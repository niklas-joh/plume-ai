<?php
/**
 * Base class shared by all AI completion providers.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Telemetry\ErrorCodes;
use Plume\Telemetry\Telemetry;

/**
 * Provides retry logic, usage logging, and media-library image saving for concrete providers.
 *
 * Concrete providers implement do_complete(), do_stream() and do_generate_image(); the
 * public complete(), stream() and generate_image() methods wrap those. complete() and
 * stream() add automatic exponential-back-off retry, and all three record an opt-in
 * `ai_request` usage event (a no-op unless Telemetry is active).
 *
 * @since 1.0.0
 * @since NEXT_VERSION generate_image() is final and wraps do_generate_image().
 */
abstract class AbstractProvider implements ProviderInterface {

	private const MAX_RETRIES   = 3;
	private const RETRY_BASE_MS = 500; // Milliseconds.

	/**
	 * Run a completion request with automatic retry on retryable errors.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 * @throws ProviderException On non-retryable error or exhausted retries.
	 */
	final public function complete( CompletionRequest $request ): CompletionResponse {
		return $this->run_completion( $request, fn() => $this->do_complete( $request ) );
	}

	/**
	 * Run a streaming completion request with automatic retry on retryable errors.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request  The completion request.
	 * @param callable          $on_chunk Callback invoked with each text delta string.
	 * @return CompletionResponse
	 * @throws ProviderException On non-retryable error or exhausted retries.
	 */
	final public function stream( CompletionRequest $request, callable $on_chunk ): CompletionResponse {
		return $this->run_completion( $request, fn() => $this->do_stream( $request, $on_chunk ) );
	}

	/**
	 * Generate an image, recording an opt-in `ai_request` usage event.
	 *
	 * There is no retry, as before. The internal `turn_id` option is removed before the
	 * provider sees the options.
	 *
	 * @since NEXT_VERSION
	 * @param string $prompt  The image prompt.
	 * @param array  $options Image generation options; `turn_id` groups the images of one request.
	 * @return int Attachment ID.
	 * @throws \Throwable Whatever do_generate_image() throws, unchanged.
	 */
	final public function generate_image( string $prompt, array $options = [] ): int {
		$turn_id = is_string( $options['turn_id'] ?? null ) ? $options['turn_id'] : '';
		unset( $options['turn_id'] );
		$started = hrtime( true );
		try {
			$attachment_id = $this->do_generate_image( $prompt, $options );
		} catch ( \Throwable $e ) {
			$this->record_image_request( null, $e, $started, $turn_id );
			throw $e;
		}
		$this->record_image_request( $attachment_id, null, $started, $turn_id );
		return $attachment_id;
	}

	/**
	 * Run a completion callback with retry, logging and the usage event.
	 *
	 * @param CompletionRequest $request  The completion request.
	 * @param callable          $callback Provider call returning a CompletionResponse.
	 * @return CompletionResponse
	 * @throws \Throwable Whatever the callback throws once retries are exhausted, unchanged.
	 */
	private function run_completion( CompletionRequest $request, callable $callback ): CompletionResponse {
		$started     = hrtime( true );
		$attempts    = 0;
		$first_error = '';
		try {
			$response = $this->with_retry( $callback, $attempts, $first_error );
		} catch ( \Throwable $e ) {
			$this->record_ai_request( $request, null, $e, $started, $attempts, $first_error );
			throw $e;
		}
		$this->maybe_log( $request, $response );
		$this->record_ai_request( $request, $response, null, $started, $attempts, $first_error );
		return $response;
	}

	/**
	 * Default implementation — most providers support tool calling.
	 * Providers that do not (e.g. Ollama) override this to return false.
	 *
	 * @return bool
	 */
	public function supports_tools(): bool {
		return true;
	}

	/**
	 * Format the system prompt for transmission to the provider API.
	 *
	 * The default returns the plain string, which is correct for OpenAI, Gemini,
	 * and Ollama (their caching is either automatic or not applicable). Providers
	 * that support explicit cache-control blocks (e.g. Claude) override this method
	 * to return a structured array when the prompt exceeds the minimum cacheable length.
	 *
	 * @since 1.10.0
	 * @param string $system Raw system prompt text.
	 * @return string|array Plain string for most providers; structured block array for Claude.
	 */
	protected function build_system_field( string $system ): string|array {
		return $system;
	}

	/**
	 * Perform the actual completion API call (no retry wrapper).
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 */
	abstract protected function do_complete( CompletionRequest $request ): CompletionResponse;

	/**
	 * Perform the actual streaming API call (no retry wrapper).
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request  The completion request.
	 * @param callable          $on_chunk Callback invoked with each text delta string.
	 * @return CompletionResponse
	 */
	abstract protected function do_stream( CompletionRequest $request, callable $on_chunk ): CompletionResponse;

	/**
	 * Perform the actual image generation call (no telemetry wrapper).
	 *
	 * @since NEXT_VERSION
	 * @param string $prompt  The image prompt.
	 * @param array  $options Image generation options.
	 * @return int Attachment ID.
	 * @throws ProviderException On generation failure or unsupported provider.
	 */
	abstract protected function do_generate_image( string $prompt, array $options ): int;

	/**
	 * Which route a request takes, for usage events: 'proxy', 'byok' or 'ollama'.
	 *
	 * @since NEXT_VERSION
	 * @return string
	 */
	protected function telemetry_route(): string {
		return 'byok';
	}

	/**
	 * Model ID used for image generation, for usage events ('' when unsupported).
	 *
	 * @since NEXT_VERSION
	 * @return string
	 */
	protected function telemetry_image_model(): string {
		return '';
	}

	/**
	 * Download an image URL into the WP media library and return its attachment ID.
	 *
	 * @since 1.0.0
	 * @param string $image_url Remote image URL to download.
	 * @param string $filename  Base filename (without extension) for the attachment.
	 * @param string $prompt    Prompt used to generate the image; stored as post meta.
	 * @return int Attachment ID.
	 * @throws ProviderException On download or sideload failure.
	 */
	protected function save_image_to_media_library( string $image_url, string $filename, string $prompt ): int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $image_url );
		if ( is_wp_error( $tmp ) ) {
			throw new ProviderException( 'Failed to download image: ' . $tmp->get_error_message(), $this->get_slug(), 0, [], null, 'media_save_failed' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$file_array = [
			'name'     => sanitize_file_name( $filename . '.png' ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, 0, $prompt );
		wp_delete_file( $tmp );

		if ( is_wp_error( $attachment_id ) ) {
			throw new ProviderException( 'Failed to save image: ' . $attachment_id->get_error_message(), $this->get_slug(), 0, [], null, 'media_save_failed' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		update_post_meta( $attachment_id, '_plume_prompt', sanitize_textarea_field( $prompt ) );
		return $attachment_id;
	}

	/**
	 * Wrap a provider call with exponential back-off retry (max 3 attempts).
	 *
	 * @since 1.0.0
	 * @since NEXT_VERSION Added the by-reference $attempts and $first_error_code parameters.
	 * @param callable $callback          Provider call to retry.
	 * @param int      $attempts          By reference: number of tries made.
	 * @param string   $first_error_code  By reference: normalised error code of the first failed
	 *                                    try; only filled while Telemetry is active.
	 * @return CompletionResponse
	 * @throws ProviderException When all retries are exhausted or the error is not retryable.
	 */
	private function with_retry( callable $callback, int &$attempts = 0, string &$first_error_code = '' ): CompletionResponse {
		$last_exception = null;
		for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			++$attempts;
			try {
				return $callback();
			} catch ( ProviderException $e ) {
				$last_exception = $e;
				if ( '' === $first_error_code && Telemetry::is_active() ) {
					$first_error_code = ErrorCodes::normalise( $e );
				}
				if ( ! $e->is_retryable() || self::MAX_RETRIES === $attempt ) {
					throw $e;
				}
				// Exponential back-off: 500ms, 1000ms, 2000ms.
				usleep( ( self::RETRY_BASE_MS * ( 2 ** $attempt ) ) * 1000 );
			}
		}
		throw $last_exception;
	}

	/**
	 * No-op for the BYOK tier, which bypasses the proxy and has no credit limit.
	 *
	 * Child providers (ClaudeProvider, OpenAIProvider, GeminiProvider) override this
	 * method and call parent only when proxy_logged is false (the BYOK path). BYOK
	 * users see "Unlimited" in the dashboard, so storing a raw token count against
	 * a null limit has no value.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest  $request  Originating request.
	 * @param CompletionResponse $response Completed response.
	 * @return void
	 */
	protected function maybe_log( CompletionRequest $request, CompletionResponse $response ): void {
		// Intentional no-op: BYOK users have no credit limit and "Unlimited" is shown in the UI.
	}

	/**
	 * Build a CompletionResponse from the proxy's normalised shape `{ content, usage, model, tool_calls? }`.
	 *
	 * Centralises the response assembly shared by all three proxy providers (Claude, OpenAI, Gemini)
	 * so the plural/singular tool-call contract cannot drift between them (see #893). Token counts are
	 * read from `$result['usage']`; the first proxy tool call (or null) is exposed via `tool_call` so
	 * is_tool_call() stays a simple null-check while the full array is preserved in `raw`.
	 * Prompt-cache counts (`cache_read_input_tokens` / `cache_creation_input_tokens`, reported by
	 * the Worker since NEXT_VERSION) map onto `cache_read_tokens` / `cache_write_tokens`, matching BYOK.
	 *
	 * @since 1.11.0
	 * @param array  $result The proxy's normalised response payload.
	 * @param string $model  The resolved model slug (Worker-reported, requested, or default).
	 * @return CompletionResponse
	 */
	protected function build_proxy_response( array $result, string $model ): CompletionResponse {
		$in_tokens  = (int) ( $result['usage']['input_tokens'] ?? 0 );
		$out_tokens = (int) ( $result['usage']['output_tokens'] ?? 0 );

		[ $first_tool_call ] = CompletionResponse::first_and_all_tool_calls_from_proxy( $result );

		return new CompletionResponse(
			content:            $result['content'] ?? '',
			model:              $model,
			prompt_tokens:      $in_tokens,
			completion_tokens:  $out_tokens,
			raw:                $result,
			tool_call:          $first_tool_call,
			credits_charged:    (int) ( $result['credits_charged'] ?? 0 ),
			cache_read_tokens:  (int) ( $result['usage']['cache_read_input_tokens'] ?? 0 ),
			cache_write_tokens: (int) ( $result['usage']['cache_creation_input_tokens'] ?? 0 ),
		);
	}

	/**
	 * Allowlisted model ID for a usage event.
	 *
	 * Only IDs from this provider's compiled list are sent, or the base ID of a dated
	 * snapshot of one (the longest matching ID wins, so `gpt-6-luna-2026-04-14` maps to
	 * `gpt-6-luna`, not a shorter listed prefix). Anything else, and every Ollama model, is `custom`,
	 * because free-text model names can identify a site or organisation.
	 *
	 * @param string $model Model ID from the response or request ('' = provider default).
	 * @return string
	 */
	private function telemetry_model( string $model ): string {
		if ( 'ollama' === $this->telemetry_route() ) {
			return 'custom';
		}
		if ( '' === $model ) {
			$model = $this->get_default_model();
		}
		$known = array_map( 'strval', array_keys( $this->get_models() ) );
		if ( in_array( $model, $known, true ) ) {
			return $model;
		}
		usort( $known, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		foreach ( $known as $id ) {
			if ( '' !== $id && str_starts_with( $model, $id . '-' ) ) {
				return $id;
			}
		}
		return 'custom';
	}

	/**
	 * Record the `ai_request` event for a completion or stream.
	 *
	 * @param CompletionRequest       $request     Originating request.
	 * @param CompletionResponse|null $response    Response on success.
	 * @param \Throwable|null         $error       Failure, when the call threw.
	 * @param int                     $started_ns  hrtime() at the start of the call.
	 * @param int                     $attempts    Number of tries.
	 * @param string                  $first_error Normalised code of the first failed try.
	 * @return void
	 */
	private function record_ai_request( CompletionRequest $request, ?CompletionResponse $response, ?\Throwable $error, int $started_ns, int $attempts, string $first_error ): void {
		if ( ! Telemetry::is_active() ) {
			return;
		}
		try {
			$route    = $this->telemetry_route();
			$feature  = (string) ( $request->metadata['feature'] ?? '' );
			$model    = null !== $response && '' !== $response->model ? $response->model : $request->model;
			$turn_id  = is_string( $request->metadata['turn_id'] ?? null ) ? $request->metadata['turn_id'] : '';
			$is_proxy = 'proxy' === $route;
			Telemetry::record(
				'ai_request',
				array_merge(
					$this->common_event_props( $route, $error, $started_ns, $turn_id ),
					[
						'feature'          => in_array( $feature, [ 'chat', 'generator', 'seo' ], true ) ? $feature : 'other',
						'model'            => $this->telemetry_model( (string) $model ),
						'first_error_code' => $first_error,
						// Proxy-route token counts are never sent: they would let these events be
						// matched to the managed service's own usage records.
						'input_tokens'     => null === $response || $is_proxy ? 0 : $response->prompt_tokens,
						'output_tokens'    => null === $response || $is_proxy ? 0 : $response->completion_tokens,
						'image_count'      => 0,
						'attempts'         => max( 1, $attempts ),
					]
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Telemetry must never break an AI request.
		}
	}

	/**
	 * Record the `ai_request` event for an image generation.
	 *
	 * @param int|null        $attachment_id Attachment ID on success.
	 * @param \Throwable|null $error         Failure, when the call threw.
	 * @param int             $started_ns    hrtime() at the start of the call.
	 * @param string          $turn_id       Request group ID from the options.
	 * @return void
	 */
	private function record_image_request( ?int $attachment_id, ?\Throwable $error, int $started_ns, string $turn_id ): void {
		if ( ! Telemetry::is_active() ) {
			return;
		}
		try {
			$route = $this->telemetry_route();
			Telemetry::record(
				'ai_request',
				array_merge(
					$this->common_event_props( $route, $error, $started_ns, $turn_id ),
					[
						'feature'          => 'images',
						'model'            => 'ollama' === $route ? 'custom' : $this->telemetry_image_model(),
						'first_error_code' => null === $error ? '' : ErrorCodes::normalise( $error ),
						'input_tokens'     => 0,
						'output_tokens'    => 0,
						'image_count'      => null === $attachment_id ? 0 : 1,
						'attempts'         => 1,
					]
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Telemetry must never break image generation.
		}
	}

	/**
	 * Event properties shared by completions and images.
	 *
	 * @param string          $route      Telemetry route.
	 * @param \Throwable|null $error      Failure, if any.
	 * @param int             $started_ns hrtime() at the start of the call.
	 * @param string          $turn_id    Request group ID ('' = new one).
	 * @return array<string, string|int>
	 */
	private function common_event_props( string $route, ?\Throwable $error, int $started_ns, string $turn_id ): array {
		return [
			'provider'    => $this->get_slug(),
			'route'       => $route,
			'outcome'     => null === $error ? 'ok' : 'error',
			'error_code'  => null === $error ? '' : ErrorCodes::normalise( $error ),
			'http_status' => null === $error ? 200 : ( $error instanceof ProviderException ? $error->get_http_status() : 0 ),
			'latency_ms'  => (int) ( ( hrtime( true ) - $started_ns ) / 1_000_000 ),
			'turn_id'     => '' !== $turn_id ? $turn_id : Telemetry::new_turn_id(),
		];
	}
}

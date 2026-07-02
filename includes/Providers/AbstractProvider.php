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

/**
 * Provides retry logic, usage logging, and media-library image saving for concrete providers.
 *
 * Concrete providers implement do_complete() and do_stream(); the public complete() and
 * stream() methods add automatic exponential-back-off retry around those.
 *
 * @since 1.0.0
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
		$response = $this->with_retry( fn() => $this->do_complete( $request ) );
		$this->maybe_log( $request, $response );
		return $response;
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
		$response = $this->with_retry( fn() => $this->do_stream( $request, $on_chunk ) );
		$this->maybe_log( $request, $response );
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
			throw new ProviderException( 'Failed to download image: ' . $tmp->get_error_message(), $this->get_slug() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$file_array = [
			'name'     => sanitize_file_name( $filename . '.png' ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, 0, $prompt );
		wp_delete_file( $tmp );

		if ( is_wp_error( $attachment_id ) ) {
			throw new ProviderException( 'Failed to save image: ' . $attachment_id->get_error_message(), $this->get_slug() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		update_post_meta( $attachment_id, '_plume_prompt', sanitize_textarea_field( $prompt ) );
		return $attachment_id;
	}

	/**
	 * Wrap a provider call with exponential back-off retry (max 3 attempts).
	 *
	 * @since 1.0.0
	 * @param callable $callback Provider call to retry.
	 * @return CompletionResponse
	 * @throws ProviderException When all retries are exhausted or the error is not retryable.
	 */
	private function with_retry( callable $callback ): CompletionResponse {
		$last_exception = null;
		for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			try {
				return $callback();
			} catch ( ProviderException $e ) {
				$last_exception = $e;
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
	 *
	 * @since NEXT_VERSION
	 * @param array  $result The proxy's normalised response payload.
	 * @param string $model  The resolved model slug (Worker-reported, requested, or default).
	 * @param float  $cost   The USD cost computed by the concrete provider's pricing.
	 * @return CompletionResponse
	 */
	protected function build_proxy_response( array $result, string $model, float $cost ): CompletionResponse {
		$in_tokens  = (int) ( $result['usage']['input_tokens'] ?? 0 );
		$out_tokens = (int) ( $result['usage']['output_tokens'] ?? 0 );

		[ $first_tool_call ] = CompletionResponse::first_and_all_tool_calls_from_proxy( $result );

		return new CompletionResponse(
			content:           $result['content'] ?? '',
			model:             $model,
			prompt_tokens:     $in_tokens,
			completion_tokens: $out_tokens,
			cost_usd:          $cost,
			raw:               $result,
			tool_call:         $first_tool_call,
			credits_charged:   (int) ( $result['credits_charged'] ?? 0 ),
		);
	}
}

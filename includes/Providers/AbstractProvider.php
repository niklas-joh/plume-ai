<?php
/**
 * Base class shared by all AI completion providers.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Tiers\UsageTracker;

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

		update_post_meta( $attachment_id, '_stilus_prompt', sanitize_textarea_field( $prompt ) );
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
	 * Log token usage for the current user if the response contains a token count.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest  $request  The originating request (unused here, available to subclasses).
	 * @param CompletionResponse $response The completed response.
	 * @return void
	 */
	protected function maybe_log( CompletionRequest $request, CompletionResponse $response ): void {
		UsageTracker::log_usage( $response->total_tokens );
	}
}

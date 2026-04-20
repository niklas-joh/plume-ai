<?php
// includes/Providers/AbstractProvider.php
declare( strict_types=1 );
namespace WP_AI_Mind\Providers;

use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

abstract class AbstractProvider implements ProviderInterface {

	private const MAX_RETRIES   = 3;
	private const RETRY_BASE_MS = 500; // milliseconds

	// ── Public API (implements interface) ─────────────────────────────────────

	final public function complete( CompletionRequest $request ): CompletionResponse {
		$response = $this->with_retry( fn() => $this->do_complete( $request ) );
		$this->maybe_log( $request, $response );
		return $response;
	}

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

	// ── Abstract — each provider implements these ─────────────────────────────

	abstract protected function do_complete( CompletionRequest $request ): CompletionResponse;
	abstract protected function do_stream( CompletionRequest $request, callable $on_chunk ): CompletionResponse;

	// ── Shared: image save to media library ──────────────────────────────────

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

		update_post_meta( $attachment_id, '_wp_ai_mind_prompt', sanitize_textarea_field( $prompt ) );
		return $attachment_id;
	}

	// ── Retry logic ───────────────────────────────────────────────────────────

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
		throw $last_exception; // @phpstan-ignore-line
	}

	// ── Usage logging ─────────────────────────────────────────────────────────

	protected function maybe_log( CompletionRequest $request, CompletionResponse $response ): void {
		NJ_Usage_Tracker::log_usage( $response->total_tokens );
	}
}

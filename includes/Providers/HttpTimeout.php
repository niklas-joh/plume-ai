<?php
/**
 * Helpers for per-request HTTP timeouts and timeout error detection.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves per-request HTTP timeouts and recognises transport timeouts.
 *
 * Shared by the providers and the managed proxy client so a slow completion is
 * reported identically whichever route it took.
 *
 * @since NEXT_VERSION
 */
final class HttpTimeout {

	/**
	 * Error code attached to a timed-out request (WP_Error code / ProviderException code).
	 *
	 * @since NEXT_VERSION
	 */
	public const ERROR_CODE = 'request_timeout';

	/**
	 * Pick the timeout for a request.
	 *
	 * @since NEXT_VERSION
	 * @param int $requested Per-request timeout in seconds; 0 or less means "not set".
	 * @param int $fallback  Timeout in seconds to fall back to.
	 * @return int Timeout in seconds.
	 */
	public static function resolve( int $requested, int $fallback ): int {
		return $requested > 0 ? $requested : $fallback;
	}

	/**
	 * Whether a WP_Error from the HTTP API was caused by the request timing out.
	 *
	 * Other transport failures (DNS, TLS, refused connections) are deliberately not matched.
	 *
	 * @since NEXT_VERSION
	 * @param \WP_Error $error Error returned by wp_remote_post().
	 * @return bool
	 */
	public static function is_timeout( \WP_Error $error ): bool {
		if ( 'http_request_failed' !== $error->get_error_code() ) {
			return false;
		}
		$message = strtolower( $error->get_error_message() );
		return str_contains( $message, 'curl error 28' ) || str_contains( $message, 'timed out' );
	}

	/**
	 * User-facing message for a timed-out request.
	 *
	 * @since NEXT_VERSION
	 * @return string
	 */
	public static function message(): string {
		return __( 'The draft took too long to finish. Try a shorter length, or try again in a moment.', 'plume' );
	}
}

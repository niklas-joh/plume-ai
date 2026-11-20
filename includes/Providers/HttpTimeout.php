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
 * @since 2.2.0
 */
final class HttpTimeout {

	/**
	 * Error code attached to a timed-out request (WP_Error code / ProviderException code).
	 *
	 * @since 2.2.0
	 */
	public const ERROR_CODE = 'request_timeout';

	/**
	 * Pick the timeout for a request.
	 *
	 * @since 2.2.0
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
	 * @since 2.2.0
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
	 * Neutral wording so the single shared message reads correctly for every
	 * feature (draft, chat, SEO) that can time out on the managed route.
	 *
	 * @since 2.2.0
	 * @return string
	 */
	public static function message(): string {
		return __( 'The request took too long to finish. Try again in a moment.', 'plume' );
	}

	/**
	 * Build a ProviderException for a timed-out request.
	 *
	 * Centralises the timeout-to-exception mapping repeated verbatim across the
	 * provider post() methods so the message and error code live in one place.
	 *
	 * @since 2.2.0
	 * @param string $slug Provider slug (e.g. 'claude', 'openai').
	 * @return ProviderException
	 */
	public static function to_exception( string $slug ): ProviderException {
		return new ProviderException( self::message(), $slug, 0, [], null, self::ERROR_CODE );
	}
}

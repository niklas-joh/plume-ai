<?php
/**
 * Verifies Freemius webhook signatures for licence lifecycle events.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies HMAC-SHA256 signatures on incoming webhook payloads.
 *
 * Webhook verification is currently handled by the Cloudflare Worker (`signature.ts`).
 * This class is retained as infrastructure for a future PHP-side webhook endpoint
 * and has no active call-site in the plugin.
 *
 * @internal Intentionally unused until a PHP webhook route is introduced.
 * @since 1.2.0
 */
class NJ_Webhook_Verifier {

	/**
	 * Verifies that an HMAC-SHA256 signature matches the raw request body.
	 *
	 * Returns false immediately when $signature is absent or $secret is empty,
	 * avoiding a timing-safe comparison against a meaningless value.
	 *
	 * @since 1.2.0
	 * @param string      $body      Raw request body.
	 * @param string|null $signature Signature from the X-Signature header.
	 * @param string      $secret    Shared webhook secret.
	 * @return bool True when the signature is valid.
	 */
	public static function verify( string $body, ?string $signature, string $secret ): bool {
		if ( ! $signature || empty( $secret ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $expected, $signature );
	}
}

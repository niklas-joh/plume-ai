<?php
/**
 * REST endpoint that receives signed tier-update pushes from the Cloudflare Worker.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Payments;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use Stilus\Tiers\TierConfig;
use Stilus\Tiers\TierManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for POST /stilus/v1/tier-update.
 *
 * Called by the Cloudflare Worker after it processes a LemonSqueezy webhook
 * (subscription_created / cancelled / expired / paused). The Worker signs each
 * push with the per-site HMAC secret issued at registration so WordPress can
 * trust the payload without exposing any state to the public network.
 *
 * Permission callback is `__return_true` — authentication is the HMAC
 * verification inside `handle()`. WordPress's permission layer cannot model
 * shared-secret-based authority natively, and computing the HMAC here keeps
 * the receiver self-contained.
 *
 * @since 1.9.0
 */
class TierUpdateWebhookController {

	private const NAMESPACE = 'stilus/v1';
	private const ROUTE     = '/tier-update';

	/**
	 * Option key storing the per-site HMAC secret issued by the Worker.
	 *
	 * @since 1.9.0
	 */
	public const OPTION_SECRET = 'stilus_tier_sync_secret';

	/**
	 * Hard cap on the inbound body size.
	 *
	 * The payload is `{"tier":"<slug>"}` — well under 64 bytes. 1 KiB gives ample
	 * head-room for future fields while denying obvious abuse.
	 *
	 * @since 1.9.0
	 */
	private const MAX_BODY_BYTES = 1024;

	/**
	 * Maximum allowed skew between the signed timestamp and server time, in seconds.
	 *
	 * Positive values: signed timestamp is older than now (network/queue latency).
	 * Negative values: signed timestamp is in the future — treated as suspicious
	 * and clamped to a small tolerance (60s) for clock-skew between hosts.
	 *
	 * @since 1.9.0
	 */
	private const MAX_PAST_SKEW = 300;

	/**
	 * Maximum allowed future skew between the signed timestamp and server time, in seconds.
	 *
	 * @since 1.9.0
	 */
	private const MAX_FUTURE_SKEW = 60;

	/**
	 * Lifetime of the replay-protection transient (seconds).
	 *
	 * Set comfortably above MAX_PAST_SKEW so that a signature replayed within the
	 * acceptance window is always remembered for the entire window's duration.
	 *
	 * @since 1.9.0
	 */
	private const REPLAY_TTL = 360;

	/**
	 * Register the REST route on rest_api_init.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function register(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle a signed tier-update POST.
	 *
	 * Verification order is deliberate: Content-Type → size cap → headers present →
	 * timestamp window → secret configured → HMAC equal → replay check → JSON →
	 * tier validation → write. HMAC is verified BEFORE the replay check so an
	 * attacker cannot pollute the transient store with arbitrary signatures.
	 *
	 * @since 1.9.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response JSON response with appropriate HTTP status.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$content_type = (string) $request->get_header( 'content_type' );
		// Worker always sends `application/json` (possibly with `; charset=utf-8`).
		if ( '' === $content_type || false === stripos( $content_type, 'application/json' ) ) {
			return new WP_REST_Response( [ 'error' => 'unsupported_media_type' ], 415 );
		}

		// Read the raw body — HMAC is computed over the exact bytes the Worker signed.
		// get_json_params() would re-encode and might silently re-normalise whitespace.
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new WP_REST_Response( [ 'error' => 'payload_too_large' ], 413 );
		}

		$signature = (string) $request->get_header( 'x_stilus_signature' );
		$timestamp = (int) $request->get_header( 'x_stilus_timestamp' );

		if ( '' === $signature || 0 === $timestamp ) {
			return new WP_REST_Response( [ 'error' => 'missing_headers' ], 401 );
		}

		$skew = time() - $timestamp;
		if ( $skew > self::MAX_PAST_SKEW || $skew < -self::MAX_FUTURE_SKEW ) {
			return new WP_REST_Response( [ 'error' => 'expired' ], 401 );
		}

		$secret = (string) get_option( self::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			return new WP_REST_Response( [ 'error' => 'not_configured' ], 401 );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( [ 'error' => 'invalid_signature' ], 401 );
		}

		// Replay protection: a verified signature is single-use within the window.
		// md5 here is a cache-key, not a security primitive — the signature itself
		// is the security primitive and was already verified above.
		$seen_key = 'stilus_tier_sig_' . md5( $signature );
		if ( get_transient( $seen_key ) ) {
			return new WP_REST_Response( [ 'error' => 'replay' ], 401 );
		}
		set_transient( $seen_key, 1, self::REPLAY_TTL );

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['tier'] ) || ! is_string( $decoded['tier'] ) ) {
			return new WP_REST_Response( [ 'error' => 'bad_request' ], 400 );
		}

		$tier = $decoded['tier'];
		if ( ! in_array( $tier, TierConfig::get_valid_tiers(), true ) ) {
			return new WP_REST_Response( [ 'error' => 'bad_request' ], 400 );
		}

		$ok = TierManager::set_site_tier( $tier );
		if ( ! $ok ) {
			return new WP_REST_Response( [ 'error' => 'internal_error' ], 500 );
		}

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}
}

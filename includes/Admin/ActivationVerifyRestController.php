<?php
/**
 * REST endpoint called by the proxy Worker to verify a site is live and running
 * Stilus.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the GET /wp-ai-mind/v1/activation-verify callback invoked by the
 * Cloudflare Worker during site registration.
 *
 * The Worker generates a challenge token, stores it in KV, and sends it back
 * to the plugin via /register. The plugin stores the challenge as a transient
 * (store_challenge). The Worker then calls this endpoint to confirm the token
 * matches — proving a live WordPress site with the plugin active is at the
 * registered URL.
 *
 * @since 1.3.0
 */
class ActivationVerifyRestController {

	private const NAMESPACE     = 'wp-ai-mind/v1';
	private const ROUTE         = '/activation-verify';
	private const TRANSIENT     = 'wp_ai_mind_challenge_';
	private const CHALLENGE_TTL = 300; // Seconds — must match worker CHALLENGE_TTL.

	/**
	 * Register the REST route. No authentication required — the Worker calls this.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ static::class, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'challenge' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( string $value ): bool {
							return (bool) preg_match( '/^[0-9a-f]{64}$/', $value );
						},
					],
				],
			]
		);
	}

	/**
	 * Handle the activation-verify request.
	 *
	 * Returns 200 on the first successful match and immediately deletes the
	 * transient (consume-once). Subsequent calls with the same token receive
	 * 403, eliminating the stable-oracle property that would otherwise allow
	 * any observer of the challenge value to block legitimate Worker registration.
	 *
	 * @since 1.3.0
	 * @since 1.8.1 Transient is deleted on first successful match (consume-once, fixes #457).
	 * @param WP_REST_Request $request The incoming REST request.
	 * @return WP_REST_Response 200 with `verified: true` on first match; 403 on mismatch or replay.
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$challenge = $request->get_param( 'challenge' );

		// Consume-once: delete before returning so a replayed request cannot
		// use this endpoint as an existence oracle for the stored transient.
		// Note: the get_transient → delete_transient sequence is non-atomic, so two
		// concurrent requests carrying the same token could theoretically both pass
		// (TOCTOU). In practice the window is negligible: tokens are high-entropy
		// (256-bit hex), single-use, and short-lived (300 s), making a collision
		// requiring an attacker who already holds the token vanishingly unlikely.
		// A native compare-and-delete primitive is not available in WP transients;
		// a SELECT … FOR UPDATE lock would require a custom table. Accepted risk.
		if ( get_transient( self::TRANSIENT . $challenge ) ) {
			delete_transient( self::TRANSIENT . $challenge );
			return new WP_REST_Response( [ 'verified' => true ], 200 );
		}

		return new WP_REST_Response( [ 'error' => 'Challenge not found' ], 403 );
	}

	/**
	 * Store a challenge token as a transient so the Worker callback can verify it.
	 *
	 * Called by NJ_Site_Registration immediately after fetching the challenge
	 * from the Worker, before sending it back in the /register request body.
	 *
	 * @since 1.3.0
	 * @param string $challenge 64-character hex challenge token.
	 * @return void
	 */
	public static function store_challenge( string $challenge ): void {
		set_transient( self::TRANSIENT . $challenge, 1, self::CHALLENGE_TTL );
	}
}

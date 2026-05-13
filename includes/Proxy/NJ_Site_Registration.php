<?php
/**
 * Handles site registration with the Cloudflare Worker AI proxy.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Admin\ActivationVerifyRestController;
use WP_AI_Mind\Payments\TierUpdateWebhookController;
use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles auto-registration of this site with the Cloudflare Worker proxy.
 *
 * On first activation the site sends its home URL to the /register endpoint
 * and stores the returned token in wp_options. Subsequent requests use
 * this token for Bearer authentication.
 *
 * @since 1.2.0
 */
class NJ_Site_Registration {

	public const OPTION_TOKEN = 'wp_ai_mind_site_token';

	/**
	 * Option key for the per-site HMAC secret used to authenticate Worker → WP
	 * tier-update pushes. Canonical definition lives in TierUpdateWebhookController;
	 * referenced here for symmetry with OPTION_TOKEN.
	 *
	 * @since 1.9.0
	 */
	public const OPTION_SECRET = TierUpdateWebhookController::OPTION_SECRET;

	private const TRANSIENT_BACKOFF = 'wp_ai_mind_reg_backoff';

	/**
	 * Return the checkout URL for the Pro Managed Monthly plan.
	 *
	 * @since 1.2.0
	 * @return string Fully-formed checkout URL.
	 */
	public static function checkout_url_pro_managed_monthly(): string {
		return self::checkout_url( self::plan_id( 'monthly' ) );
	}

	/**
	 * Return the checkout URL for the Pro Managed Annual plan.
	 *
	 * @since 1.2.0
	 * @return string Fully-formed checkout URL.
	 */
	public static function checkout_url_pro_managed_annual(): string {
		return self::checkout_url( self::plan_id( 'annual' ) );
	}

	/**
	 * Return the checkout URL for the Pro BYOK One-time plan.
	 *
	 * @since 1.2.0
	 * @return string Fully-formed checkout URL.
	 */
	public static function checkout_url_pro_byok_onetime(): string {
		return self::checkout_url( self::plan_id( 'byok' ) );
	}

	/**
	 * Return the stored site token, or an empty string if not yet registered.
	 *
	 * @since 1.2.0
	 * @return string Stored site token, or empty string when not yet registered.
	 */
	public static function get_site_token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	/**
	 * Return true when a site token is present.
	 *
	 * @since 1.2.0
	 * @return bool True when a site token is stored in wp_options.
	 */
	public static function is_registered(): bool {
		return '' !== self::get_site_token();
	}

	/**
	 * Register with the proxy Worker if not already registered.
	 *
	 * Idempotent — skips silently if a token is already stored.
	 * Hooked to `init` in Plugin.php.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( self::is_registered() ) {
			return;
		}

		if ( get_transient( self::TRANSIENT_BACKOFF ) ) {
			return;
		}

		$result = self::register();
		if ( is_wp_error( $result ) ) {
			set_transient( self::TRANSIENT_BACKOFF, 1, 5 * MINUTE_IN_SECONDS );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Stilus] Site registration failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * Send a registration request to the proxy Worker.
	 *
	 * Performs a two-step challenge handshake: fetches a single-use challenge
	 * token from the Worker, stores it as a transient so the Worker callback
	 * can verify it, then sends the challenge alongside the site URL.
	 *
	 * @since 1.2.0
	 * @return string|WP_Error The stored site token on success, or a WP_Error on failure.
	 */
	public static function register(): string|WP_Error {
		$proxy_url = NJ_Tier_Config::get_proxy_url();

		// Step 1 — fetch a single-use challenge from the Worker.
		$challenge_response = wp_remote_get(
			$proxy_url . '/activation-challenge',
			[ 'timeout' => 10 ]
		);

		if ( is_wp_error( $challenge_response ) ) {
			return $challenge_response;
		}

		$challenge_code = (int) wp_remote_retrieve_response_code( $challenge_response );
		$challenge_body = json_decode( wp_remote_retrieve_body( $challenge_response ), true ) ?? [];

		if ( 200 !== $challenge_code || empty( $challenge_body['challenge'] ) ) {
			return new WP_Error( 'challenge_failed', "Could not obtain activation challenge (HTTP {$challenge_code})" );
		}

		$challenge = sanitize_text_field( $challenge_body['challenge'] );

		// Step 2 — store the challenge locally so the Worker callback succeeds.
		ActivationVerifyRestController::store_challenge( $challenge );

		// Step 3 — register with the Worker, sending the challenge token.
		$response = wp_remote_post(
			$proxy_url . '/register',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'site_url'        => home_url(),
						'challenge_token' => $challenge,
					]
				),
				// Increased timeout: Worker makes a callback to this site before responding.
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( ( 200 !== $code && 201 !== $code ) || empty( $body['token'] ) ) {
			return new WP_Error( 'registration_failed', "Proxy registration returned HTTP {$code}" );
		}

		$token = sanitize_text_field( $body['token'] );
		update_option( self::OPTION_TOKEN, $token );

		self::store_worker_tier_state( $body );

		return $token;
	}

	/**
	 * Re-request a fresh tier-sync secret from the Worker.
	 *
	 * Used by the backfill admin notice for sites registered before the
	 * tier-sync handshake existed, and as a manual rotation path on demand.
	 * Bearer-authenticated using the existing site token.
	 *
	 * @since 1.9.0
	 * @return string|WP_Error The new secret on success, or a WP_Error on failure.
	 */
	public static function rotate_secret(): string|WP_Error {
		$token = self::get_site_token();
		if ( '' === $token ) {
			return new WP_Error( 'not_registered', 'This site is not registered with the proxy.' );
		}

		$response = wp_remote_post(
			NJ_Tier_Config::get_proxy_url() . '/rotate-secret',
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( new \stdClass() ),
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( 200 !== $code || empty( $body['tier_sync_secret'] ) ) {
			return new WP_Error( 'rotate_failed', "Proxy /rotate-secret returned HTTP {$code}" );
		}

		$secret = sanitize_text_field( $body['tier_sync_secret'] );
		update_option( self::OPTION_SECRET, $secret, false );

		if ( isset( $body['tier'] ) && is_string( $body['tier'] ) ) {
			NJ_Tier_Manager::set_site_tier( sanitize_text_field( $body['tier'] ) );
		}

		return $secret;
	}

	/**
	 * Persist Worker-supplied tier-sync state (secret + initial tier).
	 *
	 * Extracted so /register and /rotate-secret share identical handling.
	 * Silently skips fields the Worker omits (legacy compat: pre-1.9 Workers
	 * return only `{ token, tier }`; older still return just `{ token }`).
	 *
	 * @since 1.9.0
	 * @param array<string, mixed> $body Decoded Worker response body.
	 * @return void
	 */
	private static function store_worker_tier_state( array $body ): void {
		if ( ! empty( $body['tier_sync_secret'] ) && is_string( $body['tier_sync_secret'] ) ) {
			$secret = sanitize_text_field( $body['tier_sync_secret'] );
			// autoload=false: only consulted by the tier-update webhook receiver.
			update_option( self::OPTION_SECRET, $secret, false );
		}
		if ( isset( $body['tier'] ) && is_string( $body['tier'] ) ) {
			NJ_Tier_Manager::set_site_tier( sanitize_text_field( $body['tier'] ) );
		}
	}

	/**
	 * Build a LemonSqueezy checkout URL for the given variant ID.
	 *
	 * Embeds the site token as a custom checkout field so the Worker can
	 * associate the purchase with this installation automatically.
	 *
	 * @since 1.2.0
	 * @param string $variant_id The LemonSqueezy product variant ID.
	 * @return string The fully-formed checkout URL.
	 */
	public static function checkout_url( string $variant_id ): string {
		$token = self::get_site_token();
		$url   = 'https://wp-ai-mind.lemonsqueezy.com/checkout/buy/' . rawurlencode( $variant_id );
		if ( $token ) {
			$url .= '?checkout[custom][site_token]=' . rawurlencode( $token );
		}
		return $url;
	}

	/**
	 * Return the LemonSqueezy variant ID for a plan, with wp-config.php override support.
	 *
	 * Defaults match the live store. Override via WP_AI_MIND_LS_MONTHLY_ID,
	 * WP_AI_MIND_LS_ANNUAL_ID, or WP_AI_MIND_LS_BYOK_ID in wp-config.php to
	 * change variant IDs without a plugin release (e.g. after a store migration).
	 *
	 * @since 1.2.0
	 * @param string $plan One of 'monthly', 'annual', 'byok'.
	 * @return string LemonSqueezy variant ID.
	 * @throws \InvalidArgumentException When an unrecognised plan key is passed.
	 */
	private static function plan_id( string $plan ): string {
		$map = [
			'monthly' => defined( 'WP_AI_MIND_LS_MONTHLY_ID' ) ? WP_AI_MIND_LS_MONTHLY_ID : '1550505',
			'annual'  => defined( 'WP_AI_MIND_LS_ANNUAL_ID' ) ? WP_AI_MIND_LS_ANNUAL_ID : '1550477',
			'byok'    => defined( 'WP_AI_MIND_LS_BYOK_ID' ) ? WP_AI_MIND_LS_BYOK_ID : '1550517',
		];
		if ( ! array_key_exists( $plan, $map ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal developer error, not user-facing output.
			throw new \InvalidArgumentException( "Unknown plan key: '{$plan}'" );
		}
		return $map[ $plan ];
	}
}

<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles auto-registration of this site with the Cloudflare Worker proxy.
 *
 * On first activation the site sends its home URL to the /register endpoint
 * and stores the returned token in wp_options. Subsequent requests use
 * this token for Bearer authentication.
 */
class NJ_Site_Registration {

	private const OPTION_TOKEN = 'wp_ai_mind_site_token';

	public const PLAN_PRO_MANAGED_MONTHLY = '1550505';
	public const PLAN_PRO_MANAGED_ANNUAL  = '1550477';
	public const PLAN_PRO_BYOK_ONETIME    = '1550517';

	/**
	 * Return the stored site token, or an empty string if not yet registered.
	 */
	public static function get_site_token(): string {
		return (string) get_option( self::OPTION_TOKEN, '' );
	}

	/**
	 * Return true when a site token is present.
	 */
	public static function is_registered(): bool {
		return '' !== self::get_site_token();
	}

	/**
	 * Register with the proxy Worker if not already registered.
	 *
	 * Idempotent — skips silently if a token is already stored.
	 * Hooked to `init` in Plugin.php.
	 */
	private const TRANSIENT_BACKOFF = 'wp_ai_mind_reg_backoff';

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
			error_log( '[WP AI Mind] Site registration failed: ' . $result->get_error_message() );
		}
	}

	/**
	 * Send a registration request to the proxy Worker.
	 *
	 * @return string|WP_Error The stored site token on success, or a WP_Error on failure.
	 */
	public static function register(): string|WP_Error {
		$response = wp_remote_post(
			NJ_Tier_Config::PROXY_URL . '/register',
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'site_url' => home_url() ] ),
				'timeout' => 15,
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

		update_option( self::OPTION_TOKEN, sanitize_text_field( $body['token'] ) );
		return $body['token'];
	}

	/**
	 * Build a LemonSqueezy checkout URL for the given variant ID.
	 *
	 * Embeds the site token as a custom checkout field so the Worker can
	 * associate the purchase with this installation automatically.
	 *
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
}

<?php
/**
 * Handles site registration with the Cloudflare Worker AI proxy.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Proxy;

use WP_Error;
use Plume\Admin\ActivationVerifyRestController;
use Plume\Payments\TierUpdateWebhookController;
use Plume\Tiers\TierConfig;
use Plume\Tiers\TierManager;

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
class SiteRegistration {

	public const OPTION_TOKEN = 'plume_site_token';

	/**
	 * Option key for the per-site HMAC secret used to authenticate Worker → WP
	 * tier-update pushes. Canonical definition lives in TierUpdateWebhookController;
	 * referenced here for symmetry with OPTION_TOKEN.
	 *
	 * @since 1.9.0
	 */
	public const OPTION_SECRET = TierUpdateWebhookController::OPTION_SECRET;

	/**
	 * Option key storing diagnostic details of a permanent (non-retryable)
	 * registration failure: `{ reason: string, message: string }`.
	 *
	 * Cleared automatically on the next successful (or merely transient-failing)
	 * registration attempt — see record_registration_outcome().
	 *
	 * @since NEXT_VERSION
	 */
	public const OPTION_PERMANENT_FAILURE = 'plume_reg_permanent_failure';

	/**
	 * Backoff applied after a permanent verification failure — much longer than
	 * TRANSIENT_BACKOFF because retrying a structurally broken install (e.g.
	 * localhost, invalid TLS, a login wall) cannot succeed until the admin
	 * changes something, so there is no value in re-attempting every 5 minutes.
	 *
	 * @since NEXT_VERSION
	 */
	public const PERMANENT_BACKOFF = 6 * HOUR_IN_SECONDS;

	/**
	 * Worker-reported verification-failure reasons treated as permanent —
	 * retrying will never succeed until the admin fixes the underlying
	 * condition. Mirrors plume-proxy/src/types.ts::VerificationFailureReason
	 * minus 'timeout' and 'http_error', which stay transient.
	 *
	 * @since NEXT_VERSION
	 * @var string[]
	 */
	public const PERMANENT_VERIFICATION_REASONS = [
		'network_error',
		'tls_error',
		'http_unauthorized',
		'http_forbidden',
	];

	private const TRANSIENT_BACKOFF = 'plume_reg_backoff';

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
	 *
	 * Not hooked eagerly anywhere: ProxyClient and ChatRestController schedule
	 * this on `shutdown` when a user-initiated proxy request finds no site
	 * token, so the site URL is only transmitted after the admin has actually
	 * used an AI feature (WP.org Guideline 7 — no phoning home without consent).
	 *
	 * @since 1.2.0
	 * @since 1.12.0 No longer hooked to admin_init; registration is lazy,
	 *                      scheduled on shutdown of the first proxy-backed request.
	 * @since NEXT_VERSION Delegates outcome handling (backoff + permanent-failure
	 *                      bookkeeping) to record_registration_outcome().
	 * @return void
	 */
	public static function maybe_register(): void {
		if ( self::is_registered() ) {
			return;
		}

		if ( get_transient( self::TRANSIENT_BACKOFF ) ) {
			return;
		}

		self::record_registration_outcome( self::register() );
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
		$proxy_url = TierConfig::get_proxy_url();

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
			$reason = $body['reason'] ?? '';
			if ( 403 === $code && in_array( $reason, self::PERMANENT_VERIFICATION_REASONS, true ) ) {
				return new WP_Error(
					'site_unreachable',
					self::permanent_failure_message( $reason ),
					[
						'permanent' => true,
						'reason'    => $reason,
						'status'    => $body['status'] ?? null,
					]
				);
			}
			return new WP_Error( 'registration_failed', "Proxy registration returned HTTP {$code}" );
		}

		$token = sanitize_text_field( $body['token'] );
		update_option( self::OPTION_TOKEN, $token );

		self::store_worker_tier_state( $body );

		return $token;
	}

	/**
	 * Return the plugin's own fixed, translatable copy for a permanent
	 * verification-failure reason.
	 *
	 * Deliberately never echoes the Worker's raw `reason`/error text — the
	 * displayed message is always this plugin's own canned copy, so wording
	 * stays under the plugin's control regardless of what the Worker sends.
	 *
	 * @since NEXT_VERSION
	 * @param string $reason One of PERMANENT_VERIFICATION_REASONS, or an unrecognised value.
	 * @return string Translatable, user-facing diagnostic message.
	 */
	public static function permanent_failure_message( string $reason ): string {
		switch ( $reason ) {
			case 'network_error':
				return __( 'Plume AI - Write and Design could not reach this site from the internet. This usually means the site is only available locally (e.g. localhost or a development environment) and has no public address.', 'plume' );
			case 'tls_error':
				return __( 'Plume AI - Write and Design could not verify this site\'s security certificate. Please check that the site has a valid, trusted SSL/TLS certificate.', 'plume' );
			case 'http_unauthorized':
				return __( 'Plume AI - Write and Design could not reach this site because it is protected by a login wall (HTTP Basic Auth). Please allow public access to the verification endpoint, or remove the login wall.', 'plume' );
			case 'http_forbidden':
				return __( 'Plume AI - Write and Design could not reach this site because it appears to be blocked by a firewall, VPN, IP allow-list, or security plugin. Please allow outside access to the verification endpoint.', 'plume' );
			default:
				return __( 'Plume AI - Write and Design could not verify that this site is publicly reachable, and this issue is unlikely to resolve on its own.', 'plume' );
		}
	}

	/**
	 * Return the stored permanent-failure diagnostic, if one is on record.
	 *
	 * @since NEXT_VERSION
	 * @return array{reason: string, message: string}|null The stored diagnostic, or null when none is recorded.
	 */
	public static function get_permanent_failure(): ?array {
		$stored = get_option( self::OPTION_PERMANENT_FAILURE, null );
		if ( ! is_array( $stored ) || empty( $stored['reason'] ) || empty( $stored['message'] ) ) {
			return null;
		}
		return $stored;
	}

	/**
	 * Clear any stored permanent-failure diagnostic.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function clear_permanent_failure(): void {
		delete_option( self::OPTION_PERMANENT_FAILURE );
	}

	/**
	 * Single source of truth for the "site not yet usable" error surfaced to
	 * callers (ProxyClient::chat(), ChatRestController::send_message()) when
	 * no site token is available.
	 *
	 * Both call sites use this so their error codes/messages cannot drift
	 * apart: a permanent verification failure on record always wins over the
	 * generic "still connecting" message.
	 *
	 * @since NEXT_VERSION
	 * @return WP_Error `site_unreachable` when a permanent failure is stored; otherwise the
	 *                   existing generic `not_registered` "connecting…" error.
	 */
	public static function get_unavailable_error(): WP_Error {
		$permanent_failure = self::get_permanent_failure();
		if ( null !== $permanent_failure ) {
			return new WP_Error(
				'site_unreachable',
				$permanent_failure['message'],
				[
					'permanent' => true,
					'reason'    => $permanent_failure['reason'],
				]
			);
		}

		// This code is one of REGISTRATION_RETRY_CODES in src/admin/components/Chat/ChatApp.jsx,
		// which silently retries the request instead of surfacing this to the user immediately.
		return new WP_Error( 'not_registered', __( 'Connecting this site to Plume AI - Write and Design. Please try sending your message again in a moment.', 'plume' ) );
	}

	/**
	 * Record the outcome of a registration attempt and set the appropriate backoff.
	 *
	 * On success or a transient failure: clears any stored permanent-failure
	 * diagnostic and sets the existing 5-minute backoff transient so
	 * maybe_register() doesn't hammer the Worker on every request. On a
	 * permanent failure: stores the diagnostic (so get_unavailable_error() and
	 * the admin notice can surface it) and sets a much longer 6-hour backoff —
	 * there's no value in retrying a structurally broken install every 5 minutes.
	 *
	 * @since NEXT_VERSION
	 * @param string|WP_Error $result The return value of register().
	 * @return void
	 */
	public static function record_registration_outcome( string|WP_Error $result ): void {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && ! empty( $data['permanent'] ) ) {
				update_option(
					self::OPTION_PERMANENT_FAILURE,
					[
						'reason'  => $data['reason'] ?? '',
						'message' => $result->get_error_message(),
					],
					false
				);
				set_transient( self::TRANSIENT_BACKOFF, 1, self::PERMANENT_BACKOFF );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Plume] Site registration permanently failed: ' . $result->get_error_message() );
				return;
			}

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Plume] Site registration failed: ' . $result->get_error_message() );
		}

		// Success, or a transient (non-permanent) failure: clear any stale
		// permanent-failure diagnostic and fall back to the short backoff.
		self::clear_permanent_failure();
		set_transient( self::TRANSIENT_BACKOFF, 1, 5 * MINUTE_IN_SECONDS );
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
			return new WP_Error( 'not_registered', __( 'This site is not registered with Plume AI - Write and Design.', 'plume' ) );
		}

		$response = wp_remote_post(
			TierConfig::get_proxy_url() . '/rotate-secret',
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
			TierManager::set_site_tier( sanitize_text_field( $body['tier'] ) );
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
			TierManager::set_site_tier( sanitize_text_field( $body['tier'] ) );
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
		$url   = 'https://plume.lemonsqueezy.com/checkout/buy/' . rawurlencode( $variant_id );
		if ( $token ) {
			$url .= '?checkout[custom][site_token]=' . rawurlencode( $token );
		}
		return $url;
	}

	/**
	 * Return the LemonSqueezy variant ID for a plan, with wp-config.php override support.
	 *
	 * Defaults match the live store. Override via PLUME_LS_MONTHLY_ID or
	 * PLUME_LS_ANNUAL_ID in wp-config.php to change variant IDs without a
	 * plugin release (e.g. after a store migration).
	 *
	 * @since 1.2.0
	 * @since 1.12.0 Dropped the 'byok' plan — bringing your own key is
	 *                      free on every tier, so it has no checkout.
	 * @param string $plan One of 'monthly', 'annual'.
	 * @return string LemonSqueezy variant ID.
	 * @throws \InvalidArgumentException When an unrecognised plan key is passed.
	 */
	private static function plan_id( string $plan ): string {
		$map = [
			'monthly' => defined( 'PLUME_LS_MONTHLY_ID' ) ? PLUME_LS_MONTHLY_ID : '1550505',
			'annual'  => defined( 'PLUME_LS_ANNUAL_ID' ) ? PLUME_LS_ANNUAL_ID : '1550477',
		];
		if ( ! array_key_exists( $plan, $map ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal developer error, not user-facing output.
			throw new \InvalidArgumentException( "Unknown plan key: '{$plan}'" );
		}
		return $map[ $plan ];
	}
}

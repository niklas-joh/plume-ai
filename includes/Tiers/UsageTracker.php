<?php
/**
 * Tracks per-user monthly API request consumption against tier limits.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tiers;

use Plume\Proxy\SiteRegistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks per-user monthly credit consumption.
 *
 * @since 1.2.0
 */
class UsageTracker {

	/**
	 * Monthly credit allowance for the free tier.
	 *
	 * Mirrors MONTHLY_CREDIT_LIMITS.free in plume-proxy/src/credits.ts.
	 *
	 * @since 1.11.0
	 */
	public const FREE_CREDITS = 100;

	/**
	 * Monthly credit allowance for the pro_managed tier.
	 *
	 * Mirrors MONTHLY_CREDIT_LIMITS.pro_managed in plume-proxy/src/credits.ts.
	 *
	 * @since 1.11.0
	 */
	public const PRO_MANAGED_CREDITS = 500;

	/**
	 * Fallback monthly credit limit used when the tier is unrecognised.
	 *
	 * @since 1.11.0
	 * @deprecated Use FREE_CREDITS or PRO_MANAGED_CREDITS directly.
	 */
	public const FALLBACK_LIMIT = self::FREE_CREDITS;

	/**
	 * HTTP timeout, in seconds, for the hot-path live credit-limit fetch.
	 *
	 * Deliberately short: this runs on a cache miss while resolving a user's
	 * usage summary, so a slow/unreachable Worker must not stall the request —
	 * on timeout we fall back to the hardcoded constants above.
	 *
	 * @since 1.13.1
	 */
	private const CONFIG_FETCH_TIMEOUT = 3;

	/**
	 * Returns the wp_usermeta key for the current calendar month's token counter.
	 *
	 * Centralises the key format so all consumers (get_usage, log_usage, dev-tools
	 * REST endpoints) derive it from a single place. If the format ever changes,
	 * only this method needs updating.
	 *
	 * @since 1.11.0
	 * @return string Meta key in the form plume_credits_YYYY_MM.
	 */
	public static function get_current_month_key(): string {
		return 'plume_credits_' . gmdate( 'Y_m' );
	}

	/**
	 * Returns the current month's usage summary for a user.
	 *
	 * `can_use` is always true: the Cloudflare Worker's KV ledger is the sole
	 * source of truth for credit enforcement now (it rejects exhausted requests
	 * with a 429), so this local summary exists purely for dashboard display.
	 *
	 * @since 1.2.0
	 * @since 1.11.0 limit now comes from get_cached_credit_limit() instead of
	 *                      the deleted TierManager::get_monthly_limit(); can_use is
	 *                      hardcoded true rather than computed locally.
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return array{tier: string, used: int, limit: int|null, remaining: int|null, can_use: bool}
	 */
	public static function get_usage( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$tier    = TierManager::get_user_tier();
		$limit   = self::get_cached_credit_limit( $tier );

		$key  = self::get_current_month_key();
		$used = (int) get_user_meta( $user_id, $key, true );

		if ( null === $limit ) {
			return [
				'tier'      => $tier,
				'used'      => $used,
				'limit'     => null,
				'remaining' => null,
				'can_use'   => true,
			];
		}

		return [
			'tier'      => $tier,
			'used'      => $used,
			'limit'     => $limit,
			'remaining' => max( 0, $limit - $used ),
			'can_use'   => true,
		];
	}

	/**
	 * Returns the monthly credit limit for a tier, cached in a transient.
	 *
	 * On a cache miss, fetches the live value from the Worker's `GET /v1/config`
	 * endpoint (the Worker's `MONTHLY_CREDIT_LIMITS` is authoritative — this
	 * avoids drift between the two). This adds a short, timeout-bounded HTTP
	 * round trip on a cache miss only (at most once per tier per day, per the
	 * transient TTL); on any failure (unregistered site, network error,
	 * non-200, malformed body) this falls back to the hardcoded constants
	 * below and still caches that fallback, so a slow/unreachable Worker never
	 * causes a miss on every subsequent call.
	 *
	 * @since 1.11.0
	 * @since 1.13.1 Fetches the live limit from the Worker on a cache miss
	 *                      instead of always falling through to a hardcoded value.
	 * @param string $tier Tier slug.
	 * @return int|null Monthly credit limit, or null for the unlimited pro_byok tier.
	 */
	public static function get_cached_credit_limit( string $tier ): ?int {
		if ( 'pro_byok' === $tier ) {
			return null;
		}

		$transient_key = 'plume_credit_limit_' . $tier;
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$limit = self::fetch_live_credit_limit( $tier );
		if ( null === $limit ) {
			$tier_limits = [
				'free'        => self::FREE_CREDITS,
				'pro_managed' => self::PRO_MANAGED_CREDITS,
			];
			$limit       = $tier_limits[ $tier ] ?? self::FALLBACK_LIMIT;
		}

		set_transient( $transient_key, $limit, DAY_IN_SECONDS );
		return $limit;
	}

	/**
	 * Fetch this site's live credit limit from the Worker's GET /v1/config endpoint.
	 *
	 * Bearer-authenticated with the site's own token; the Worker resolves the
	 * tier from the token itself rather than trusting a caller-supplied value.
	 * That resolved tier can disagree with $tier — e.g. TierManager::get_user_tier()
	 * locally downgrades an unverified pro site to 'free' while the Worker's
	 * SiteRecord still says 'pro_managed' — so the response's `tier` is checked
	 * against $tier before use; a mismatch is treated like any other failure and
	 * falls back to the hardcoded constant for $tier. Without this check the
	 * limit would get cached under the wrong tier's transient key. Read-only:
	 * unlike /rotate-secret, this never mutates the site's Worker-side record.
	 *
	 * @since 1.13.1
	 * @param string $tier Tier slug the result will be cached under; validated against
	 *                     the Worker's own view of the tier before the limit is trusted.
	 * @return int|null Live limit from the Worker, or null on any failure (including a
	 *                  tier mismatch) so the caller falls back.
	 */
	private static function fetch_live_credit_limit( string $tier ): ?int {
		$token = SiteRegistration::get_site_token();
		if ( '' === $token ) {
			return null;
		}

		$response = wp_remote_get(
			TierConfig::get_proxy_url() . '/v1/config',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => self::CONFIG_FETCH_TIMEOUT,
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['credit_limit'] ) || ! is_numeric( $body['credit_limit'] ) ) {
			return null;
		}

		if ( ! isset( $body['tier'] ) || $tier !== $body['tier'] ) {
			return null;
		}

		return (int) $body['credit_limit'];
	}

	/**
	 * Increments the current month's credit counter for a user.
	 *
	 * Uses an atomic SQL UPDATE to avoid a read-modify-write race condition under concurrency.
	 *
	 * @since 1.2.0
	 * @param int      $credits Number of credits to add.
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return void
	 */
	public static function log_usage( int $credits, ?int $user_id = null ): void {
		// BYOK users bypass the Worker entirely and have no credit limit; credits_charged is
		// always 0 for them. Skip the DB write to avoid a no-op UPDATE on every chat message.
		if ( $credits <= 0 ) {
			return;
		}
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$key     = self::get_current_month_key();
		// Atomic increment avoids the read-modify-write race condition that occurs when two
		// concurrent requests read the same value and each overwrites it. $wpdb->update()
		// cannot express SET meta_value = meta_value + %d, so a direct query is required.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = meta_value + %d WHERE user_id = %d AND meta_key = %s",
				$credits,
				$user_id,
				$key
			)
		);
		if ( ! $wpdb->rows_affected ) {
			add_user_meta( $user_id, $key, $credits, true );
		}
	}
}

<?php
/**
 * Tracks per-user monthly API request consumption against tier limits.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tiers;

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
	 * Mirrors MONTHLY_CREDIT_LIMITS.free in plume-proxy/src/index.ts.
	 *
	 * @since 1.11.0
	 */
	public const FREE_CREDITS = 100;

	/**
	 * Monthly credit allowance for the pro_managed tier.
	 *
	 * Mirrors MONTHLY_CREDIT_LIMITS.pro_managed in plume-proxy/src/index.ts.
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
	 * Known interim limitation (tracked as a follow-up GitHub issue — a minimal
	 * Worker endpoint such as `GET /v1/config` returning
	 * `{ credit_limits: { free: 100, pro_managed: 500 } }`): the Worker's
	 * `/register` and `/rotate-secret` responses do not yet expose a credit-limit
	 * field, so there is currently nothing to fetch on a cache miss. The real
	 * per-tier limit (defined Worker-side in `MONTHLY_CREDIT_LIMITS`) cannot be
	 * read from PHP until that follow-up ships, so every cache miss falls
	 * through to FALLBACK_LIMIT and caches that value. This method still owns
	 * the transient read-through/TTL/pro_byok-null plumbing so that wiring in
	 * the real Worker fetch later is a one-line change inside this method, not
	 * a call-site migration across the plugin.
	 *
	 * The transient is deliberately on the hot path's "miss" side only — never
	 * blocking a real request on an HTTP round trip is more important than a
	 * dashboard figure being briefly stale or wrong by a fallback margin.
	 *
	 * @since 1.11.0
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

		// TODO: fetch the real limit from the Worker once it exposes one (see PHPDoc above).
		$tier_limits = [
			'free'        => self::FREE_CREDITS,
			'pro_managed' => self::PRO_MANAGED_CREDITS,
		];
		$limit       = $tier_limits[ $tier ] ?? self::FALLBACK_LIMIT;
		set_transient( $transient_key, $limit, DAY_IN_SECONDS );
		return $limit;
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

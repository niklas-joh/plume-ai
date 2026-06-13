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
 * Tracks per-user monthly token consumption.
 *
 * @since 1.2.0
 */
class UsageTracker {

	/**
	 * Returns the wp_usermeta key for the current calendar month's token counter.
	 *
	 * Centralises the key format so all consumers (get_usage, log_usage, dev-tools
	 * REST endpoints) derive it from a single place. If the format ever changes,
	 * only this method needs updating.
	 *
	 * @since 1.11.0
	 * @return string Meta key in the form plume_usage_YYYY_MM.
	 */
	public static function get_current_month_key(): string {
		return 'plume_usage_' . gmdate( 'Y_m' );
	}

	/**
	 * Returns the current month's usage summary for a user.
	 *
	 * @since 1.2.0
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return array{tier: string, used: int, limit: int|null, remaining: int|null, can_use: bool}
	 */
	public static function get_usage( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$tier    = TierManager::get_user_tier( $user_id );
		$limit   = TierManager::get_monthly_limit( $tier );

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
			'can_use'   => $used < $limit,
		];
	}

	/**
	 * Increments the current month's token counter for a user.
	 *
	 * Uses an atomic SQL UPDATE to avoid a read-modify-write race condition under concurrency.
	 *
	 * @since 1.2.0
	 * @param int      $tokens  Number of tokens to add.
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return void
	 */
	public static function log_usage( int $tokens, ?int $user_id = null ): void {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$key     = self::get_current_month_key();
		// Atomic increment avoids the read-modify-write race condition that occurs when two
		// concurrent requests read the same value and each overwrites it. $wpdb->update()
		// cannot express SET meta_value = meta_value + %d, so a direct query is required.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_value = meta_value + %d WHERE user_id = %d AND meta_key = %s",
				$tokens,
				$user_id,
				$key
			)
		);
		if ( ! $wpdb->rows_affected ) {
			add_user_meta( $user_id, $key, $tokens, true );
		}
	}

	/**
	 * Checks whether a user is within their monthly token allowance.
	 *
	 * @since 1.2.0
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return bool True when the user can still make requests this month.
	 */
	public static function check_limit( ?int $user_id = null ): bool {
		return self::get_usage( $user_id )['can_use'];
	}
}

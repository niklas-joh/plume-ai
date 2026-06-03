<?php
/**
 * Tracks per-user monthly API request consumption against tier limits.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks per-user monthly token consumption.
 *
 * @since 1.2.0
 */
class NJ_Usage_Tracker {

	/**
	 * Returns the current month's usage summary for a user.
	 *
	 * @since 1.2.0
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return array{tier: string, used: int, limit: int|null, remaining: int|null, can_use: bool}
	 */
	public static function get_usage( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$tier    = NJ_Tier_Manager::get_user_tier( $user_id );
		$limit   = NJ_Tier_Manager::get_monthly_limit( $tier );

		$key  = 'stilus_usage_' . gmdate( 'Y_m' );
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
		$key     = 'stilus_usage_' . gmdate( 'Y_m' );
		// Atomic increment avoids the read-modify-write race condition that occurs
		// when two concurrent requests read the same value and each overwrites it.
		$wpdb->query(
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

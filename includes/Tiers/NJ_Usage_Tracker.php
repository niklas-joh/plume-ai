<?php

namespace WP_AI_Mind\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NJ_Usage_Tracker {

	public static function get_usage( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		$tier    = NJ_Tier_Manager::get_user_tier( $user_id );
		$limit   = NJ_Tier_Manager::get_monthly_limit( $tier );

		$key  = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );
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

	public static function log_usage( int $tokens, ?int $user_id = null ): void {
		$user_id = $user_id ?? get_current_user_id();
		$key     = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );
		$current = (int) get_user_meta( $user_id, $key, true );
		update_user_meta( $user_id, $key, $current + $tokens );
	}

	public static function check_limit( ?int $user_id = null ): bool {
		return self::get_usage( $user_id )['can_use'];
	}
}

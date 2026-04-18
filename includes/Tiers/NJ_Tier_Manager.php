<?php

namespace WP_AI_Mind\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NJ_Tier_Manager {

	const META_KEY = 'wp_ai_mind_tier';

	const TIERS = [ 'free', 'pro_managed', 'pro_byok' ];

	// Single source of truth: what each tier can do.
	const FEATURES = [
		'free'        => [ 'chat' => true,  'model_selection' => false, 'own_api_key' => false ],
		'pro_managed' => [ 'chat' => true,  'model_selection' => true,  'own_api_key' => false ],
		'pro_byok'    => [ 'chat' => true,  'model_selection' => true,  'own_api_key' => true  ],
	];

	const MONTHLY_LIMITS = [
		'free'        => 50000,
		'pro_managed' => 2000000,
		'pro_byok'    => null, // unlimited
	];

	public static function get_user_tier( ?int $user_id = null ): string {
		$user_id = $user_id ?: get_current_user_id();
		return get_user_meta( $user_id, self::META_KEY, true ) ?: 'free';
	}

	public static function set_user_tier( string $tier, ?int $user_id = null ): bool {
		if ( ! in_array( $tier, self::TIERS, true ) ) {
			return false;
		}
		$user_id = $user_id ?: get_current_user_id();
		return (bool) update_user_meta( $user_id, self::META_KEY, $tier );
	}

	public static function user_can( string $feature, ?int $user_id = null ): bool {
		$tier = self::get_user_tier( $user_id );
		return (bool) ( self::FEATURES[ $tier ][ $feature ] ?? false );
	}

	public static function get_monthly_limit( string $tier ): ?int {
		return array_key_exists( $tier, self::MONTHLY_LIMITS ) ? self::MONTHLY_LIMITS[ $tier ] : 50000;
	}
}

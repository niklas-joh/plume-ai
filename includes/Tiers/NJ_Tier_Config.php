<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Single source of truth for tier capabilities and limits.
// Only constants here — no WordPress function calls.
class NJ_Tier_Config {

	const TIERS = [ 'free', 'trial', 'pro_managed', 'pro_byok' ];

	const FEATURES = [
		'free'        => [
			'chat'            => true,
			'model_selection' => false,
			'own_api_key'     => false,
		],
		'trial'       => [
			'chat'            => true,
			'model_selection' => false,
			'own_api_key'     => false,
		],
		'pro_managed' => [
			'chat'            => true,
			'model_selection' => true,
			'own_api_key'     => false,
		],
		'pro_byok'    => [
			'chat'            => true,
			'model_selection' => true,
			'own_api_key'     => true,
		],
	];

	// Monthly token limits; null = unlimited.
	const MONTHLY_LIMITS = [
		'free'        => 50000,
		'trial'       => 300000,
		'pro_managed' => 2000000,
		'pro_byok'    => null,
	];

	const TRIAL_DAYS = 30;

	const PROXY_URL = 'https://wp-ai-mind-proxy.wp-ai-mind.workers.dev';

	public static function get_valid_tiers(): array {
		return self::TIERS;
	}

	public static function get_feature( string $tier, string $feature ): bool {
		return (bool) ( self::FEATURES[ $tier ][ $feature ] ?? false );
	}

	public static function get_limit( string $tier ): ?int {
		return array_key_exists( $tier, self::MONTHLY_LIMITS ) ? self::MONTHLY_LIMITS[ $tier ] : 50000;
	}
}

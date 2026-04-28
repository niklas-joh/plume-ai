<?php
/**
 * Static configuration for tier capabilities and monthly request limits.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for tier capabilities and limits.
 *
 * The constants and any methods that do not call WordPress i18n functions are
 * safe to load before `init`. Methods that call `__()` (such as
 * `get_tier_labels()`) must only be invoked after `init` when translations
 * are available.
 *
 * @since 1.2.0
 */
class NJ_Tier_Config {

	const TIERS = [ 'free', 'trial', 'pro_managed', 'pro_byok' ];

	const FEATURES = [
		'free'        => [
			'chat'            => true,
			'generator'       => false,
			'seo'             => false,
			'images'          => false,
			'model_selection' => false,
			'own_api_key'     => false,
		],
		'trial'       => [
			'chat'            => true,
			'generator'       => true,
			'seo'             => true,
			'images'          => true,
			'model_selection' => false,
			'own_api_key'     => false,
		],
		'pro_managed' => [
			'chat'            => true,
			'generator'       => true,
			'seo'             => true,
			'images'          => true,
			'model_selection' => true,
			'own_api_key'     => false,
		],
		'pro_byok'    => [
			'chat'            => true,
			'generator'       => true,
			'seo'             => true,
			'images'          => true,
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

	/**
	 * Return the base URL of the Cloudflare Worker proxy.
	 *
	 * Can be overridden via the WP_AI_MIND_PROXY_URL constant for local dev or staging.
	 *
	 * @since 1.2.0
	 * @return string Base URL without trailing slash.
	 */
	public static function get_proxy_url(): string {
		return rtrim( defined( 'WP_AI_MIND_PROXY_URL' ) ? WP_AI_MIND_PROXY_URL : 'https://wp-ai-mind-proxy.wp-ai-mind.workers.dev', '/' );
	}

	/**
	 * Returns all recognised tier slugs.
	 *
	 * @since 1.2.0
	 * @return string[] List of valid tier slugs.
	 */
	public static function get_valid_tiers(): array {
		return self::TIERS;
	}

	/**
	 * Returns whether a feature is enabled for a given tier.
	 *
	 * @since 1.2.0
	 * @param string $tier    Tier slug.
	 * @param string $feature Feature key (e.g. 'chat', 'own_api_key').
	 * @return bool True when the feature is enabled for the tier.
	 */
	public static function get_feature( string $tier, string $feature ): bool {
		return (bool) ( self::FEATURES[ $tier ][ $feature ] ?? false );
	}

	/**
	 * Returns translatable human-readable labels for all tier slugs.
	 *
	 * Centralised here so NJ_Tier_Status_Page and NJ_Usage_Widget share
	 * the same strings and cannot drift out of sync.
	 *
	 * @since 1.2.0
	 * @return array<string, string> Map of tier slug → display label.
	 */
	public static function get_tier_labels(): array {
		return [
			'free'        => __( 'Free', 'wp-ai-mind' ),
			'trial'       => __( 'Trial', 'wp-ai-mind' ),
			'pro_managed' => __( 'Pro Managed', 'wp-ai-mind' ),
			'pro_byok'    => __( 'Pro BYOK', 'wp-ai-mind' ),
		];
	}

	/**
	 * Returns the monthly token limit for a tier.
	 *
	 * Falls back to the 'free' limit (50 000) when the tier is unrecognised.
	 *
	 * @since 1.2.0
	 * @param string $tier Tier slug.
	 * @return int|null Monthly token limit, or null for unlimited tiers.
	 */
	public static function get_limit( string $tier ): ?int {
		return array_key_exists( $tier, self::MONTHLY_LIMITS ) ? self::MONTHLY_LIMITS[ $tier ] : 50000;
	}
}

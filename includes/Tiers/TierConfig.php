<?php
/**
 * Static configuration for tier capabilities and monthly request limits.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for recognised tier slugs and proxy connectivity.
 *
 * No feature is tier-gated (WP.org Guideline 5 — plugins must be fully
 * functional). Tiers only determine the managed service's credit allowance and
 * model catalogue, enforced exclusively by the Cloudflare Worker's KV store
 * and fetched/cached by UsageTracker. This class therefore only owns the
 * valid tier slugs and proxy URL resolution.
 *
 * @since 1.2.0
 */
class TierConfig {

	/**
	 * All recognised tier slugs.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const TIERS = [ 'free', 'pro_managed', 'pro_byok' ];

	/**
	 * Production proxy URL used when PLUME_PROXY_URL is not defined.
	 *
	 * @since 1.8.1
	 */
	const DEFAULT_PROXY_URL = 'https://plume-proxy.plumewp.workers.dev';

	/**
	 * Return the base URL of the Cloudflare Worker proxy.
	 *
	 * Can be overridden via the PLUME_PROXY_URL constant for local dev or staging.
	 *
	 * @since 1.2.0
	 * @return string Base URL without trailing slash.
	 */
	public static function get_proxy_url(): string {
		if ( defined( 'PLUME_PROXY_URL' ) ) {
			return rtrim( PLUME_PROXY_URL, '/' );
		}
		$option = (string) get_option( 'plume_proxy_url', '' );
		if ( '' !== $option ) {
			return rtrim( $option, '/' );
		}
		return self::DEFAULT_PROXY_URL;
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
	 * Returns translatable human-readable labels for all tier slugs.
	 *
	 * Centralised here so TierStatusPage and UsageWidget share
	 * the same strings and cannot drift out of sync.
	 *
	 * @since 1.2.0
	 * @return array<string, string> Map of tier slug → display label.
	 */
	public static function get_tier_labels(): array {
		return [
			'free'        => __( 'Free', 'plume' ),
			'pro_managed' => __( 'Pro Managed', 'plume' ),
			'pro_byok'    => __( 'Pro BYOK', 'plume' ),
		];
	}
}

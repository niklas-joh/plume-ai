<?php
/**
 * Manages user licence tiers (Free, Trial, Pro Managed, Pro BYOK).
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tiers;

use Plume\Payments\TierUpdateWebhookController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages site-wide tier assignment.
 *
 * Paid entitlements (Pro Managed, Pro BYOK) live on a site-wide option because a
 * LemonSqueezy subscription is purchased per site, not per user.
 *
 * @since 1.2.0
 */
class TierManager {

	public const META_KEY = 'plume_tier';

	/**
	 * Option key for the site-wide tier (paid entitlement source of truth).
	 *
	 * Stored with autoload=false because it is only consulted when
	 * TierManager itself is hit, not on every page load.
	 *
	 * @since 1.9.0
	 */
	public const SITE_OPTION = 'plume_site_tier';

	/**
	 * Option key for the HMAC signature that authenticates the stored site tier.
	 *
	 * Written by set_site_tier() alongside SITE_OPTION; verified by
	 * get_user_tier() before honouring a paid tier value. This detects direct
	 * database edits that bypass the signed webhook.
	 *
	 * @since 1.10.0
	 */
	public const SITE_OPTION_SIG = 'plume_site_tier_sig';

	// ── Tier CRUD ─────────────────────────────────────────────────────────────

	/**
	 * Returns the current site-wide tier slug.
	 *
	 * Paid status is a site-level fact (a LemonSqueezy subscription is purchased
	 * per site, not per user), so there is no per-user resolution branch — every
	 * caller, logged in or not, resolves to the same value from the site option.
	 *
	 * @since 1.2.0
	 * @since 1.9.0 Paid tiers resolve from the site-wide option.
	 * @since 1.11.0 Removed the unused $user_id parameter and the trial-meta
	 *                      fallback now that the trial tier no longer exists.
	 * @return string Tier slug (e.g. 'free', 'pro_managed', 'pro_byok').
	 */
	public static function get_user_tier(): string {
		$site_tier = (string) get_option( self::SITE_OPTION, 'free' );
		if ( ! in_array( $site_tier, TierConfig::get_valid_tiers(), true ) ) {
			return 'free';
		}
		if ( in_array( $site_tier, [ 'pro_managed', 'pro_byok' ], true ) && ! self::is_site_tier_verified( $site_tier ) ) {
			return 'free';
		}
		return $site_tier;
	}

	/**
	 * Sets the site-wide tier (used by the LemonSqueezy webhook receiver).
	 *
	 * Fires `plume_tier_changed` on success so other modules can react
	 * (cache invalidation, audit logs, etc.).
	 *
	 * @since 1.9.0
	 * @param string $tier Tier slug; must be one of TierConfig::get_valid_tiers().
	 * @return bool True when the option was written, false when the tier is invalid
	 *              or the option write failed.
	 */
	public static function set_site_tier( string $tier ): bool {
		if ( ! in_array( $tier, TierConfig::get_valid_tiers(), true ) ) {
			return false;
		}
		// autoload=false: this option is only consulted from TierManager, not
		// every page load, so paying the autoload cost on every request is wasteful.
		$ok = (bool) update_option( self::SITE_OPTION, $tier, false );
		if ( $ok ) {
			// No-op when no secret is registered: is_site_tier_verified() returns true
			// for unregistered installs, so the tier resolves without a signature.
			// On a registered staging site that already has a secret, calling
			// set_site_tier() directly (e.g. via the dev-tools REST endpoint) will
			// store an unsigned paid tier — needs_tier_verification_resync() will
			// return true and TierSyncBackfillNotice will appear. Re-register via the
			// settings page to obtain a properly signed tier from the Worker.
			$secret = (string) get_option( TierUpdateWebhookController::OPTION_SECRET, '' );
			if ( '' !== $secret ) {
				if ( in_array( $tier, [ 'pro_managed', 'pro_byok' ], true ) ) {
					update_option( self::SITE_OPTION_SIG, hash_hmac( 'sha256', $tier, $secret ), false );
				} else {
					// Non-paid tiers carry no signature value; remove any stale sig from a prior subscription.
					delete_option( self::SITE_OPTION_SIG );
				}
			}
			do_action( 'plume_tier_changed', $tier );
		}
		return $ok;
	}

	/**
	 * Checks whether a user's current tier grants access to a feature.
	 *
	 * Trial removal means every tier now has uniform access to content features
	 * (chat/generator/seo/images) — credit exhaustion is the Worker's enforcement
	 * mechanism, not a PHP-side feature gate. Only model_selection and own_api_key
	 * remain genuinely tier-gated capabilities.
	 *
	 * @since 1.2.0
	 * @since 1.11.0 Collapsed to a two-branch match() now that TierConfig::FEATURES
	 *                      no longer exists. Removed the $user_id parameter entirely —
	 *                      its only real caller (ToolExecutor's generate_seo_meta() tier
	 *                      gate) was deleted in the same redesign, leaving zero production
	 *                      callers that passed a real argument; per the no-legacy-shims
	 *                      directive a parameter kept only against a hypothetical future
	 *                      caller is removed rather than left unused.
	 * @param string $feature Feature key (e.g. 'chat', 'generator', 'own_api_key').
	 * @return bool True when the feature is enabled for the site's tier.
	 */
	public static function user_can( string $feature ): bool {
		$tier = self::get_user_tier();
		return match ( $feature ) {
			'model_selection' => 'free' !== $tier,
			'own_api_key'     => 'pro_byok' === $tier,
			default           => true,
		};
	}

	/**
	 * Returns whether the current site is on a paid tier.
	 *
	 * Centralises the repeated `'free' !== get_user_tier()` computation duplicated
	 * across admin pages and REST controllers (Domains B/C otherwise reimplement
	 * this five-plus times).
	 *
	 * @since 1.11.0
	 * @return bool True when the site tier is anything other than 'free'.
	 */
	public static function is_paid(): bool {
		return 'free' !== self::get_user_tier();
	}

	// ── Tier integrity ────────────────────────────────────────────────────────

	/**
	 * Verifies the HMAC signature stored alongside the site tier option.
	 *
	 * Returns true when no sync secret exists so that unregistered sites are
	 * never silently downgraded. Once a secret is present every paid-tier write
	 * must have produced a companion signature; absence means the option was
	 * written outside the normal webhook path.
	 *
	 * @since 1.10.0
	 * @param string $tier The tier slug to verify against the stored signature.
	 * @return bool True when verification passes or cannot be performed; false on mismatch.
	 */
	private static function is_site_tier_verified( string $tier ): bool {
		$secret = (string) get_option( TierUpdateWebhookController::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			return true; // Unregistered site — no secret to check against.
		}
		$stored = (string) get_option( self::SITE_OPTION_SIG, '' );
		if ( '' === $stored ) {
			return false; // Secret present but signature absent — unverified.
		}
		return hash_equals( hash_hmac( 'sha256', $tier, $secret ), $stored );
	}

	/**
	 * Returns true when a paid tier is stored but its HMAC signature is missing
	 * or does not match, signalling that the DB option may have been edited directly.
	 *
	 * Used by TierSyncBackfillNotice to prompt a re-registration that re-issues
	 * a signed tier from the Worker.
	 *
	 * @since 1.10.0
	 * @return bool True when the stored paid tier cannot be verified and a re-sync is needed.
	 */
	public static function needs_tier_verification_resync(): bool {
		$secret = (string) get_option( TierUpdateWebhookController::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			return false; // Not registered — no action needed.
		}
		$site_tier = (string) get_option( self::SITE_OPTION, 'free' );
		if ( ! in_array( $site_tier, [ 'pro_managed', 'pro_byok' ], true ) ) {
			return false; // Free tier — nothing to verify.
		}
		$stored_sig = (string) get_option( self::SITE_OPTION_SIG, '' );
		if ( '' === $stored_sig ) {
			return true;
		}
		return ! hash_equals( hash_hmac( 'sha256', $site_tier, $secret ), $stored_sig );
	}
}

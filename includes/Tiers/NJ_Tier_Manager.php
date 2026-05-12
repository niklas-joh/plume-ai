<?php
/**
 * Manages user licence tiers (Free, Trial, Pro Managed, Pro BYOK).
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages user tier assignment and trial lifecycle.
 *
 * Paid entitlements (Pro Managed, Pro BYOK) live on a site-wide option because a
 * LemonSqueezy subscription is purchased per site, not per user. Per-user meta
 * is reserved for the trial flag, which is the only state that genuinely varies
 * between users on the same install.
 *
 * @since 1.2.0
 */
class NJ_Tier_Manager {

	public const META_KEY           = 'wp_ai_mind_tier';
	public const TRIAL_STARTED_META = 'wp_ai_mind_trial_started';

	/**
	 * Option key for the site-wide tier (paid entitlement source of truth).
	 *
	 * Stored with autoload=false because it is only consulted when
	 * NJ_Tier_Manager itself is hit, not on every page load.
	 *
	 * @since 1.9.0
	 */
	public const SITE_OPTION = 'wp_ai_mind_site_tier';

	// ── Tier CRUD ─────────────────────────────────────────────────────────────

	/**
	 * Returns the current tier slug for a user.
	 *
	 * Resolution order (paid wins over active trial):
	 *   1. If no user (logged-out REST, cron, CLI): site option, default 'free'.
	 *   2. Site option, when it is 'pro_managed' or 'pro_byok'.
	 *   3. User meta 'trial' when the trial is still active.
	 *   4. Site option, default 'free'.
	 *
	 * @since 1.2.0
	 * @since 1.9.0 Site option now wins over active trial meta for paid tiers.
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return string Tier slug (e.g. 'free', 'trial', 'pro_managed', 'pro_byok').
	 */
	public static function get_user_tier( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();

		// Logged-out REST callers, cron, and CLI have no user context — paid status
		// is a site-level fact, so consult the site option directly.
		if ( $user_id <= 0 ) {
			$site_tier = (string) get_option( self::SITE_OPTION, 'free' );
			return in_array( $site_tier, NJ_Tier_Config::get_valid_tiers(), true ) ? $site_tier : 'free';
		}

		$site_tier = (string) get_option( self::SITE_OPTION, 'free' );
		if ( 'pro_managed' === $site_tier || 'pro_byok' === $site_tier ) {
			return $site_tier;
		}

		$meta = (string) get_user_meta( $user_id, self::META_KEY, true );
		if ( 'trial' === $meta && self::is_trial_active( $user_id ) ) {
			return 'trial';
		}

		// Treat unknown site_option values as 'free' rather than passing them through
		// — protects callers from corrupt option rows and gives legacy tests that stub
		// get_option globally a deterministic floor.
		return in_array( $site_tier, NJ_Tier_Config::get_valid_tiers(), true ) ? $site_tier : 'free';
	}

	/**
	 * Assigns a tier to a user.
	 *
	 * Returns false when $tier is not a recognised tier slug.
	 *
	 * @since 1.2.0
	 * @param string   $tier    Tier slug to assign.
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return bool True on success, false when the tier is invalid or the meta update fails.
	 */
	public static function set_user_tier( string $tier, ?int $user_id = null ): bool {
		if ( ! in_array( $tier, NJ_Tier_Config::get_valid_tiers(), true ) ) {
			return false;
		}
		$user_id = $user_id ?? get_current_user_id();
		return (bool) update_user_meta( $user_id, self::META_KEY, $tier );
	}

	/**
	 * Sets the site-wide tier (used by the LemonSqueezy webhook receiver).
	 *
	 * Fires `wp_ai_mind_tier_changed` on success so other modules can react
	 * (cache invalidation, audit logs, etc.).
	 *
	 * @since 1.9.0
	 * @param string $tier Tier slug; must be one of NJ_Tier_Config::get_valid_tiers().
	 * @return bool True when the option was written, false when the tier is invalid
	 *              or the option write failed.
	 */
	public static function set_site_tier( string $tier ): bool {
		if ( ! in_array( $tier, NJ_Tier_Config::get_valid_tiers(), true ) ) {
			return false;
		}
		// autoload=false: this option is only consulted from NJ_Tier_Manager, not
		// every page load, so paying the autoload cost on every request is wasteful.
		$ok = (bool) update_option( self::SITE_OPTION, $tier, false );
		if ( $ok ) {
			do_action( 'wp_ai_mind_tier_changed', $tier );
		}
		return $ok;
	}

	/**
	 * Checks whether a user's current tier grants access to a feature.
	 *
	 * @since 1.2.0
	 * @param string   $feature Feature key (e.g. 'chat', 'generator', 'own_api_key').
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return bool True when the feature is enabled for the user's tier.
	 */
	public static function user_can( string $feature, ?int $user_id = null ): bool {
		$tier = self::get_user_tier( $user_id );
		return NJ_Tier_Config::get_feature( $tier, $feature );
	}

	/**
	 * Returns the monthly token limit for a tier.
	 *
	 * @since 1.2.0
	 * @param string $tier Tier slug.
	 * @return int|null Token limit, or null for unlimited tiers.
	 */
	public static function get_monthly_limit( string $tier ): ?int {
		return NJ_Tier_Config::get_limit( $tier );
	}

	// ── Trial management ──────────────────────────────────────────────────────

	/**
	 * Starts a trial period for a user and records the start timestamp.
	 *
	 * @since 1.2.0
	 * @param int $user_id User ID.
	 * @return bool True on success, false when the tier update fails.
	 */
	public static function start_trial( int $user_id ): bool {
		if ( ! self::set_user_tier( 'trial', $user_id ) ) {
			return false;
		}
		update_user_meta( $user_id, self::TRIAL_STARTED_META, time() );
		return true;
	}

	/**
	 * Checks whether a user's trial period is still within the allowed window.
	 *
	 * Reads the tier meta directly (not via get_user_tier()) to avoid the
	 * site-option short-circuit — a paid site can still have stale trial meta
	 * on individual users, and is_trial_active() must reflect that meta alone.
	 *
	 * @since 1.2.0
	 * @param int $user_id User ID.
	 * @return bool True when the user is on a trial tier and the trial has not expired.
	 */
	public static function is_trial_active( int $user_id ): bool {
		$meta = (string) get_user_meta( $user_id, self::META_KEY, true );
		if ( 'trial' !== $meta ) {
			return false;
		}
		$started = (int) get_user_meta( $user_id, self::TRIAL_STARTED_META, true );
		if ( ! $started ) {
			return false;
		}
		return ( time() - $started ) < ( NJ_Tier_Config::TRIAL_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Demotes expired trial users by clearing their tier meta.
	 *
	 * The site option now provides the post-trial floor (typically 'free'), so
	 * the per-user override is simply removed rather than overwritten. Intended
	 * to be called by a daily WP-Cron event. Processes users in batches to avoid
	 * memory exhaustion on sites with large user tables.
	 *
	 * The loop uses no offset because each demotion removes the user from the
	 * 'trial' result set, so the next query always fetches from the new front.
	 * The `$demoted > 0` guard prevents an infinite loop when a full batch
	 * contains only active (non-expired) trial users — without it the query
	 * would return the same 200 users indefinitely.
	 *
	 * @since 1.2.0
	 * @since 1.9.0 Deletes the meta instead of overwriting with 'free'.
	 * @return void
	 */
	public static function maybe_demote_expired_trials(): void {
		$batch_size = 200;

		do {
			$users   = get_users(
				[
					'meta_key'   => self::META_KEY,
					'meta_value' => 'trial',
					'fields'     => 'ID',
					'number'     => $batch_size,
				]
			);
			$found   = count( $users );
			$demoted = 0;
			foreach ( $users as $user_id ) {
				if ( ! self::is_trial_active( (int) $user_id ) ) {
					if ( delete_user_meta( (int) $user_id, self::META_KEY ) ) {
						++$demoted;
					}
				}
			}
		} while ( $found === $batch_size && $demoted > 0 );
	}
}

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
 * @since 1.2.0
 */
class NJ_Tier_Manager {

	const META_KEY           = 'wp_ai_mind_tier';
	const TRIAL_STARTED_META = 'wp_ai_mind_trial_started';

	// ── Tier CRUD ─────────────────────────────────────────────────────────────

	/**
	 * Returns the current tier slug for a user.
	 *
	 * Defaults to 'free' when no tier meta is stored.
	 *
	 * @since 1.2.0
	 * @param int|null $user_id User ID; defaults to the current user.
	 * @return string Tier slug (e.g. 'free', 'trial', 'pro_managed', 'pro_byok').
	 */
	public static function get_user_tier( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();
		$stored  = get_user_meta( $user_id, self::META_KEY, true );
		return $stored ? (string) $stored : 'free';
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
	 * @since 1.2.0
	 * @param int $user_id User ID.
	 * @return bool True when the user is on a trial tier and the trial has not expired.
	 */
	public static function is_trial_active( int $user_id ): bool {
		if ( self::get_user_tier( $user_id ) !== 'trial' ) {
			return false;
		}
		$started = (int) get_user_meta( $user_id, self::TRIAL_STARTED_META, true );
		if ( ! $started ) {
			return false;
		}
		return ( time() - $started ) < ( NJ_Tier_Config::TRIAL_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Demotes expired trial users to the free tier.
	 *
	 * Intended to be called by a daily WP-Cron event. Processes users in batches
	 * to avoid memory exhaustion on sites with large user tables.
	 *
	 * The loop uses no offset because each demotion removes the user from the
	 * 'trial' result set, so the next query always fetches from the new front.
	 * The `$demoted > 0` guard prevents an infinite loop when a full batch
	 * contains only active (non-expired) trial users — without it the query
	 * would return the same 200 users indefinitely.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function maybe_demote_expired_trials(): void {
		$batch_size = 200;

		do {
			$users = get_users(
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
					if ( self::set_user_tier( 'free', (int) $user_id ) ) {
						++$demoted;
					}
				}
			}
		} while ( $found === $batch_size && $demoted > 0 );
	}
}

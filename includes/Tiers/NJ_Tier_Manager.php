<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Tiers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NJ_Tier_Manager {

	const META_KEY           = 'wp_ai_mind_tier';
	const TRIAL_STARTED_META = 'wp_ai_mind_trial_started';

	// ── Tier CRUD ─────────────────────────────────────────────────────────────

	public static function get_user_tier( ?int $user_id = null ): string {
		$user_id = $user_id ?? get_current_user_id();
		$stored  = get_user_meta( $user_id, self::META_KEY, true );
		return $stored ? (string) $stored : 'free';
	}

	public static function set_user_tier( string $tier, ?int $user_id = null ): bool {
		if ( ! in_array( $tier, NJ_Tier_Config::get_valid_tiers(), true ) ) {
			return false;
		}
		$user_id = $user_id ?? get_current_user_id();
		return (bool) update_user_meta( $user_id, self::META_KEY, $tier );
	}

	public static function user_can( string $feature, ?int $user_id = null ): bool {
		$tier = self::get_user_tier( $user_id );
		return NJ_Tier_Config::get_feature( $tier, $feature );
	}

	public static function get_monthly_limit( string $tier ): ?int {
		return NJ_Tier_Config::get_limit( $tier );
	}

	// ── Trial management ──────────────────────────────────────────────────────

	public static function start_trial( int $user_id ): bool {
		if ( ! self::set_user_tier( 'trial', $user_id ) ) {
			return false;
		}
		update_user_meta( $user_id, self::TRIAL_STARTED_META, time() );
		return true;
	}

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

	// Called by daily cron to demote expired trial users to free.
	public static function maybe_demote_expired_trials(): void {
		$batch_size = 200;
		$offset     = 0;

		do {
			$users = get_users(
				[
					'meta_key'   => self::META_KEY,
					'meta_value' => 'trial',
					'fields'     => 'ID',
					'number'     => $batch_size,
					'offset'     => $offset,
				]
			);
			$found = count( $users );
			foreach ( $users as $user_id ) {
				if ( ! self::is_trial_active( (int) $user_id ) ) {
					self::set_user_tier( 'free', (int) $user_id );
				}
			}
			$offset += $batch_size;
		} while ( $found === $batch_size );
	}
}

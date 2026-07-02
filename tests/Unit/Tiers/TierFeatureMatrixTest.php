<?php
/**
 * Structural contract tests confirming the trial-tier removal from TierConfig.
 *
 * TierConfig no longer owns a feature matrix or token limits — the Worker's
 * credit ledger is now the sole source of truth for both feature gating
 * (every tier can use every feature; no feature is tier-gated any more) and
 * quota enforcement. This file asserts those structures were actually removed,
 * not just hardcoded to a pass-through value, per the no-legacy-users directive.
 *
 * No WordPress functions are called by the class under test, so Brain Monkey
 * bootstrapping is not required here.
 *
 * @package Plume\Tests\Unit\Tiers
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Tiers;

use PHPUnit\Framework\TestCase;
use Plume\Tiers\TierConfig;

/**
 * Structural contract tests for TierConfig after the trial-tier/credits redesign.
 *
 * @since 1.0.0
 */
class TierFeatureMatrixTest extends TestCase {

	/**
	 * Asserts that TIERS contains exactly the three post-redesign tier slugs.
	 *
	 * @since 1.0.0
	 */
	public function test_tier_config_has_three_tiers(): void {
		$this->assertSame(
			[ 'free', 'pro_managed', 'pro_byok' ],
			TierConfig::TIERS,
			'TIERS must contain exactly the three canonical slugs — trial was removed.'
		);
	}

	/**
	 * Asserts the FEATURES constant no longer exists — replaced by uniform
	 * feature access (every tier can use every content feature).
	 *
	 * @since 1.0.0
	 */
	public function test_features_constant_does_not_exist(): void {
		$this->assertFalse(
			( new \ReflectionClass( TierConfig::class ) )->hasConstant( 'FEATURES' ),
			'FEATURES must be removed — feature gating is no longer tier-based.'
		);
	}

	/**
	 * Asserts the MONTHLY_LIMITS constant no longer exists — credit limits now
	 * live exclusively in the Worker's KV store, fetched/cached by UsageTracker.
	 *
	 * @since 1.0.0
	 */
	public function test_monthly_limits_constant_does_not_exist(): void {
		$this->assertFalse(
			( new \ReflectionClass( TierConfig::class ) )->hasConstant( 'MONTHLY_LIMITS' ),
			'MONTHLY_LIMITS must be removed — re-adding a hardcoded limits array here would reintroduce the PHP/Worker drift bug.'
		);
	}

	/**
	 * Asserts the TRIAL_DAYS constant no longer exists.
	 *
	 * @since 1.0.0
	 */
	public function test_trial_days_constant_does_not_exist(): void {
		$this->assertFalse(
			( new \ReflectionClass( TierConfig::class ) )->hasConstant( 'TRIAL_DAYS' ),
			'TRIAL_DAYS must be removed — there is no trial tier any more.'
		);
	}

	/**
	 * Asserts get_feature() no longer exists.
	 *
	 * @since 1.0.0
	 */
	public function test_get_feature_method_does_not_exist(): void {
		$this->assertFalse(
			method_exists( TierConfig::class, 'get_feature' ),
			'get_feature() must be removed along with FEATURES.'
		);
	}

	/**
	 * Asserts get_limit() no longer exists — the replacement logic lives in
	 * UsageTracker, which fetches/caches the real credit limit from the Worker.
	 *
	 * @since 1.0.0
	 */
	public function test_get_limit_method_does_not_exist(): void {
		$this->assertFalse(
			method_exists( TierConfig::class, 'get_limit' ),
			'get_limit() must be removed — UsageTracker now owns credit-limit resolution.'
		);
	}

	/**
	 * Asserts get_tier_labels() no longer carries a 'trial' entry.
	 *
	 * @since 1.0.0
	 */
	public function test_get_tier_labels_has_no_trial_key(): void {
		\Brain\Monkey\setUp();
		\Brain\Monkey\Functions\when( '__' )->alias( fn( $s ) => $s );

		$labels = TierConfig::get_tier_labels();

		\Brain\Monkey\tearDown();

		$this->assertArrayNotHasKey( 'trial', $labels );
		$this->assertSame( [ 'free', 'pro_managed', 'pro_byok' ], array_keys( $labels ) );
	}

	/**
	 * Asserts get_valid_tiers() still works and reflects the three-tier set.
	 *
	 * @since 1.0.0
	 */
	public function test_get_valid_tiers_returns_three_tiers(): void {
		$this->assertSame( [ 'free', 'pro_managed', 'pro_byok' ], TierConfig::get_valid_tiers() );
	}
}

<?php
/**
 * Data-provider-based tests that document and verify every cell in the
 * tier × feature capability matrix defined in TierConfig.
 *
 * No WordPress functions are called by the class under test, so Brain Monkey
 * bootstrapping is not required here.
 *
 * @package Stilus\Tests\Unit\Tiers
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace Stilus\Tests\Unit\Tiers;

use PHPUnit\Framework\TestCase;
use Stilus\Tiers\TierConfig;

/**
 * Exhaustive matrix tests for TierConfig capability and limit data.
 *
 * Each data-provider method expands the full set of tier × feature
 * combinations so that any change to FEATURES or MONTHLY_LIMITS
 * immediately surfaces as a named, descriptive test failure rather than
 * a silent regression.
 *
 * @since 1.0.0
 */
class TierFeatureMatrixTest extends TestCase {

	// ── Tier × feature capability matrix ─────────────────────────────────────

	/**
	 * Provides every tier × feature combination with its expected boolean value.
	 *
	 * Each entry is keyed by a human-readable label so failing test output
	 * immediately names the offending cell (e.g. "free: generator").
	 *
	 * @since 1.0.0
	 * @return array<string, array{string, string, bool}>
	 */
	public static function tier_feature_combinations(): array {
		return [
			// free tier — only chat is enabled
			'free: chat'            => [ 'free', 'chat', true ],
			'free: generator'       => [ 'free', 'generator', false ],
			'free: seo'             => [ 'free', 'seo', false ],
			'free: images'          => [ 'free', 'images', false ],
			'free: model_selection' => [ 'free', 'model_selection', false ],
			'free: own_api_key'     => [ 'free', 'own_api_key', false ],

			// trial tier — all features except model selection and own API key
			'trial: chat'            => [ 'trial', 'chat', true ],
			'trial: generator'       => [ 'trial', 'generator', true ],
			'trial: seo'             => [ 'trial', 'seo', true ],
			'trial: images'          => [ 'trial', 'images', true ],
			'trial: model_selection' => [ 'trial', 'model_selection', false ],
			'trial: own_api_key'     => [ 'trial', 'own_api_key', false ],

			// pro_managed — all features except own API key
			'pro_managed: chat'            => [ 'pro_managed', 'chat', true ],
			'pro_managed: generator'       => [ 'pro_managed', 'generator', true ],
			'pro_managed: seo'             => [ 'pro_managed', 'seo', true ],
			'pro_managed: images'          => [ 'pro_managed', 'images', true ],
			'pro_managed: model_selection' => [ 'pro_managed', 'model_selection', true ],
			'pro_managed: own_api_key'     => [ 'pro_managed', 'own_api_key', false ],

			// pro_byok — all features enabled
			'pro_byok: chat'            => [ 'pro_byok', 'chat', true ],
			'pro_byok: generator'       => [ 'pro_byok', 'generator', true ],
			'pro_byok: seo'             => [ 'pro_byok', 'seo', true ],
			'pro_byok: images'          => [ 'pro_byok', 'images', true ],
			'pro_byok: model_selection' => [ 'pro_byok', 'model_selection', true ],
			'pro_byok: own_api_key'     => [ 'pro_byok', 'own_api_key', true ],
		];
	}

	/**
	 * Asserts that get_feature() returns the documented capability for every
	 * tier × feature cell in the matrix.
	 *
	 * @since 1.0.0
	 * @dataProvider tier_feature_combinations
	 * @param string $tier     Tier slug under test.
	 * @param string $feature  Feature key under test.
	 * @param bool   $expected Expected return value of get_feature().
	 */
	public function test_get_feature_returns_expected_capability(
		string $tier,
		string $feature,
		bool $expected
	): void {
		$this->assertSame(
			$expected,
			TierConfig::get_feature( $tier, $feature ),
			"get_feature( '{$tier}', '{$feature}' ) should return " . ( $expected ? 'true' : 'false' ) . '.'
		);
	}

	// ── Monthly token limits ──────────────────────────────────────────────────

	/**
	 * Provides tier → expected monthly token limit pairs for all four tiers.
	 *
	 * @since 1.0.0
	 * @return array<string, array{string, int|null}>
	 */
	public static function monthly_limit_cases(): array {
		return [
			'free tier limit'        => [ 'free', 50000 ],
			'trial tier limit'       => [ 'trial', 300000 ],
			'pro_managed tier limit' => [ 'pro_managed', 2000000 ],
			// null signals unlimited usage for bring-your-own-key subscribers.
			'pro_byok tier limit'    => [ 'pro_byok', null ],
		];
	}

	/**
	 * Asserts that get_limit() returns the documented monthly token limit for
	 * every tier.
	 *
	 * @since 1.0.0
	 * @dataProvider monthly_limit_cases
	 * @param string   $tier     Tier slug under test.
	 * @param int|null $expected Expected monthly token limit (null = unlimited).
	 */
	public function test_monthly_limit_matches_config( string $tier, ?int $expected ): void {
		$this->assertSame(
			$expected,
			TierConfig::get_limit( $tier ),
			"get_limit( '{$tier}' ) should return " . ( null === $expected ? 'null' : (string) $expected ) . '.'
		);
	}

	// ── Structural contract tests ─────────────────────────────────────────────

	/**
	 * Asserts that TIERS contains exactly the four documented tier slugs.
	 *
	 * Adding a fifth tier without updating this test causes an immediate
	 * failure, preventing silent capability drift.
	 *
	 * @since 1.0.0
	 */
	public function test_all_tiers_defined(): void {
		$this->assertSame(
			[ 'free', 'trial', 'pro_managed', 'pro_byok' ],
			TierConfig::TIERS,
			'TIERS must contain exactly the four canonical slugs in the documented order.'
		);
	}

	/**
	 * Asserts that every tier entry in FEATURES declares all six feature keys.
	 *
	 * Catches typos and missing entries when new capabilities are introduced.
	 *
	 * @since 1.0.0
	 */
	public function test_all_features_defined(): void {
		$expected_keys = [ 'chat', 'generator', 'seo', 'images', 'model_selection', 'own_api_key' ];

		foreach ( TierConfig::FEATURES as $tier => $features ) {
			$actual_keys = array_keys( $features );
			$this->assertSame(
				$expected_keys,
				$actual_keys,
				"FEATURES['{$tier}'] must declare exactly the six canonical feature keys in the documented order."
			);
		}
	}
}

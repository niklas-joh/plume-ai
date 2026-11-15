<?php
/**
 * Unit tests for TierConfig::tier_label().
 *
 * @package Plume\Tests\Unit\Tiers
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Tiers\TierConfig;

/**
 * Plan names must read as words and never expose a tier slug.
 *
 * @since NEXT_VERSION
 */
class TierConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_known_tiers_have_standalone_plan_names(): void {
		$this->assertSame( 'Free plan', TierConfig::tier_label( 'free' ) );
		$this->assertSame( 'Pro', TierConfig::tier_label( 'pro_managed' ) );
		$this->assertSame( 'Your own API key', TierConfig::tier_label( 'pro_byok' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_unknown_tier_gets_a_neutral_name_never_the_slug(): void {
		$label = TierConfig::tier_label( 'mystery_tier' );
		$this->assertSame( 'Current plan', $label );
		$this->assertStringNotContainsString( '_', $label );
		$this->assertSame( 'Current plan', TierConfig::tier_label( '' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_every_recognised_tier_has_a_readable_name(): void {
		foreach ( TierConfig::get_valid_tiers() as $tier ) {
			$this->assertStringNotContainsString( '_', TierConfig::tier_label( $tier ) );
			$this->assertNotSame( 'Current plan', TierConfig::tier_label( $tier ) );
		}
	}
}

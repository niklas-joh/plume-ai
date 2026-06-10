<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Seo\SeoModule;
use PHPUnit\Framework\TestCase;

/**
 * Tier-gating unit tests for the SEO module REST route.
 *
 * Exercises the permission_callback chain — TierManager::user_can('seo')
 * returns false for the free tier and true for trial+.
 */
class SeoTierGatingTest extends TestCase {

	/** @var array<string, array<string, mixed>> */
	private array $captured_routes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Ensure TierManager::get_user_tier() sees 'free' site tier so the user-meta
		// path is exercised rather than the pro-site short-circuit.
		Functions\when( 'get_option' )->justReturn( 'free' );

		// Capture the registered routes so we can invoke permission_callback directly.
		$this->captured_routes = [];
		Functions\when( 'register_rest_route' )->alias(
			function ( string $namespace, string $route, array $args ): void {
				$this->captured_routes[ $route ] = $args;
			}
		);

		SeoModule::register_routes();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_seo_generate_returns_403_for_free_tier_user(): void {
		$this->assertArrayHasKey( '/seo/generate', $this->captured_routes );
		$permission_callback = $this->captured_routes['/seo/generate']['permission_callback'];

		$month_key = 'plume_usage_' . gmdate( 'Y_m' );

		// Free tier: edit_posts capability present, within usage limit, but seo feature is disabled.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key, $single = false ) use ( $month_key ) {
				if ( 'plume_tier' === $key ) {
					return 'free';
				}
				if ( $month_key === $key ) {
					return '0'; // within free limit
				}
				return '';
			}
		);

		// permission_callback returns false because TierConfig::FEATURES['free']['seo'] = false.
		$this->assertFalse( (bool) $permission_callback() );
	}

	public function test_seo_generate_returns_200_for_trial_tier_user(): void {
		$this->assertArrayHasKey( '/seo/generate', $this->captured_routes );
		$permission_callback = $this->captured_routes['/seo/generate']['permission_callback'];

		$month_key = 'plume_usage_' . gmdate( 'Y_m' );

		// Trial tier: edit_posts capability present, within usage limit, seo feature enabled.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key, $single = false ) use ( $month_key ) {
				if ( 'plume_tier' === $key ) {
					return 'trial';
				}
				if ( 'plume_trial_started' === $key ) {
					return (string) time(); // trial started now, well within the trial period
				}
				if ( $month_key === $key ) {
					return '0'; // well within 300k trial limit
				}
				return '';
			}
		);

		// permission_callback returns true because TierConfig::FEATURES['trial']['seo'] = true.
		$this->assertTrue( (bool) $permission_callback() );
	}
}

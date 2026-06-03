<?php
declare( strict_types=1 );

namespace Stilus\Tests\Integration\Generator;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Stilus\Modules\Generator\GeneratorModule;
use Stilus\Tests\Helpers\WpdbStubFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tier-gating integration tests for the Generator module REST route.
 *
 * Exercises the permission_callback chain — TierManager::user_can('generator')
 * returns false for the free tier and true for trial+.
 */
class GeneratorTierGatingTest extends TestCase {

	/** @var array<string, array<string, mixed>> */
	private array $captured_routes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Capture the registered routes so we can invoke permission_callback directly.
		$this->captured_routes = [];
		Functions\when( 'register_rest_route' )->alias(
			function ( string $namespace, string $route, array $args ): void {
				$this->captured_routes[ $route ] = $args;
			}
		);

		GeneratorModule::register_routes();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional test stub.
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_generator_generate_returns_403_for_free_tier_user(): void {
		$this->assertArrayHasKey( '/generate', $this->captured_routes );
		$permission_callback = $this->captured_routes['/generate']['permission_callback'];

		$month_key = 'stilus_usage_' . gmdate( 'Y_m' );

		// Free tier: edit_posts capability present, within usage limit, but generator feature is disabled.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key, $single = false ) use ( $month_key ) {
				if ( 'stilus_tier' === $key ) {
					return 'free';
				}
				if ( $month_key === $key ) {
					return '0'; // within free limit
				}
				return '';
			}
		);

		// permission_callback returns false because TierConfig::FEATURES['free']['generator'] = false.
		$this->assertFalse( (bool) $permission_callback() );
	}

	public function test_generator_generate_returns_200_for_trial_tier_user(): void {
		$this->assertArrayHasKey( '/generate', $this->captured_routes );
		$permission_callback = $this->captured_routes['/generate']['permission_callback'];

		$month_key = 'stilus_usage_' . gmdate( 'Y_m' );

		// Trial tier: edit_posts capability present, within usage limit, generator feature enabled.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key, $single = false ) use ( $month_key ) {
				if ( 'stilus_tier' === $key ) {
					return 'trial';
				}
				if ( $month_key === $key ) {
					return '0'; // well within 300k trial limit
				}
				return '';
			}
		);

		// permission_callback returns true because TierConfig::FEATURES['trial']['generator'] = true.
		$this->assertTrue( (bool) $permission_callback() );
	}
}

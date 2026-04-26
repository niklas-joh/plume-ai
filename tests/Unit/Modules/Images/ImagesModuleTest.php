<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Modules\Images;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Modules\Images\ImagesModule;
use PHPUnit\Framework\TestCase;

class ImagesModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── permission_callback ────────────────────────────────────────────────────

	public function test_permission_callback_returns_false_when_usage_limit_exceeded(): void {
		$captured_args = [];

		Functions\when( 'register_rest_route' )->alias(
			function( $namespace, $route, $args ) use ( &$captured_args ) {
				$captured_args[ $route ] = $args;
			}
		);

		ImagesModule::register_routes();

		$this->assertArrayHasKey( '/images/generate', $captured_args );
		$permission_callback = $captured_args['/images/generate']['permission_callback'];

		// User has permission but is over the free monthly limit.
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function( $user_id, $key, $single ) use ( $month_key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				if ( $month_key === $key ) {
					return '60000'; // over 50k free limit
				}
				return '';
			}
		);

		$this->assertFalse( (bool) $permission_callback() );
	}

	// ── permission_callback with usage within limit ───────────────────────────

	public function test_permission_callback_returns_true_with_capability_and_usage_within_limit(): void {
		$captured_args = [];

		Functions\when( 'register_rest_route' )->alias(
			function( $namespace, $route, $args ) use ( &$captured_args ) {
				$captured_args[ $route ] = $args;
			}
		);

		ImagesModule::register_routes();

		$permission_callback = $captured_args['/images/generate']['permission_callback'];

		// User has permission and has tokens remaining.
		// trial tier is required — free tier has images: false in NJ_Tier_Config.
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function( $user_id, $key, $single ) use ( $month_key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'trial';
				}
				if ( $month_key === $key ) {
					return '0'; // well under 300k trial limit
				}
				return '';
			}
		);

		$this->assertTrue( (bool) $permission_callback() );
	}
}

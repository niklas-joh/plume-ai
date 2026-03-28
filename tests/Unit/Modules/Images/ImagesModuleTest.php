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

	public function test_permission_callback_returns_false_without_pro(): void {
		$captured_args = [];

		Functions\when( 'register_rest_route' )->alias(
			function( $namespace, $route, $args ) use ( &$captured_args ) {
				$captured_args[ $route ] = $args;
			}
		);

		ImagesModule::register_routes();

		$this->assertArrayHasKey( '/images/generate', $captured_args );
		$permission_callback = $captured_args['/images/generate']['permission_callback'];

		// Without pro: wp_ai_mind_is_pro returns false, current_user_can returns true.
		Functions\when( 'wp_ai_mind_is_pro' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );

		$result = $permission_callback();

		$this->assertFalse( (bool) $result );
	}

	// ── permission_callback with pro enabled ───────────────────────────────────

	public function test_permission_callback_returns_true_with_pro_and_capability(): void {
		$captured_args = [];

		Functions\when( 'register_rest_route' )->alias(
			function( $namespace, $route, $args ) use ( &$captured_args ) {
				$captured_args[ $route ] = $args;
			}
		);

		ImagesModule::register_routes();

		$permission_callback = $captured_args['/images/generate']['permission_callback'];

		// With pro active and capability granted.
		Functions\when( 'wp_ai_mind_is_pro' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$result = $permission_callback();

		$this->assertTrue( (bool) $result );
	}
}

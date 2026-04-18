<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Modules\Usage;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Modules\Usage\UsageModule;
use PHPUnit\Framework\TestCase;

class UsageModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_hooks_admin_enqueue_scripts(): void {
		UsageModule::register();
		self::assertSame(
			10,
			has_action( 'admin_enqueue_scripts', [ UsageModule::class, 'enqueue_assets' ] )
		);
	}

	public function test_register_hooks_rest_api_init(): void {
		UsageModule::register();
		self::assertSame(
			10,
			has_action( 'rest_api_init', [ UsageModule::class, 'register_routes' ] )
		);
	}

	public function test_get_usage_returns_expected_shape(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->justReturn( '1200' );

		$request  = new \WP_REST_Request();
		$response = UsageModule::get_usage( $request );
		$data     = $response->data;

		self::assertArrayHasKey( 'tier',      $data );
		self::assertArrayHasKey( 'used',      $data );
		self::assertArrayHasKey( 'limit',     $data );
		self::assertArrayHasKey( 'remaining', $data );
		self::assertArrayHasKey( 'can_use',   $data );
		self::assertSame( 1200, $data['used'] );
	}
}

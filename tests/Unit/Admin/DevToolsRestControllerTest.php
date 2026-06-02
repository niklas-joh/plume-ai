<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Admin\DevToolsRestController;

// Ensure the constant is defined so DevToolsPage::is_active() can be exercised.
if ( ! defined( 'WP_AI_MIND_DEV_KEY' ) ) {
	define( 'WP_AI_MIND_DEV_KEY', 'test-dev-key' );
}

class DevToolsRestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── check_permission() ────────────────────────────────────────────────────

	public function test_check_permission_returns_wp_error_when_dev_tools_inactive(): void {
		// Dev tools are inactive when the current user lacks manage_options.
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->returnArg();

		$result = DevToolsRestController::check_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_check_permission_returns_true_when_dev_tools_active(): void {
		$stored_hash = hash_hmac( 'sha256', 'test-dev-key', 'test-salt' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) use ( $stored_hash ) {
				if ( 'wp_ai_mind_dev_key_hash' === $key ) {
					return $stored_hash;
				}
				return $default;
			}
		);

		$result = DevToolsRestController::check_permission();

		$this->assertTrue( $result );
	}

	// ── handle_set_ceiling() ──────────────────────────────────────────────────

	public function test_handle_set_ceiling_returns_success_message_for_unlimited_tier(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// pro_byok is the site-wide tier; no sync secret means verification passes.
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) {
				if ( 'wp_ai_mind_site_tier' === $key ) {
					return 'pro_byok';
				}
				// Return '' for OPTION_SECRET so is_site_tier_verified() returns true.
				return $default;
			}
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( '__' )->returnArg();

		$response = DevToolsRestController::handle_set_ceiling();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->data['success'] );
		$this->assertStringContainsString( 'unlimited', strtolower( $response->data['message'] ) );
	}

	public function test_handle_set_ceiling_sets_usage_to_tier_ceiling_for_limited_tier(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		// No site-wide paid tier → resolves to free (limit = 50 000).
		Functions\when( 'get_option' )->alias( fn( $key, $default = null ) => $default );
		Functions\when( 'get_user_meta' )->alias(
			function ( int $uid, string $key ): string {
				return 'wp_ai_mind_tier' === $key ? '' : '';
			}
		);
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 2, \Mockery::type( 'string' ), 50000 )
			->andReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => (string) number_format( (int) $n ) );

		$response = DevToolsRestController::handle_set_ceiling();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->data['success'] );
	}
}

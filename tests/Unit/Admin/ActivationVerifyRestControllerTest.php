<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Admin\ActivationVerifyRestController;

class ActivationVerifyRestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Route registration ──────────────────────────────────────────────────────

	public function test_register_routes_registers_activation_verify_endpoint(): void {
		$registered_ns    = null;
		$registered_route = null;

		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route ) use ( &$registered_ns, &$registered_route ) {
				$registered_ns    = $ns;
				$registered_route = $route;
			}
		);

		ActivationVerifyRestController::register_routes();

		$this->assertSame( 'wp-ai-mind/v1', $registered_ns );
		$this->assertSame( '/activation-verify', $registered_route );
	}

	// ── store_challenge ─────────────────────────────────────────────────────────

	public function test_store_challenge_sets_transient_with_challenge_key(): void {
		$captured_key = null;

		Functions\when( 'set_transient' )->alias(
			function ( string $key ) use ( &$captured_key ) {
				$captured_key = $key;
			}
		);

		ActivationVerifyRestController::store_challenge( 'abc123' );

		$this->assertStringContainsString( 'abc123', $captured_key );
		$this->assertStringStartsWith( 'wp_ai_mind_challenge_', $captured_key );
	}

	// ── handle — challenge found ────────────────────────────────────────────────

	public function test_handle_returns_200_when_challenge_transient_exists(): void {
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'delete_transient' )->justReturn( true );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'challenge', str_repeat( 'a', 64 ) );

		$response = ActivationVerifyRestController::handle( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->data['verified'] );
	}

	public function test_handle_deletes_transient_after_successful_verification(): void {
		$challenge    = str_repeat( 'a', 64 );
		$deleted_key  = null;

		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ) use ( &$deleted_key ) {
				$deleted_key = $key;
			}
		);

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'challenge', $challenge );

		ActivationVerifyRestController::handle( $request );

		$this->assertSame( 'wp_ai_mind_challenge_' . $challenge, $deleted_key );
	}

	// ── handle — challenge missing ──────────────────────────────────────────────

	public function test_handle_returns_403_when_challenge_transient_is_absent(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'challenge', str_repeat( 'b', 64 ) );

		$response = ActivationVerifyRestController::handle( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->data );
	}

	// ── handle — get_transient uses the correct key ─────────────────────────────

	public function test_handle_looks_up_transient_with_correct_prefixed_key(): void {
		$challenge    = str_repeat( 'c', 64 );
		$captured_key = null;

		Functions\when( 'get_transient' )->alias(
			function ( string $key ) use ( &$captured_key ) {
				$captured_key = $key;
				return 1;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'challenge', $challenge );

		ActivationVerifyRestController::handle( $request );

		$this->assertSame( 'wp_ai_mind_challenge_' . $challenge, $captured_key );
	}
}

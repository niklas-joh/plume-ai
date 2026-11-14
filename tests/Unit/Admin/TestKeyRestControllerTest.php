<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Admin\TestKeyRestController;
use PHPUnit\Framework\TestCase;

/**
 * Extend WP_Error stub to add get_error_message() for the request_failed path.
 * The bootstrap WP_Error stub omits this method, so we add it here.
 */
if ( ! class_exists( 'TestWpError' ) ) {
	class TestWpError extends \WP_Error {
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

class TestKeyRestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Route registration ──────────────────────────────────────────────────────

	public function test_register_routes_registers_test_key_endpoint(): void {
		$registered_ns    = null;
		$registered_route = null;

		Functions\when( 'register_rest_route' )->alias(
			function( $ns, $route ) use ( &$registered_ns, &$registered_route ) {
				$registered_ns    = $ns;
				$registered_route = $route;
			}
		);

		TestKeyRestController::register_routes();

		$this->assertSame( 'plume/v1', $registered_ns );
		$this->assertSame( '/test-key', $registered_route );
	}

	// ── Permission check ────────────────────────────────────────────────────────

	public function test_check_permission_returns_true_for_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$result = TestKeyRestController::check_permission();

		$this->assertTrue( $result );
	}

	public function test_check_permission_returns_wp_error_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = TestKeyRestController::check_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->code );
	}

	// ── Unsupported provider (direct call bypasses REST enum validation) ────────

	public function test_handle_returns_wp_error_for_unsupported_provider(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'provider', 'unknown' );
		$request->set_param( 'api_key', 'key' );

		$result = TestKeyRestController::handle( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unsupported_provider', $result->code );
	}

	// ── OpenAI — valid key ──────────────────────────────────────────────────────

	public function test_openai_valid_key_returns_200(): void {
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 200 ], 'body' => '' ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'provider', 'openai' );
		$request->set_param( 'api_key', 'sk-valid' );

		$response = TestKeyRestController::handle( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->data['success'] );
	}

	// ── OpenAI — invalid key ────────────────────────────────────────────────────

	public function test_openai_invalid_key_returns_wp_error(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			[ 'response' => [ 'code' => 401 ], 'body' => '{"error":{"message":"Invalid key"}}' ]
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":{"message":"Invalid key"}}' );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'provider', 'openai' );
		$request->set_param( 'api_key', 'sk-invalid' );

		$result = TestKeyRestController::handle( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_key', $result->code );
	}

	// ── Rejected key vs. check that could not run ───────────────────────────────

	/**
	 * Run the handler against a stubbed upstream response.
	 *
	 * @since NEXT_VERSION
	 * @param string $provider Provider slug.
	 * @param int    $status   HTTP status the provider returns.
	 * @param string $body     Raw response body the provider returns.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function handle_with_upstream( string $provider, int $status, string $body ) {
		$response = [ 'response' => [ 'code' => $status ], 'body' => $body ];
		Functions\when( 'wp_remote_get' )->justReturn( $response );
		Functions\when( 'wp_remote_post' )->justReturn( $response );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'apply_filters' )->alias( fn( $hook, $value ) => $value );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'provider', $provider );
		$request->set_param( 'api_key', 'key-under-test' );

		return TestKeyRestController::handle( $request );
	}

	/**
	 * Only an authentication rejection means the key itself is wrong.
	 *
	 * @since NEXT_VERSION
	 * @dataProvider rejected_key_responses
	 * @param string $provider Provider slug.
	 * @param int    $status   Upstream HTTP status.
	 * @param string $body     Upstream body.
	 * @return void
	 */
	public function test_authentication_rejection_is_reported_as_invalid_key( string $provider, int $status, string $body ): void {
		$result = $this->handle_with_upstream( $provider, $status, $body );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_key', $result->code );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Upstream responses that mean "this key was rejected".
	 *
	 * @since NEXT_VERSION
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function rejected_key_responses(): array {
		return [
			'openai 401'                => [ 'openai', 401, '{"error":{"message":"Incorrect API key provided"}}' ],
			'claude 401'                => [ 'claude', 401, '{"error":{"message":"invalid x-api-key"}}' ],
			'claude 403'                => [ 'claude', 403, '{"error":{"message":"forbidden"}}' ],
			'gemini 400 API_KEY_INVALID' => [ 'gemini', 400, '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT","details":[{"reason":"API_KEY_INVALID"}]}}' ],
		];
	}

	/**
	 * A provider outage, rate limit, billing problem or retired probe model says
	 * nothing about the key, so it must not be reported as an invalid key: the
	 * settings screen refuses to save a key reported as invalid.
	 *
	 * @since NEXT_VERSION
	 * @dataProvider unverifiable_responses
	 * @param string $provider Provider slug.
	 * @param int    $status   Upstream HTTP status.
	 * @param string $body     Upstream body.
	 * @return void
	 */
	public function test_other_failures_are_reported_as_verification_failed( string $provider, int $status, string $body ): void {
		$result = $this->handle_with_upstream( $provider, $status, $body );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'verification_failed', $result->code );
		$this->assertSame( 502, $result->get_error_data()['status'] );
	}

	/**
	 * Upstream responses that do not tell us whether the key is valid.
	 *
	 * @since NEXT_VERSION
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function unverifiable_responses(): array {
		return [
			'openai rate limited'      => [ 'openai', 429, '{"error":{"message":"Rate limit reached"}}' ],
			'openai outage'            => [ 'openai', 503, '' ],
			'claude overloaded'        => [ 'claude', 529, '{"error":{"message":"Overloaded"}}' ],
			'claude low balance'       => [ 'claude', 400, '{"error":{"message":"Your credit balance is too low"}}' ],
			'claude retired model'     => [ 'claude', 404, '{"error":{"message":"model: claude-haiku-4-5-20251001"}}' ],
			'gemini bad request other' => [ 'gemini', 400, '{"error":{"code":400,"message":"Invalid page size","status":"INVALID_ARGUMENT"}}' ],
		];
	}

	// ── Gemini — security regression: key must be in header, not URL ────────────

	public function test_gemini_sends_api_key_as_header_not_query_param(): void {
		$captured_url  = null;
		$captured_args = null;

		Functions\when( 'wp_remote_get' )->alias(
			function( $url, $args ) use ( &$captured_url, &$captured_args ) {
				$captured_url  = $url;
				$captured_args = $args;
				return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'provider', 'gemini' );
		$request->set_param( 'api_key', 'AIza_testkey' );

		TestKeyRestController::handle( $request );

		$this->assertSame( 'AIza_testkey', $captured_args['headers']['x-goog-api-key'] );
		$this->assertFalse(
			str_contains( (string) $captured_url, 'AIza_testkey' ),
			'API key must not appear in the URL (exposure in server logs).'
		);
	}

	// ── HTTP transport error → request_failed ───────────────────────────────────

	public function test_wp_error_from_http_call_returns_request_failed(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			new TestWpError( 'http_request_failed', 'Could not connect' )
		);
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'provider', 'openai' );
		$request->set_param( 'api_key', 'key' );

		$result = TestKeyRestController::handle( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'request_failed', $result->code );
	}
}

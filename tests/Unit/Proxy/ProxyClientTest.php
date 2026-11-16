<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Proxy\ProxyClient;
use Plume\Proxy\SiteRegistration;
use Plume\Tests\Helpers\WpdbStubFactory;

class ProxyClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	/**
	 * Stub a registered site's options. chat() reads both the token and the
	 * usage-statistics opt-out, so a single-key expectation would not do.
	 *
	 * @param mixed $share Stored value of the opt-out option, or null when unset.
	 * @return void
	 */
	private function stub_site_options( mixed $share = null ): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) use ( $share ) {
				if ( SiteRegistration::OPTION_TOKEN === $key ) {
					return 'test-token';
				}
				if ( ProxyClient::OPTION_SHARE_USAGE_STATS === $key && null !== $share ) {
					return $share;
				}
				return $fallback;
			}
		);
	}

	protected function tearDown(): void {
		// Restore a valid $wpdb baseline after any test that installs a Mockery mock.
		global $wpdb;
		$wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_chat_returns_error_when_not_registered(): void {
		// SiteRegistration::get_unavailable_error() makes a second, differently-keyed
		// get_option() call (OPTION_PERMANENT_FAILURE) — Functions\when()->alias() tolerates
		// that extra call, unlike a strict Functions\expect()->with() expectation.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => SiteRegistration::OPTION_TOKEN === $key ? '' : $default
		);
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->code );
	}

	public function test_chat_schedules_shutdown_hook_when_not_registered_and_no_existing_action(): void {
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => SiteRegistration::OPTION_TOKEN === $key ? '' : $default
		);
		Functions\expect( 'has_action' )
			->once()
			->with( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] )
			->andReturn( false );
		Functions\expect( 'add_action' )
			->once()
			->with( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->get_error_code() );
	}

	public function test_chat_does_not_schedule_duplicate_shutdown_hook_when_already_registered(): void {
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => SiteRegistration::OPTION_TOKEN === $key ? '' : $default
		);
		Functions\expect( 'has_action' )
			->once()
			->with( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] )
			->andReturn( true );
		Functions\expect( 'add_action' )->never();
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->get_error_code() );
	}

	public function test_chat_does_not_pre_check_usage_limit_before_calling_worker(): void {
		// Worker's KV is the sole source of truth for credit enforcement now — the old
		// fail-fast WordPress-meta pre-check via UsageTracker::check_limit() is gone.
		// Simulate a registered site whose cached local usage meta would have failed the
		// old pre-check, and confirm the request still reaches wp_remote_post().
		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\expect( 'wp_remote_post' )->once()->andReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'content' => 'hello' ] ) );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertIsArray( $result );
	}

	public function test_chat_clears_token_and_returns_error_on_401(): void {
		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\expect( 'delete_option' )
			->once()
			->with( SiteRegistration::OPTION_TOKEN );
		Functions\expect( 'has_action' )
			->once()
			->with( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] )
			->andReturn( false );
		Functions\expect( 'add_action' )
			->once()
			->with( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'auth_failed', $result->get_error_code() );
	}

	public function test_chat_returns_rate_limit_error_on_429(): void {
		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * @dataProvider provider_unavailable_responses
	 */
	public function test_chat_returns_neutral_error_when_upstream_provider_fails( int $status, string $body ): void {
		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $status );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'provider_unavailable', $result->get_error_code() );
		$this->assertStringNotContainsString( 'Internal proxy error', $result->get_error_message() );
	}

	/**
	 * @return array<string, array{int, string}>
	 */
	public static function provider_unavailable_responses(): array {
		return [
			'new worker code'  => [ 503, '{"error":"This AI model is temporarily unavailable.","code":"provider_unavailable"}' ],
			'legacy worker 500' => [ 500, '{"error":"Internal proxy error"}' ],
		];
	}

	public function test_chat_returns_response_body_on_success(): void {
		// Proxy normalises content to a flat string — NOT a Claude block array.
		$body = json_encode(
			[
				'content' => 'hello',
				'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
			]
		);

		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertIsArray( $result );
		$this->assertSame( 'hello', $result['content'] );
		$this->assertArrayHasKey( 'usage', $result );
		$this->assertSame( 10, $result['usage']['input_tokens'] );
		$this->assertSame( 5, $result['usage']['output_tokens'] );
	}

	public function test_chat_returns_tool_call_when_proxy_relays_one(): void {
		$body = json_encode(
			[
				'content'   => "I'll fetch that for you.",
				'usage'     => [ 'input_tokens' => 20, 'output_tokens' => 8 ],
				'tool_call' => [ 'id' => 'toolu_01', 'name' => 'get_post_content', 'arguments' => [ 'post_id' => 42 ] ],
			]
		);

		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'Summarise post 42' ] ], 'chat' );

		$this->assertIsArray( $result );
		$this->assertSame( "I'll fetch that for you.", $result['content'] );
		$this->assertArrayHasKey( 'tool_call', $result );
		$this->assertSame( 'toolu_01', $result['tool_call']['id'] );
		$this->assertSame( 'get_post_content', $result['tool_call']['name'] );
		$this->assertSame( [ 'post_id' => 42 ], $result['tool_call']['arguments'] );
	}

	public function test_chat_logs_credits_charged_for_non_chat_features(): void {
		// Non-chat features (generator, seo, image) must still be logged by ProxyClient.
		$body = json_encode( [ 'content' => 'hello', 'credits_charged' => 10 ] );

		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		global $wpdb;
		$wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta      = 'wp_usermeta';
		$wpdb->rows_affected = 1;
		$wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'generator' );

		$this->addToAssertionCount( 1 );
	}

	public function test_chat_skips_credit_logging_for_chat_feature(): void {
		// Chat credits are logged once by ChatRestController after the full agentic loop,
		// so ProxyClient must not call log_usage() for feature='chat'.
		$body = json_encode( [ 'content' => 'hello', 'credits_charged' => 3 ] );

		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		global $wpdb;
		$wpdb           = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta = 'wp_usermeta';
		$wpdb->shouldReceive( 'query' )->never();

		ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->addToAssertionCount( 1 );
	}

	public function test_chat_skips_usage_mirror_when_usage_absent_from_response(): void {
		// Proxy normalises content to a flat string — NOT a Claude block array.
		$body = json_encode( [ 'content' => 'hello' ] );

		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		// add_user_meta must NOT be called — usage mirroring requires credits_charged in the response.
		Functions\expect( 'add_user_meta' )->never();

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'usage', $result );
	}

	public function test_chat_sets_feature_in_payload_unconditionally(): void {
		$captured_body = null;

		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->alias( function ( $url, $args ) use ( &$captured_body ) {
			$captured_body = json_decode( $args['body'], true );
			return [];
		} );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'content' => 'hello' ] ) );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'generator' );

		$this->assertNotNull( $captured_body );
		$this->assertSame( 'generator', $captured_body['feature'] );
	}

	/**
	 * Stub a registered site and capture what chat() sends to wp_remote_post().
	 *
	 * @param array|\WP_Error $response  What wp_remote_post() returns.
	 * @param array|null       $sent_args Receives the request args.
	 * @return void
	 */
	private function stub_registered_post( array|\WP_Error $response, ?array &$sent_args = null ): void {
		$this->stub_site_options();
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$sent_args, $response ) {
				$sent_args = $args;
				return $response;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'content' => 'hello' ] ) );
		Functions\when( '__' )->alias( fn( $s ) => $s );
	}

	public function test_chat_uses_the_default_timeout(): void {
		$this->stub_registered_post( [], $sent );

		ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertSame( PLUME_HTTP_TIMEOUT, $sent['timeout'] );
	}

	public function test_chat_honours_the_timeout_option_without_sending_it_to_the_worker(): void {
		$this->stub_registered_post( [], $sent );

		ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'generator', [ 'timeout' => 120 ] );

		$this->assertSame( 120, $sent['timeout'] );
		$this->assertArrayNotHasKey( 'timeout', json_decode( $sent['body'], true ) );
	}

	public function test_chat_maps_a_curl_timeout_to_a_request_timeout_error(): void {
		$this->stub_registered_post( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received' ) );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'request_timeout', $result->get_error_code() );
		$this->assertStringNotContainsString( 'cURL', $result->get_error_message() );
	}

	public function test_chat_leaves_other_transport_errors_untouched(): void {
		$this->stub_registered_post( new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ) );

		$result = ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'http_request_failed', $result->get_error_code() );
	}

	// ── Usage-statistics opt-out (#1015) ────────────────────────────────────────

	/**
	 * Send a chat request with the given stored opt-out value and return the
	 * headers chat() sends.
	 *
	 * @param mixed $share Stored value of the opt-out option, or null when unset.
	 * @return array<string, string>
	 */
	private function chat_headers_with_share_option( mixed $share ): array {
		$this->stub_site_options( $share );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		$sent = [];
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$sent ) {
				$sent = $args;
				return [];
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'content' => 'hello' ] ) );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		ProxyClient::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ], 'chat' );

		return $sent['headers'];
	}

	public function test_chat_sends_no_stats_header_when_site_opted_out(): void {
		// update_option( false ) is read back by WordPress as an empty string.
		$headers = $this->chat_headers_with_share_option( '' );

		$this->assertSame( '1', $headers['X-Plume-No-Stats'] ?? null );
		$this->assertSame( 'Bearer test-token', $headers['Authorization'] );
	}

	public function test_chat_omits_no_stats_header_when_sharing_is_on(): void {
		$this->assertArrayNotHasKey( 'X-Plume-No-Stats', $this->chat_headers_with_share_option( '1' ) );
	}

	public function test_chat_omits_no_stats_header_when_option_never_saved(): void {
		$this->assertArrayNotHasKey( 'X-Plume-No-Stats', $this->chat_headers_with_share_option( null ) );
	}
}

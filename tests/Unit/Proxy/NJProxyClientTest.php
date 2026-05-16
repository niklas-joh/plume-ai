<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Proxy\NJ_Proxy_Client;
use WP_AI_Mind\Proxy\NJ_Site_Registration;

class NJProxyClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_chat_returns_error_when_not_registered(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->code );
	}

	public function test_chat_schedules_shutdown_hook_when_not_registered_and_no_existing_action(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );
		Functions\expect( 'has_action' )
			->once()
			->with( 'shutdown', [ NJ_Site_Registration::class, 'maybe_register' ] )
			->andReturn( false );
		Functions\expect( 'add_action' )
			->once()
			->with( 'shutdown', [ NJ_Site_Registration::class, 'maybe_register' ] );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->get_error_code() );
	}

	public function test_chat_does_not_schedule_duplicate_shutdown_hook_when_already_registered(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );
		Functions\expect( 'has_action' )
			->once()
			->with( 'shutdown', [ NJ_Site_Registration::class, 'maybe_register' ] )
			->andReturn( true );
		Functions\expect( 'add_action' )->never();
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->get_error_code() );
	}

	public function test_chat_returns_error_when_usage_limit_exceeded(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'test-token' );
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		// get_user_meta: tier=free, usage above the 50 000 free limit.
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				return 51000;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_chat_clears_token_and_returns_error_on_401(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'test-token' );
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				return 0;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\expect( 'delete_option' )
			->once()
			->with( NJ_Site_Registration::OPTION_TOKEN );
		Functions\expect( 'has_action' )
			->once()
			->with( 'shutdown', [ NJ_Site_Registration::class, 'maybe_register' ] )
			->andReturn( false );
		Functions\expect( 'add_action' )
			->once()
			->with( 'shutdown', [ NJ_Site_Registration::class, 'maybe_register' ] );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'proxy_auth_failed', $result->get_error_code() );
	}

	public function test_chat_returns_rate_limit_error_on_429(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'test-token' );
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				return 0;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_chat_returns_response_body_on_success(): void {
		// Proxy normalises content to a flat string — NOT a Claude block array.
		$body = json_encode(
			[
				'content' => 'hello',
				'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
			]
		);

		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'test-token' );
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				return 0;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

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

		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'test-token' );
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				return 0;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'Summarise post 42' ] ] );

		$this->assertIsArray( $result );
		$this->assertSame( "I'll fetch that for you.", $result['content'] );
		$this->assertArrayHasKey( 'tool_call', $result );
		$this->assertSame( 'toolu_01', $result['tool_call']['id'] );
		$this->assertSame( 'get_post_content', $result['tool_call']['name'] );
		$this->assertSame( [ 'post_id' => 42 ], $result['tool_call']['arguments'] );
	}

	public function test_chat_skips_usage_mirror_when_usage_absent_from_response(): void {
		// Proxy normalises content to a flat string — NOT a Claude block array.
		$body = json_encode( [ 'content' => 'hello' ] );

		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'test-token' );
		Functions\expect( 'get_current_user_id' )->andReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $user_id, $key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				return 0;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		// add_user_meta must NOT be called — usage mirroring requires both token counts.
		Functions\expect( 'add_user_meta' )->never();

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'usage', $result );
	}
}

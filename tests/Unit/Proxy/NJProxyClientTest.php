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

		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->code );
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

	/**
	 * @covers \WP_AI_Mind\Proxy\NJ_Proxy_Client::chat
	 */
	public function test_chat_clears_token_and_returns_error_on_401_when_reregistration_fails(): void {
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
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		// All response code calls return 401 — registration will also fail with 401.
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 401 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\expect( 'delete_option' )
			->once()
			->with( NJ_Site_Registration::OPTION_TOKEN );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'proxy_auth_failed', $result->get_error_code() );
	}

	/**
	 * @covers \WP_AI_Mind\Proxy\NJ_Proxy_Client::chat
	 */
	public function test_chat_reregisters_inline_on_401_and_retries_successfully(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'stale-token' );
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
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

		// Sequence: first chat call → 401, register call → 201, retry chat → 200.
		$codes = [ 401, 201, 200 ];
		$idx   = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$codes, &$idx ) {
				return $codes[ $idx++ ] ?? 200;
			}
		);

		$success_body = json_encode( [
			'content' => [ [ 'type' => 'text', 'text' => 'hello' ] ],
			'usage'   => [ 'input_tokens' => 5, 'output_tokens' => 3 ],
		] );
		$bodies = [ '{}', json_encode( [ 'token' => 'new-token' ] ), $success_body ];
		$bidx   = 0;
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () use ( &$bodies, &$bidx ) {
				return $bodies[ $bidx++ ] ?? '{}';
			}
		);

		Functions\expect( 'delete_option' )->once()->with( NJ_Site_Registration::OPTION_TOKEN );
		Functions\expect( 'update_option' )->once()->with( NJ_Site_Registration::OPTION_TOKEN, 'new-token' );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [ [ 'role' => 'user', 'content' => 'hi' ] ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'content', $result );
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
		$body = json_encode(
			[
				'content' => [ [ 'type' => 'text', 'text' => 'hello' ] ],
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
		$this->assertArrayHasKey( 'usage', $result );
		$this->assertSame( 10, $result['usage']['input_tokens'] );
		$this->assertSame( 5, $result['usage']['output_tokens'] );
	}

	public function test_chat_skips_usage_mirror_when_usage_absent_from_response(): void {
		$body = json_encode( [ 'content' => [ [ 'type' => 'text', 'text' => 'hello' ] ] ] );

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

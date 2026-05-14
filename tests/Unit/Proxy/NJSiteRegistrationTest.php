<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Proxy\NJ_Site_Registration;

class NJSiteRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Accessors ───────────────────────────────────────────────────────────────

	public function test_get_site_token_returns_stored_token(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'abc123' );

		$this->assertSame( 'abc123', NJ_Site_Registration::get_site_token() );
	}

	public function test_get_site_token_returns_empty_string_when_not_registered(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );

		$this->assertSame( '', NJ_Site_Registration::get_site_token() );
	}

	public function test_is_registered_returns_true_when_token_exists(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'some-token' );

		$this->assertTrue( NJ_Site_Registration::is_registered() );
	}

	public function test_is_registered_returns_false_when_no_token(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );

		$this->assertFalse( NJ_Site_Registration::is_registered() );
	}

	public function test_checkout_url_embeds_site_token(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'mytoken' );

		$url = NJ_Site_Registration::checkout_url_pro_managed_monthly();

		$this->assertStringContainsString( 'lemonsqueezy.com', $url );
		$this->assertStringContainsString( 'mytoken', $url );
	}

	// ── register() — challenge fetch failure ────────────────────────────────────

	public function test_register_returns_wp_error_when_challenge_request_fails(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			new \WP_Error( 'http_request_failed', 'Connection refused' )
		);
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );

		$result = NJ_Site_Registration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_register_returns_wp_error_when_challenge_response_is_not_200(): void {
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		$result = NJ_Site_Registration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'challenge_failed', $result->get_error_code() );
	}

	public function test_register_returns_wp_error_when_challenge_body_has_no_challenge_key(): void {
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"other":"value"}' );

		$result = NJ_Site_Registration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'challenge_failed', $result->get_error_code() );
	}

	// ── register() — registration request failure ───────────────────────────────

	public function test_register_returns_wp_error_when_registration_post_fails(): void {
		$challenge = str_repeat( 'a', 64 );

		// Challenge fetch succeeds.
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"challenge":"' . $challenge . '"}' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://mysite.example.com' );
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );

		// Registration POST fails.
		Functions\when( 'wp_remote_post' )->justReturn(
			new \WP_Error( 'http_request_failed', 'Connection refused' )
		);
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );

		$result = NJ_Site_Registration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_register_returns_wp_error_when_registration_returns_non_2xx(): void {
		$challenge = str_repeat( 'b', 64 );

		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		// First body call returns the challenge, second returns the registration failure.
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			( function () use ( $challenge ) {
				$calls = 0;
				return function () use ( $challenge, &$calls ): string {
					++$calls;
					return 1 === $calls
						? '{"challenge":"' . $challenge . '"}'
						: '{"error":"site verification failed"}';
				};
			} )()
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://mysite.example.com' );
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );

		// First call 200 (challenge), second call 403 (register).
		$callNum = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$callNum ): int {
				++$callNum;
				return 1 === $callNum ? 200 : 403;
			}
		);

		$result = NJ_Site_Registration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'registration_failed', $result->get_error_code() );
	}

	// ── register() — happy path ─────────────────────────────────────────────────

	public function test_register_stores_token_and_returns_it_on_success(): void {
		$challenge     = str_repeat( 'c', 64 );
		$token         = str_repeat( 'd', 64 );
		$captured_args = null;

		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return [];
			}
		);
		$callNum = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$callNum ): int {
				return 1 === ++$callNum ? 200 : 201;
			}
		);
		$bodyCallNum = 0;
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () use ( &$bodyCallNum, $challenge, $token ): string {
				return 1 === ++$bodyCallNum
					? '{"challenge":"' . $challenge . '"}'
					: '{"token":"' . $token . '","tier":"trial"}';
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://mysite.example.com' );
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		// set_site_tier() reads the sync secret to sign the stored tier value.
		Functions\when( 'get_option' )->justReturn( '' );

		$result = NJ_Site_Registration::register();

		$this->assertSame( $token, $result );

		// Confirm challenge_token is forwarded in the registration body.
		$body = json_decode( $captured_args['body'], true );
		$this->assertSame( $challenge, $body['challenge_token'] );
		$this->assertSame( 'https://mysite.example.com', $body['site_url'] );
	}

	// ── register() — Worker now supplies tier_sync_secret and tier ──────────

	public function test_register_stores_tier_sync_secret_and_site_tier_when_worker_supplies_them(): void {
		$challenge = str_repeat( 'e', 64 );
		$token     = str_repeat( 'f', 64 );
		$secret    = str_repeat( '9', 64 );

		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( [] );

		$rcCalls = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$rcCalls ): int {
				return 1 === ++$rcCalls ? 200 : 201;
			}
		);

		$bodyCalls = 0;
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () use ( &$bodyCalls, $challenge, $token, $secret ): string {
				return 1 === ++$bodyCalls
					? '{"challenge":"' . $challenge . '"}'
					: '{"token":"' . $token . '","tier":"pro_managed","tier_sync_secret":"' . $secret . '"}';
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://mysite.example.com' );
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );
		Functions\when( 'do_action' )->justReturn( null );
		// set_site_tier() reads the sync secret to sign the stored tier value.
		Functions\when( 'get_option' )->justReturn( '' );

		$captured = [];
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) use ( &$captured ) {
				$captured[ $key ] = [ 'value' => $value, 'autoload' => $autoload ];
				return true;
			}
		);

		$result = NJ_Site_Registration::register();

		$this->assertSame( $token, $result );
		$this->assertSame( $secret, $captured[ NJ_Site_Registration::OPTION_SECRET ]['value'] );
		$this->assertFalse( $captured[ NJ_Site_Registration::OPTION_SECRET ]['autoload'] );
		// Tier should also have been persisted via set_site_tier().
		$this->assertSame( 'pro_managed', $captured['wp_ai_mind_site_tier']['value'] );
	}

	public function test_register_succeeds_without_secret_or_tier_for_legacy_workers(): void {
		$challenge = str_repeat( 'a', 64 );
		$token     = str_repeat( 'b', 64 );

		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( [] );

		$rcCalls = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$rcCalls ): int {
				return 1 === ++$rcCalls ? 200 : 201;
			}
		);

		$bodyCalls = 0;
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function () use ( &$bodyCalls, $challenge, $token ): string {
				return 1 === ++$bodyCalls
					? '{"challenge":"' . $challenge . '"}'
					: '{"token":"' . $token . '"}'; // legacy: token only.
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://mysite.example.com' );
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );

		$captured = [];
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);

		$result = NJ_Site_Registration::register();

		$this->assertSame( $token, $result );
		// Only the token option must have been written — no secret, no tier.
		$this->assertArrayHasKey( NJ_Site_Registration::OPTION_TOKEN, $captured );
		$this->assertArrayNotHasKey( NJ_Site_Registration::OPTION_SECRET, $captured );
		$this->assertArrayNotHasKey( 'wp_ai_mind_site_tier', $captured );
	}

	// ── rotate_secret() ─────────────────────────────────────────────────────

	public function test_rotate_secret_returns_wp_error_when_not_registered(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );
		Functions\when( '__' )->returnArg();

		$result = NJ_Site_Registration::rotate_secret();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->get_error_code() );
	}

	public function test_rotate_secret_returns_wp_error_on_non_200(): void {
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => NJ_Site_Registration::OPTION_TOKEN === $k ? 'site-token' : $d
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		$result = NJ_Site_Registration::rotate_secret();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rotate_failed', $result->get_error_code() );
	}

	public function test_rotate_secret_stores_new_secret_on_success(): void {
		$secret = str_repeat( '7', 64 );

		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => NJ_Site_Registration::OPTION_TOKEN === $k ? 'site-token' : $d
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			'{"tier_sync_secret":"' . $secret . '","tier":"pro_managed"}'
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );

		$captured = [];
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);

		$result = NJ_Site_Registration::rotate_secret();

		$this->assertSame( $secret, $result );
		$this->assertSame( $secret, $captured[ NJ_Site_Registration::OPTION_SECRET ] );
		$this->assertSame( 'pro_managed', $captured['wp_ai_mind_site_tier'] );
	}
}

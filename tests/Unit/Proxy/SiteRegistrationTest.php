<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Proxy\SiteRegistration;

class SiteRegistrationTest extends TestCase {

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
			->with( SiteRegistration::OPTION_TOKEN, '' )
			->andReturn( 'abc123' );

		$this->assertSame( 'abc123', SiteRegistration::get_site_token() );
	}

	public function test_get_site_token_returns_empty_string_when_not_registered(): void {
		Functions\expect( 'get_option' )
			->with( SiteRegistration::OPTION_TOKEN, '' )
			->andReturn( '' );

		$this->assertSame( '', SiteRegistration::get_site_token() );
	}

	public function test_is_registered_returns_true_when_token_exists(): void {
		Functions\expect( 'get_option' )
			->with( SiteRegistration::OPTION_TOKEN, '' )
			->andReturn( 'some-token' );

		$this->assertTrue( SiteRegistration::is_registered() );
	}

	public function test_is_registered_returns_false_when_no_token(): void {
		Functions\expect( 'get_option' )
			->with( SiteRegistration::OPTION_TOKEN, '' )
			->andReturn( '' );

		$this->assertFalse( SiteRegistration::is_registered() );
	}

	public function test_checkout_url_embeds_site_token(): void {
		Functions\expect( 'get_option' )
			->with( SiteRegistration::OPTION_TOKEN, '' )
			->andReturn( 'mytoken' );

		$url = SiteRegistration::checkout_url_pro_managed_monthly();

		$this->assertStringContainsString( 'lemonsqueezy.com', $url );
		$this->assertStringContainsString( 'mytoken', $url );
	}

	// ── register() — challenge fetch failure ────────────────────────────────────

	public function test_register_returns_wp_error_when_challenge_request_fails(): void {
		Functions\when( 'wp_remote_get' )->justReturn(
			new \WP_Error( 'http_request_failed', 'Connection refused' )
		);
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );

		$result = SiteRegistration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_register_returns_wp_error_when_challenge_response_is_not_200(): void {
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		$result = SiteRegistration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'challenge_failed', $result->get_error_code() );
	}

	public function test_register_returns_wp_error_when_challenge_body_has_no_challenge_key(): void {
		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"other":"value"}' );

		$result = SiteRegistration::register();

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

		$result = SiteRegistration::register();

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

		$result = SiteRegistration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'registration_failed', $result->get_error_code() );
	}

	// ── register() — permanent verification failures ─────────────────────────────

	/**
	 * Data provider: reason => expected substring unique to that reason's canned message.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function permanent_verification_reasons(): array {
		return [
			'network_error'      => [ 'network_error', 'locally' ],
			'tls_error'          => [ 'tls_error', 'certificate' ],
			'http_unauthorized'  => [ 'http_unauthorized', 'login wall' ],
			'http_forbidden'     => [ 'http_forbidden', 'firewall' ],
		];
	}

	/**
	 * @dataProvider permanent_verification_reasons
	 */
	public function test_register_returns_site_unreachable_for_permanent_verification_reasons( string $reason, string $expected_substring ): void {
		$challenge = str_repeat( 'a', 64 );

		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			( function () use ( $challenge, $reason ) {
				$calls = 0;
				return function () use ( $challenge, $reason, &$calls ): string {
					++$calls;
					return 1 === $calls
						? '{"challenge":"' . $challenge . '"}'
						: '{"error":"Site verification failed","reason":"' . $reason . '","status":403}';
				};
			} )()
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://mysite.example.com' );
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );
		Functions\when( '__' )->returnArg();

		$callNum = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$callNum ): int {
				++$callNum;
				return 1 === $callNum ? 200 : 403;
			}
		);

		$result = SiteRegistration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'site_unreachable', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertTrue( $data['permanent'] );
		$this->assertSame( $reason, $data['reason'] );
		$this->assertStringContainsString( $expected_substring, $result->get_error_message() );
	}

	/**
	 * Data provider: reason values that must NOT be treated as permanent.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function non_permanent_verification_reasons(): array {
		return [
			'no reason at all' => [ '' ],
			'timeout'          => [ 'timeout' ],
			'http_error'       => [ 'http_error' ],
		];
	}

	/**
	 * @dataProvider non_permanent_verification_reasons
	 */
	public function test_register_returns_generic_error_for_non_permanent_reasons( string $reason ): void {
		$challenge = str_repeat( 'b', 64 );

		Functions\when( 'wp_remote_get' )->justReturn( [] );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			( function () use ( $challenge, $reason ) {
				$calls = 0;
				return function () use ( $challenge, $reason, &$calls ): string {
					++$calls;
					if ( 1 === $calls ) {
						return '{"challenge":"' . $challenge . '"}';
					}
					return '' !== $reason
						? '{"error":"Site verification failed","reason":"' . $reason . '"}'
						: '{"error":"Site verification failed"}';
				};
			} )()
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://mysite.example.com' );
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );

		$callNum = 0;
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function () use ( &$callNum ): int {
				++$callNum;
				return 1 === $callNum ? 200 : 403;
			}
		);

		$result = SiteRegistration::register();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'registration_failed', $result->get_error_code() );
	}

	// ── permanent_failure_message() ───────────────────────────────────────────────

	public function test_permanent_failure_message_returns_fallback_for_unrecognised_reason(): void {
		Functions\when( '__' )->returnArg();

		$message = SiteRegistration::permanent_failure_message( 'something_unexpected' );

		$this->assertNotSame( '', $message );
	}

	// ── get_permanent_failure() / get_unavailable_error() ─────────────────────────

	public function test_get_permanent_failure_returns_null_when_none_stored(): void {
		Functions\when( 'get_option' )->justReturn( null );

		$this->assertNull( SiteRegistration::get_permanent_failure() );
	}

	public function test_get_permanent_failure_returns_null_when_stored_value_is_malformed(): void {
		Functions\when( 'get_option' )->justReturn( [ 'reason' => 'network_error' ] ); // missing 'message'.

		$this->assertNull( SiteRegistration::get_permanent_failure() );
	}

	public function test_get_permanent_failure_returns_stored_diagnostic(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'reason'  => 'tls_error',
				'message' => 'Certificate problem.',
			]
		);

		$failure = SiteRegistration::get_permanent_failure();

		$this->assertSame( 'tls_error', $failure['reason'] );
		$this->assertSame( 'Certificate problem.', $failure['message'] );
	}

	public function test_get_unavailable_error_returns_site_unreachable_when_permanent_failure_stored(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'reason'  => 'http_forbidden',
				'message' => 'Blocked by a firewall.',
			]
		);
		Functions\when( '__' )->returnArg();

		$error = SiteRegistration::get_unavailable_error();

		$this->assertSame( 'site_unreachable', $error->get_error_code() );
		$this->assertSame( 'Blocked by a firewall.', $error->get_error_message() );
		$this->assertSame( 'http_forbidden', $error->get_error_data()['reason'] );
	}

	public function test_get_unavailable_error_returns_not_registered_when_no_permanent_failure_stored(): void {
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( '__' )->returnArg();

		$error = SiteRegistration::get_unavailable_error();

		$this->assertSame( 'not_registered', $error->get_error_code() );
	}

	// ── record_registration_outcome() ─────────────────────────────────────────────

	public function test_record_registration_outcome_clears_permanent_failure_and_sets_short_backoff_on_success(): void {
		Functions\expect( 'delete_option' )
			->once()
			->with( SiteRegistration::OPTION_PERMANENT_FAILURE );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_reg_backoff', 1, 5 * MINUTE_IN_SECONDS );

		SiteRegistration::record_registration_outcome( 'a-token' );

		$this->addToAssertionCount( 1 );
	}

	public function test_record_registration_outcome_clears_permanent_failure_and_sets_short_backoff_on_transient_failure(): void {
		Functions\expect( 'delete_option' )
			->once()
			->with( SiteRegistration::OPTION_PERMANENT_FAILURE );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_reg_backoff', 1, 5 * MINUTE_IN_SECONDS );

		$transient_error = new \WP_Error( 'challenge_failed', 'Could not obtain activation challenge.' );

		SiteRegistration::record_registration_outcome( $transient_error );

		$this->addToAssertionCount( 1 );
	}

	public function test_record_registration_outcome_stores_diagnostic_and_sets_long_backoff_on_permanent_failure(): void {
		Functions\expect( 'update_option' )
			->once()
			->with(
				SiteRegistration::OPTION_PERMANENT_FAILURE,
				[
					'reason'  => 'network_error',
					'message' => 'Could not reach this site.',
				],
				false
			);
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_reg_backoff', 1, SiteRegistration::PERMANENT_BACKOFF );
		Functions\expect( 'delete_option' )->never();

		$permanent_error = new \WP_Error(
			'site_unreachable',
			'Could not reach this site.',
			[ 'permanent' => true, 'reason' => 'network_error' ]
		);

		SiteRegistration::record_registration_outcome( $permanent_error );

		$this->addToAssertionCount( 1 );
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
				// 'free' — the Worker's VALID_TIERS no longer includes 'trial'.
				return 1 === ++$bodyCallNum
					? '{"challenge":"' . $challenge . '"}'
					: '{"token":"' . $token . '","tier":"free"}';
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

		$result = SiteRegistration::register();

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

		$result = SiteRegistration::register();

		$this->assertSame( $token, $result );
		$this->assertSame( $secret, $captured[ SiteRegistration::OPTION_SECRET ]['value'] );
		$this->assertFalse( $captured[ SiteRegistration::OPTION_SECRET ]['autoload'] );
		// Tier should also have been persisted via set_site_tier().
		$this->assertSame( 'pro_managed', $captured['plume_site_tier']['value'] );
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

		$result = SiteRegistration::register();

		$this->assertSame( $token, $result );
		// Only the token option must have been written — no secret, no tier.
		$this->assertArrayHasKey( SiteRegistration::OPTION_TOKEN, $captured );
		$this->assertArrayNotHasKey( SiteRegistration::OPTION_SECRET, $captured );
		$this->assertArrayNotHasKey( 'plume_site_tier', $captured );
	}

	// ── rotate_secret() ─────────────────────────────────────────────────────

	public function test_rotate_secret_returns_wp_error_when_not_registered(): void {
		Functions\expect( 'get_option' )
			->with( SiteRegistration::OPTION_TOKEN, '' )
			->andReturn( '' );
		Functions\when( '__' )->returnArg();

		$result = SiteRegistration::rotate_secret();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->get_error_code() );
	}

	public function test_rotate_secret_returns_wp_error_on_non_200(): void {
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => SiteRegistration::OPTION_TOKEN === $k ? 'site-token' : $d
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $d ) => json_encode( $d ) );
		Functions\when( 'wp_remote_post' )->justReturn( [] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );

		$result = SiteRegistration::rotate_secret();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rotate_failed', $result->get_error_code() );
	}

	public function test_rotate_secret_stores_new_secret_on_success(): void {
		$secret = str_repeat( '7', 64 );

		Functions\when( 'get_option' )->alias(
			fn( $k, $d = '' ) => SiteRegistration::OPTION_TOKEN === $k ? 'site-token' : $d
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

		$result = SiteRegistration::rotate_secret();

		$this->assertSame( $secret, $result );
		$this->assertSame( $secret, $captured[ SiteRegistration::OPTION_SECRET ] );
		$this->assertSame( 'pro_managed', $captured['plume_site_tier'] );
	}
}

<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Payments;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Payments\TierUpdateWebhookController;
use WP_REST_Request;

/**
 * Unit tests for TierUpdateWebhookController.
 *
 * @since 1.9.0
 */
class TierUpdateWebhookControllerTest extends TestCase {

	private const SECRET = 'shared-test-secret';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a signed request with the given body and timestamp.
	 *
	 * @param string $body      Raw JSON body.
	 * @param int    $timestamp Unix timestamp baked into the signature.
	 * @param string $secret    Secret to sign with (defaults to SECRET).
	 * @return WP_REST_Request
	 */
	private function makeSignedRequest( string $body, int $timestamp, string $secret = self::SECRET ): WP_REST_Request {
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		$req       = new WP_REST_Request( 'POST', '/wp-ai-mind/v1/tier-update' );
		$req->set_header( 'content_type', 'application/json' );
		$req->set_header( 'x_wp_ai_mind_signature', $signature );
		$req->set_header( 'x_wp_ai_mind_timestamp', (string) $timestamp );
		$req->set_body( $body );
		return $req;
	}

	/**
	 * Default option stubs: secret present, no replay seen, transients write OK.
	 */
	private function stubOptionsAndTransients( string $secret = self::SECRET ): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( $secret ) {
				if ( TierUpdateWebhookController::OPTION_SECRET === $key ) {
					return $secret;
				}
				return $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	// ── Happy path ──────────────────────────────────────────────────────────

	public function test_valid_signature_updates_site_tier_and_returns_200(): void {
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( TierUpdateWebhookController::OPTION_SECRET === $key ) {
					return self::SECRET;
				}
				return $default;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		// Assert that the replay-protection transient key follows the expected format.
		Functions\expect( 'set_transient' )
			->once()
			->with( \Mockery::pattern( '/^wp_ai_mind_tier_sig_[a-f0-9]{32}$/' ), 1, 360 )
			->andReturn( true );
		Functions\expect( 'update_option' )
			->once()
			->with( 'wp_ai_mind_site_tier', 'pro_managed', false )
			->andReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		$body = wp_json_encode_stub( [ 'tier' => 'pro_managed' ] );
		$req  = $this->makeSignedRequest( $body, time() );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( [ 'ok' => true ], $res->data );
	}

	// ── Auth failures ───────────────────────────────────────────────────────

	public function test_wrong_signature_returns_401(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		$body = '{"tier":"pro_managed"}';
		$req  = $this->makeSignedRequest( $body, time(), 'attacker-secret' );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'invalid_signature', $res->data['error'] );
	}

	public function test_stale_timestamp_returns_401(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		$body = '{"tier":"pro_managed"}';
		$req  = $this->makeSignedRequest( $body, time() - 1000 );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'expired', $res->data['error'] );
	}

	public function test_far_future_timestamp_returns_401(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		$body = '{"tier":"pro_managed"}';
		$req  = $this->makeSignedRequest( $body, time() + 1000 );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'expired', $res->data['error'] );
	}

	public function test_replayed_signature_returns_401(): void {
		Functions\when( 'get_option' )->alias(
			fn( $k, $d = false ) => TierUpdateWebhookController::OPTION_SECRET === $k ? self::SECRET : $d
		);
		// First call simulates the signature already being recorded.
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\expect( 'update_option' )->never();

		$body = '{"tier":"pro_managed"}';
		$req  = $this->makeSignedRequest( $body, time() );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'replay', $res->data['error'] );
	}

	public function test_empty_secret_returns_401(): void {
		$this->stubOptionsAndTransients( '' );
		Functions\expect( 'update_option' )->never();

		$body = '{"tier":"pro_managed"}';
		$req  = $this->makeSignedRequest( $body, time() );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'not_configured', $res->data['error'] );
	}

	public function test_missing_headers_returns_401(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		$req = new WP_REST_Request( 'POST', '/wp-ai-mind/v1/tier-update' );
		$req->set_header( 'content_type', 'application/json' );
		$req->set_body( '{"tier":"pro_managed"}' );
		// No signature or timestamp headers.

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 'missing_headers', $res->data['error'] );
	}

	// ── Body validation ─────────────────────────────────────────────────────

	public function test_malformed_json_returns_400(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		$body = 'this is not json';
		$req  = $this->makeSignedRequest( $body, time() );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'bad_request', $res->data['error'] );
	}

	public function test_invalid_tier_returns_400(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		$body = '{"tier":"enterprise"}';
		$req  = $this->makeSignedRequest( $body, time() );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'bad_request', $res->data['error'] );
	}

	// ── Content-Type / size guards ──────────────────────────────────────────

	public function test_unsupported_content_type_returns_415(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		$body = '{"tier":"pro_managed"}';
		$req  = $this->makeSignedRequest( $body, time() );
		$req->set_header( 'content_type', 'text/plain' );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 415, $res->get_status() );
		$this->assertSame( 'unsupported_media_type', $res->data['error'] );
	}

	public function test_accepts_content_type_with_charset(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )
			->once()
			->with( 'wp_ai_mind_site_tier', 'pro_managed', false )
			->andReturn( true );
		Functions\when( 'do_action' )->justReturn( null );

		$body = '{"tier":"pro_managed"}';
		$req  = $this->makeSignedRequest( $body, time() );
		$req->set_header( 'content_type', 'application/json; charset=utf-8' );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 200, $res->get_status() );
	}

	public function test_oversized_body_returns_413(): void {
		$this->stubOptionsAndTransients();
		Functions\expect( 'update_option' )->never();

		// 2 KiB filler — comfortably above the 1 KiB cap.
		$body = '{"tier":"pro_managed","filler":"' . str_repeat( 'x', 2048 ) . '"}';
		$req  = $this->makeSignedRequest( $body, time() );

		$res = TierUpdateWebhookController::handle( $req );

		$this->assertSame( 413, $res->get_status() );
		$this->assertSame( 'payload_too_large', $res->data['error'] );
	}
}

// Lightweight helper for tests — avoids depending on wp_json_encode being stubbed for this file.
if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode_stub' ) ) {
	function wp_json_encode_stub( array $data ): string {
		return json_encode( $data );
	}
}

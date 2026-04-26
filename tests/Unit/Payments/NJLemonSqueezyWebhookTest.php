<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Payments;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Payments\NJ_LemonSqueezy_Webhook;

/**
 * Extended WP_REST_Request stub with body and header support.
 *
 * The bootstrap stub only covers params. The webhook handler requires
 * get_body() and get_header(), so we subclass to add those methods.
 */
class FakeWebhookRequest extends \WP_REST_Request {

	private string $body = '';

	/** @var array<string, string> */
	private array $headers = [];

	/**
	 * Sets the raw request body.
	 *
	 * @since 1.2.1
	 * @param string $body Raw request body string.
	 */
	public function set_body( string $body ): void {
		$this->body = $body;
	}

	/**
	 * Returns the raw request body.
	 *
	 * @since 1.2.1
	 * @return string Raw request body string.
	 */
	public function get_body(): string {
		return $this->body;
	}

	/**
	 * Sets a request header, normalised to lowercase.
	 *
	 * @since 1.2.1
	 * @param string $key   Header name.
	 * @param string $value Header value.
	 */
	public function set_header( string $key, string $value ): void {
		$this->headers[ strtolower( $key ) ] = $value;
	}

	/**
	 * Returns a request header value, or null if not set.
	 *
	 * @since 1.2.1
	 * @param  string $key Header name.
	 * @return string|null Header value, or null if absent.
	 */
	public function get_header( string $key ): ?string {
		return $this->headers[ strtolower( $key ) ] ?? null;
	}
}

/**
 * Tests for NJ_LemonSqueezy_Webhook.
 *
 * @since 1.2.1
 */
class NJLemonSqueezyWebhookTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// Define the webhook secret used by NJ_LemonSqueezy_Webhook::handle().
		if ( ! defined( 'WP_AI_MIND_LS_SECRET' ) ) {
			define( 'WP_AI_MIND_LS_SECRET', 'test-secret' );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// wp_json_encode is a WP function — alias it to the native json_encode.
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Helper ────────────────────────────────────────────────────────────────

	/**
	 * Build a POST request with a valid HMAC-SHA256 X-Signature for 'test-secret'.
	 *
	 * @param array<string, mixed> $payload
	 */
	private function make_signed_request( array $payload ): FakeWebhookRequest {
		$body = wp_json_encode( $payload );
		$sig  = hash_hmac( 'sha256', $body, 'test-secret' );
		$req  = new FakeWebhookRequest( 'POST' );
		$req->set_body( $body );
		$req->set_header( 'X-Signature', $sig );
		return $req;
	}

	// ── Signature validation ──────────────────────────────────────────────────

	public function test_handle_returns_401_when_signature_is_invalid(): void {
		$req = new FakeWebhookRequest( 'POST' );
		$req->set_body( '{"data":{"attributes":{"user_email":"a@example.com"}}}' );
		$req->set_header( 'X-Signature', 'invalid-signature' );

		$response = NJ_LemonSqueezy_Webhook::handle( $req );

		$this->assertSame( 401, $response->get_status() );
	}

	// ── Payload validation ────────────────────────────────────────────────────

	public function test_handle_returns_400_when_email_missing_from_payload(): void {
		// Valid HMAC, but payload has no data.attributes.user_email.
		$req = $this->make_signed_request( [ 'data' => [ 'attributes' => [] ] ] );

		$response = NJ_LemonSqueezy_Webhook::handle( $req );

		$this->assertSame( 400, $response->get_status() );
	}

	// ── User-not-found path ───────────────────────────────────────────────────

	public function test_handle_returns_200_skipped_when_user_not_found(): void {
		Functions\expect( 'get_user_by' )
			->once()
			->with( 'email', 'notfound@example.com' )
			->andReturn( false );

		$payload = [
			'data' => [
				'attributes' => [ 'user_email' => 'notfound@example.com' ],
			],
			'meta' => [ 'event_name' => 'subscription_created' ],
		];
		$req = $this->make_signed_request( $payload );

		$response = NJ_LemonSqueezy_Webhook::handle( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'skipped', $response->data['action'] );
	}

	// ── Success path ──────────────────────────────────────────────────────────

	public function test_handle_returns_200_received_on_success(): void {
		$user     = new \stdClass();
		$user->ID = 1;

		Functions\expect( 'get_user_by' )
			->once()
			->with( 'email', 'user@example.com' )
			->andReturn( $user );

		Functions\expect( 'update_user_meta' )
			->once()
			->andReturn( true );

		$payload = [
			'data' => [
				'attributes' => [ 'user_email' => 'user@example.com' ],
			],
			'meta' => [ 'event_name' => 'subscription_created' ],
		];
		$req = $this->make_signed_request( $payload );

		$response = NJ_LemonSqueezy_Webhook::handle( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->data['received'] );
	}

	// ── register_routes ───────────────────────────────────────────────────────

	public function test_register_routes_registers_webhook_route(): void {
		$captured_namespace = null;
		$captured_route     = null;
		$captured_args      = null;

		Functions\when( 'register_rest_route' )->alias(
			function ( string $namespace, string $route, array $args ) use ( &$captured_namespace, &$captured_route, &$captured_args ): bool {
				$captured_namespace = $namespace;
				$captured_route     = $route;
				$captured_args      = $args;
				return true;
			}
		);

		NJ_LemonSqueezy_Webhook::register_routes();

		$this->assertSame( 'wp-ai-mind/v1', $captured_namespace );
		$this->assertSame( '/webhook', $captured_route );
		$this->assertSame( 'POST', $captured_args['methods'] );
		$this->assertSame( [ NJ_LemonSqueezy_Webhook::class, 'handle' ], $captured_args['callback'] );
	}

	// ── apply_event — tier mapping via handle() ───────────────────────────────

	public function test_subscription_created_sets_pro_managed(): void {
		$user     = new \stdClass();
		$user->ID = 10;

		Functions\expect( 'get_user_by' )->once()->andReturn( $user );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 10, 'wp_ai_mind_tier', 'pro_managed' )
			->andReturn( true );

		$payload = [
			'data' => [ 'attributes' => [ 'user_email' => 'u@example.com' ] ],
			'meta' => [ 'event_name' => 'subscription_created' ],
		];

		$response = NJ_LemonSqueezy_Webhook::handle( $this->make_signed_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_subscription_cancelled_sets_free(): void {
		$user     = new \stdClass();
		$user->ID = 11;

		Functions\expect( 'get_user_by' )->once()->andReturn( $user );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 11, 'wp_ai_mind_tier', 'free' )
			->andReturn( true );

		$payload = [
			'data' => [ 'attributes' => [ 'user_email' => 'u@example.com' ] ],
			'meta' => [ 'event_name' => 'subscription_cancelled' ],
		];

		$response = NJ_LemonSqueezy_Webhook::handle( $this->make_signed_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_order_created_sets_pro_byok(): void {
		$user     = new \stdClass();
		$user->ID = 12;

		Functions\expect( 'get_user_by' )->once()->andReturn( $user );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 12, 'wp_ai_mind_tier', 'pro_byok' )
			->andReturn( true );

		$payload = [
			'data' => [ 'attributes' => [ 'user_email' => 'u@example.com' ] ],
			'meta' => [ 'event_name' => 'order_created' ],
		];

		$response = NJ_LemonSqueezy_Webhook::handle( $this->make_signed_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_subscription_resumed_sets_pro_managed(): void {
		$user     = new \stdClass();
		$user->ID = 13;

		Functions\expect( 'get_user_by' )->once()->andReturn( $user );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 13, 'wp_ai_mind_tier', 'pro_managed' )
			->andReturn( true );

		$payload = [
			'data' => [ 'attributes' => [ 'user_email' => 'u@example.com' ] ],
			'meta' => [ 'event_name' => 'subscription_resumed' ],
		];

		$response = NJ_LemonSqueezy_Webhook::handle( $this->make_signed_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_subscription_expired_sets_free(): void {
		$user     = new \stdClass();
		$user->ID = 14;

		Functions\expect( 'get_user_by' )->once()->andReturn( $user );
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 14, 'wp_ai_mind_tier', 'free' )
			->andReturn( true );

		$payload = [
			'data' => [ 'attributes' => [ 'user_email' => 'u@example.com' ] ],
			'meta' => [ 'event_name' => 'subscription_expired' ],
		];

		$response = NJ_LemonSqueezy_Webhook::handle( $this->make_signed_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_unknown_event_returns_200_without_updating_meta(): void {
		$user     = new \stdClass();
		$user->ID = 15;

		Functions\expect( 'get_user_by' )->once()->andReturn( $user );
		// update_user_meta must NOT be called for unknown events.
		Functions\expect( 'update_user_meta' )->never();

		$payload = [
			'data' => [ 'attributes' => [ 'user_email' => 'u@example.com' ] ],
			'meta' => [ 'event_name' => 'subscription_paused' ],
		];

		$response = NJ_LemonSqueezy_Webhook::handle( $this->make_signed_request( $payload ) );

		$this->assertSame( 200, $response->get_status() );
	}
}

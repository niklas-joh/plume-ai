<?php
/**
 * Base class for all WP AI Mind integration tests.
 *
 * Extends WP_UnitTestCase so every test has a real WordPress environment,
 * including a live database and a functional REST server. Shared helpers
 * cover tier assignment, HTTP interception, and REST dispatch.
 *
 * @package WP_AI_Mind\Tests\Integration
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Integration;

use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Proxy\NJ_Site_Registration;

/**
 * Base integration test case.
 *
 * @since 1.0.0
 */
abstract class IntegrationTestCase extends \WP_UnitTestCase {

	/**
	 * User ID for an editor-role user shared across all tests in the class.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected static int $editor_user_id;

	/**
	 * User ID for a subscriber-role user shared across all tests in the class.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected static int $subscriber_user_id;

	/**
	 * The raw $args array captured from the last intercepted wp_remote_post() call.
	 *
	 * Populated by mock_http_with_claude_fixture(). Tests can inspect
	 * $this->last_http_args['body'] to assert on what was sent to the provider.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>|null
	 */
	protected ?array $last_http_args = null;

	/**
	 * Tag used to remove the pre_http_request filter added by mock_http_with_claude_fixture().
	 *
	 * @since 1.0.0
	 * @var callable|null
	 */
	private $http_mock_callback = null;

	// ── Lifecycle ──────────────────────────────────────────────────────────────

	/**
	 * Create shared editor and subscriber users once per test class.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		self::$editor_user_id = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => 'integration_editor_' . uniqid(),
			]
		);

		self::$subscriber_user_id = self::factory()->user->create(
			[
				'role'       => 'subscriber',
				'user_login' => 'integration_subscriber_' . uniqid(),
			]
		);
	}

	/**
	 * Reset the REST server and set a fake site token before each test.
	 *
	 * The site token prevents NJ_Site_Registration::maybe_register() from
	 * firing a real outbound HTTP call during the test run.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Prevent the proxy registration flow from making real network calls.
		update_option( NJ_Site_Registration::OPTION_TOKEN, 'test-site-token' );

		// Force a fresh REST server so routes registered by the plugin are
		// available without carrying state between tests.
		global $wp_rest_server;
		$wp_rest_server = null;
		do_action( 'rest_api_init' );
	}

	/**
	 * Remove HTTP mock filter and clean up after each test.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function tearDown(): void {
		if ( null !== $this->http_mock_callback ) {
			remove_filter( 'pre_http_request', $this->http_mock_callback );
			$this->http_mock_callback = null;
		}
		$this->last_http_args = null;

		wp_set_current_user( 0 );

		parent::tearDown();
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Assign a tier to a user via the canonical NJ_Tier_Manager meta key.
	 *
	 * For the 'trial' tier this also writes a fresh trial-started timestamp so
	 * is_trial_active() considers the trial valid for the duration of the test.
	 *
	 * @since 1.0.0
	 * @param int    $user_id WordPress user ID.
	 * @param string $tier    Tier slug — one of 'free', 'trial', 'pro_managed', 'pro_byok'.
	 * @return void
	 */
	protected function set_user_tier( int $user_id, string $tier ): void {
		update_user_meta( $user_id, NJ_Tier_Manager::META_KEY, $tier );

		if ( 'trial' === $tier ) {
			// Record the trial start as now so is_trial_active() returns true.
			update_user_meta( $user_id, NJ_Tier_Manager::TRIAL_STARTED_META, time() );
		}

		// Ensure the site-level option does not shadow the user meta for the
		// tiers under test — reset it to 'free' so paid-tier short-circuit in
		// get_user_tier() does not override the per-user meta we just set.
		update_option( NJ_Tier_Manager::SITE_OPTION, 'free', false );
	}

	/**
	 * Install a pre_http_request filter that intercepts all wp_remote_post() calls
	 * and returns a synthetic Claude-format 200 response.
	 *
	 * The raw request $args are stored in $this->last_http_args so individual
	 * tests can inspect $this->last_http_args['body'] to assert on the payload
	 * that would have been sent to the AI provider.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $response_body Decoded response body to return as JSON.
	 * @return void
	 */
	protected function mock_http_with_claude_fixture( array $response_body ): void {
		// Remove any previously installed mock to avoid double-firing.
		if ( null !== $this->http_mock_callback ) {
			remove_filter( 'pre_http_request', $this->http_mock_callback );
		}

		$this->http_mock_callback = function ( $preempt, array $parsed_args, string $url ) use ( $response_body ) {
			// Capture the raw request args so tests can assert on the body.
			$this->last_http_args = $parsed_args;

			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => wp_json_encode( $response_body ),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};

		add_filter( 'pre_http_request', $this->http_mock_callback, 10, 3 );
	}

	/**
	 * Install a pre_http_request filter that intercepts HTTP calls and returns a
	 * synthetic Gemini Imagen-format 200 response.
	 *
	 * Use this helper when the code under test calls the Gemini provider so the
	 * mock's intent is clear and distinct from the Claude-specific variant.
	 *
	 * @since 1.0.0
	 * @param array<string, mixed> $response_body Decoded response body to return as JSON.
	 * @return void
	 */
	protected function mock_http_with_gemini_fixture( array $response_body ): void {
		// Remove any previously installed mock to avoid double-firing.
		if ( null !== $this->http_mock_callback ) {
			remove_filter( 'pre_http_request', $this->http_mock_callback );
		}

		$this->http_mock_callback = function ( $preempt, array $parsed_args, string $url ) use ( $response_body ) {
			// Capture the raw request args so tests can assert on the body.
			$this->last_http_args = $parsed_args;

			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => wp_json_encode( $response_body ),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};

		add_filter( 'pre_http_request', $this->http_mock_callback, 10, 3 );
	}

	/**
	 * Dispatch a request against the WordPress REST server and return the response.
	 *
	 * Convenience wrapper around WP_REST_Server::dispatch() that handles server
	 * initialisation. Query parameters (for GET) and body parameters (for POST)
	 * are both accepted via $params — the method routes them correctly.
	 *
	 * @since 1.0.0
	 * @param string               $method HTTP method ('GET', 'POST', 'PATCH', 'DELETE').
	 * @param string               $route  REST route path, e.g. '/wp-ai-mind/v1/conversations'.
	 * @param array<string, mixed> $params Request parameters (query string or body).
	 * @return \WP_REST_Response
	 */
	protected function rest_do( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );

		if ( in_array( strtoupper( $method ), [ 'POST', 'PATCH', 'PUT' ], true ) ) {
			$request->set_body_params( $params );
		} else {
			$request->set_query_params( $params );
		}

		$server = rest_get_server();
		return $server->dispatch( $request );
	}
}

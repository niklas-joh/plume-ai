<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Admin\OnboardingRestController;
use Plume\Proxy\SiteRegistration;
use Plume\Settings\ProviderSettings;
use PHPUnit\Framework\TestCase;

class OnboardingRestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Route registration ───────────────────────────────────────────────────

	public function test_register_routes_registers_onboarding_endpoint(): void {
		$registered_ns    = null;
		$registered_route = null;

		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route ) use ( &$registered_ns, &$registered_route ) {
				$registered_ns    = $ns;
				$registered_route = $route;
			}
		);

		OnboardingRestController::register_routes();

		$this->assertSame( 'plume/v1', $registered_ns );
		$this->assertSame( '/onboarding', $registered_route );
	}

	// ── Permission check ─────────────────────────────────────────────────────

	public function test_check_permission_returns_true_for_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$result = OnboardingRestController::check_permission();

		$this->assertTrue( $result );
	}

	public function test_check_permission_returns_wp_error_for_non_admin(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = OnboardingRestController::check_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->code );
	}

	// ── save() — seen flag ───────────────────────────────────────────────────

	public function test_save_marks_onboarding_seen(): void {
		$captured_key = null;
		Functions\when( 'update_option' )->alias(
			function ( $key ) use ( &$captured_key ) {
				$captured_key = $key;
				return true;
			}
		);
		// pro_byok: skips the site-registration branch, irrelevant to this test's assertion.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'seen', true );

		OnboardingRestController::save( $request );

		$this->assertSame( 'plume_onboarding_seen', $captured_key );
	}

	public function test_save_clears_onboarding_seen(): void {
		$captured_key = null;
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( &$captured_key ) {
				$captured_key = $key;
				return true;
			}
		);
		// pro_byok: skips the site-registration branch, irrelevant to this test's assertion.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'seen', false );

		OnboardingRestController::save( $request );

		$this->assertSame( 'plume_onboarding_seen', $captured_key );
	}

	// ── save() — api_keys ────────────────────────────────────────────────────

	public function test_save_stores_api_keys_per_provider(): void {
		Functions\when( 'sanitize_text_field' )->alias( fn( $s ) => $s );
		// Tier gate: TierManager::user_can() needs these stubs.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\when( 'get_user_meta' )->alias( fn( $uid, $key, $single ) => $key === 'plume_tier' ? 'pro_byok' : null );

		$mock_settings = $this->createMock( ProviderSettings::class );
		$mock_settings->expects( $this->once() )
			->method( 'set_api_key' )
			->with( 'openai', 'sk-test' );

		$ctrl        = new class extends OnboardingRestController {
			public static ProviderSettings $mock;
			protected static function make_provider_settings(): ProviderSettings {
				return self::$mock;
			}
		};
		$ctrl::$mock = $mock_settings;

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'api_keys', [ 'openai' => 'sk-test' ] );

		$ctrl::save( $request );
	}

	public function test_save_ignores_invalid_provider_in_api_keys(): void {
		// Tier gate: TierManager::user_can() needs these stubs.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// pro_byok is a site-level entitlement now, so stub the site option.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\when( 'get_user_meta' )->alias( fn( $uid, $key, $single ) => $key === 'plume_tier' ? 'pro_byok' : null );

		$mock_settings = $this->createMock( ProviderSettings::class );
		$mock_settings->expects( $this->never() )->method( 'set_api_key' );

		$ctrl        = new class extends OnboardingRestController {
			public static ProviderSettings $mock;
			protected static function make_provider_settings(): ProviderSettings {
				return self::$mock;
			}
		};
		$ctrl::$mock = $mock_settings;

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'api_keys', [ 'unknown' => 'some-key' ] );

		$ctrl::save( $request );
	}

	public function test_save_api_keys_rejected_for_free_tier(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		// tier gate: simulate a free-tier user.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias( fn( $uid, $key, $single ) => $key === 'plume_tier' ? 'free' : null );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'api_keys', [ 'openai' => 'sk-test' ] );

		$response = OnboardingRestController::save( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'rest_plan_required', $response->get_error_code() );
		$this->assertSame( 403, $response->get_error_data( 'rest_plan_required' )['status'] );
	}

	// ── save() — response ────────────────────────────────────────────────────

	public function test_save_returns_success_response(): void {
		// pro_byok: skips the site-registration branch, irrelevant to this test's assertion.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);

		$request = new \WP_REST_Request( 'POST' );
		// No params — all branches skipped, straight to the return statement.

		$response = OnboardingRestController::save( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->data['success'] );
		$this->assertTrue( $response->data['registered'] );
	}

	// ── save() — site registration ───────────────────────────────────────────

	public function test_save_reports_registered_true_for_byok_tier_without_touching_site_token(): void {
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);

		$request  = new \WP_REST_Request( 'POST' );
		$response = OnboardingRestController::save( $request );

		$this->assertTrue( $response->data['registered'] );
	}

	public function test_save_registers_site_for_proxy_tier_when_not_already_registered(): void {
		// Default (free) tier — proxy-eligible.
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( SiteRegistration::OPTION_TOKEN === $key ) {
					return '';
				}
				return $default;
			}
		);
		// No backoff in effect, so maybe_register() proceeds to register().
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn(
			new \WP_Error( 'http_request_failed', 'Connection refused' )
		);
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request  = new \WP_REST_Request( 'POST' );
		$response = OnboardingRestController::save( $request );

		// The Worker is unreachable in this test, so registration fails and the
		// site token is still absent — the response must reflect that honestly.
		$this->assertFalse( $response->data['registered'] );
	}

	public function test_save_reports_registered_false_when_maybe_register_is_backed_off(): void {
		// Default (free) tier — proxy-eligible; no site token yet.
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				if ( SiteRegistration::OPTION_TOKEN === $key ) {
					return '';
				}
				return $default;
			}
		);
		// A prior failed attempt already set the backoff transient — maybe_register()
		// must skip silently rather than hammering the Worker on every onboarding save.
		Functions\when( 'get_transient' )->justReturn( 1 );

		$request  = new \WP_REST_Request( 'POST' );
		$response = OnboardingRestController::save( $request );

		$this->assertFalse( $response->data['registered'] );
	}
}

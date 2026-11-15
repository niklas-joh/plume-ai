<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Admin\DashboardPage;

if ( ! defined( 'PLUME_DIR' ) ) {
	define( 'PLUME_DIR', dirname( __DIR__, 3 ) . '/' );
}
if ( ! defined( 'PLUME_URL' ) ) {
	define( 'PLUME_URL', 'http://example.test/wp-content/plugins/plume/' );
}
if ( ! defined( 'PLUME_VERSION' ) ) {
	define( 'PLUME_VERSION', '0.0.0-test' );
}
if ( ! defined( 'PLUME_WEBSITE_URL' ) ) {
	define( 'PLUME_WEBSITE_URL', 'https://wpaimind.com' );
}

/**
 * Unit tests for DashboardPage's data-assembly logic (get_dashboard_data()).
 *
 * Exercised indirectly via enqueue_assets()'s wp_localize_script() call, since
 * get_dashboard_data() is private — the test captures the localised payload.
 */
class DashboardPageTest extends TestCase {

	/** @var array<int, array<int, string>> Captured wp_set_script_translations() calls. */
	private array $translations = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the WordPress functions DashboardPage::render()/enqueue_assets() call,
	 * for a given site tier, and capture the localised dashboard payload.
	 *
	 * @param string $tier             Site tier slug.
	 * @param bool   $manage_options   Whether the current user can see usage figures.
	 * @param int    $used             Simulated credits used this month.
	 * @return array<string, mixed> The captured `plumeDashboard` payload.
	 */
	private function render_and_capture_payload( string $tier, bool $manage_options = true, int $used = 0 ): array {
		$month_key = 'plume_credits_' . gmdate( 'Y_m' );

		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_unslash' )->alias( fn( $v ) => $v );
		Functions\when( 'current_user_can' )->justReturn( $manage_options );
		Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
		Functions\when( 'esc_url_raw' )->alias( fn( $v ) => $v );
		Functions\when( 'rest_url' )->alias( fn( $v ) => 'http://example.test/wp-json/' . $v );
		Functions\when( 'wp_nonce_url' )->alias( fn( $url ) => $url );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.test/wp-admin/' . $path );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( $uid, $key ) use ( $month_key, $used ) {
				return $month_key === $key ? (string) $used : '';
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( $tier ) {
				if ( 'plume_site_tier' === $key ) {
					return $tier;
				}
				return $default;
			}
		);
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		$this->translations = [];
		Functions\when( 'wp_set_script_translations' )->alias(
			function ( string $handle, string $domain, string $path ): bool {
				$this->translations[] = [ $handle, $domain, $path ];
				return true;
			}
		);

		$captured = null;
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $object_name, $data ) use ( &$captured ) {
				$captured = $data;
			}
		);

		ob_start();
		DashboardPage::render();
		ob_end_clean();

		$this->assertIsArray( $captured );
		return $captured;
	}

	public function test_dashboard_data_includes_is_paid_field(): void {
		$data = $this->render_and_capture_payload( 'free' );
		$this->assertArrayHasKey( 'isPaid', $data );
		$this->assertFalse( $data['isPaid'] );
	}

	public function test_dashboard_data_is_paid_true_for_pro_managed(): void {
		$data = $this->render_and_capture_payload( 'pro_managed' );
		$this->assertTrue( $data['isPaid'] );
	}

	public function test_dashboard_data_banner_state_none_for_paid_tier(): void {
		$data = $this->render_and_capture_payload( 'pro_managed' );
		$this->assertSame( 'none', $data['bannerState'] );
	}

	public function test_dashboard_data_banner_state_none_for_free_tier_under_80_percent(): void {
		// 50/100 = 50% — below the 80% threshold.
		$data = $this->render_and_capture_payload( 'free', true, 50 );
		$this->assertSame( 'none', $data['bannerState'] );
	}

	public function test_dashboard_data_banner_state_free_tier_low_credits_above_80_percent(): void {
		// 85/100 = 85% — above the 80% threshold.
		$data = $this->render_and_capture_payload( 'free', true, 85 );
		$this->assertSame( 'free_tier_low_credits', $data['bannerState'] );
	}

	public function test_dashboard_data_does_not_include_is_pro_key(): void {
		$data = $this->render_and_capture_payload( 'free' );
		$this->assertArrayNotHasKey( 'isPro', $data, 'isPro must be renamed to isPaid entirely, not kept alongside it.' );
	}

	/**
	 * The Dashboard's script must load the plugin's JSON translations.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_enqueue_loads_script_translations(): void {
		$this->render_and_capture_payload( 'free' );
		$this->assertContains( [ 'plume-admin', 'plume', PLUME_DIR . 'languages' ], $this->translations );
	}
}

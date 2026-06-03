<?php
declare( strict_types=1 );

namespace Stilus\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Stilus\Admin\UsageWidget;

class UsageWidgetTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default site-tier lookup returns the supplied default; individual tests
		// override when they need a paid site tier.
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Hook registration ────────────────────────────────────────────────────

	public function test_register_hooks_adds_dashboard_setup_action(): void {
		$action_added = false;

		Functions\when( 'add_action' )->alias(
			function ( string $hook, $callback ) use ( &$action_added ): void {
				if ( 'wp_dashboard_setup' === $hook ) {
					$action_added = true;
				}
			}
		);

		UsageWidget::register_hooks();

		$this->assertTrue( $action_added, 'add_action was not called with wp_dashboard_setup' );
	}

	public function test_add_dashboard_widget_registers_widget_with_correct_id(): void {
		$registered_id = null;

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( '__' )->andReturnFirstArg();
		Functions\when( 'wp_add_dashboard_widget' )->alias(
			function ( string $id ) use ( &$registered_id ): void {
				$registered_id = $id;
			}
		);

		UsageWidget::add_dashboard_widget();

		$this->assertSame( 'stilus_usage', $registered_id );
	}

	public function test_add_dashboard_widget_skipped_without_manage_options(): void {
		$widget_registered = false;

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_add_dashboard_widget' )->alias(
			function () use ( &$widget_registered ): void {
				$widget_registered = true;
			}
		);

		UsageWidget::add_dashboard_widget();

		$this->assertFalse( $widget_registered, 'Widget must not be registered for users without manage_options' );
	}

	// ── render() — limited tier (free) ───────────────────────────────────────

	public function test_render_shows_plan_label_and_progress_bar_for_free_tier(): void {
		$month_key = 'stilus_usage_' . gmdate( 'Y_m' );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// tier meta is called once inside get_usage(); render() reads $usage['tier'] directly
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) use ( $month_key ): string {
				if ( 'stilus_tier' === $key ) {
					return 'free';
				}
				if ( $month_key === $key ) {
					return '25000';
				}
				return '';
			}
		);
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => (string) number_format( (int) $n ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		ob_start();
		UsageWidget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Free', $output );
		$this->assertStringContainsString( '25,000', $output );
		$this->assertStringContainsString( '50,000', $output );
		$this->assertStringContainsString( 'wpaim-progress-track', $output );
	}

	public function test_render_shows_upgrade_notice_when_above_80_percent(): void {
		$month_key = 'stilus_usage_' . gmdate( 'Y_m' );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) use ( $month_key ): string {
				if ( 'stilus_tier' === $key ) {
					return 'free'; // limit = 50000
				}
				if ( $month_key === $key ) {
					return '46000'; // 92% used
				}
				return '';
			}
		);
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => (string) number_format( (int) $n ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		ob_start();
		UsageWidget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Over 80%', $output );
		$this->assertStringContainsString( 'wpaim-progress-bar--danger', $output );
	}

	// ── render() — unlimited tier (pro_byok) ─────────────────────────────────

	public function test_render_shows_unlimited_message_for_pro_byok(): void {
		$month_key = 'stilus_usage_' . gmdate( 'Y_m' );

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// pro_byok lives on the site option now.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'stilus_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) use ( $month_key ): string {
				if ( $month_key === $key ) {
					return '0';
				}
				return '';
			}
		);
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => (string) number_format( (int) $n ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		ob_start();
		UsageWidget::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Unlimited', $output );
		$this->assertStringNotContainsString( 'wpaim-progress-track', $output );
	}
}

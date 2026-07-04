<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Admin\ActivationNotice;

/**
 * Covers the WP.org Guideline 11 page-scoping of the one-time activation
 * disclosure: the notice must render only on Plume admin screens, and the
 * single-use flag must survive visits to unrelated wp-admin pages so the
 * disclosure is not silently burned before the user can see it.
 */
class ActivationNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// is_plume_admin_page() runs $_GET through these; pass values through verbatim.
		Functions\when( 'sanitize_key' )->alias( fn( $value ) => strtolower( (string) $value ) );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $_GET['page'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the escaping/i18n functions used by the notice markup.
	 *
	 * @return void
	 */
	private function stub_render_functions(): void {
		if ( ! defined( 'PLUME_WEBSITE_URL' ) ) {
			define( 'PLUME_WEBSITE_URL', 'https://wpaimind.com' );
		}
		Functions\when( 'esc_html_e' )->alias(
			function ( string $text ): void {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
	}

	public function test_nothing_renders_and_flag_survives_off_plume_pages(): void {
		$_GET['page'] = 'edit';
		Functions\when( 'get_option' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'delete_option' )->never();

		ob_start();
		ActivationNotice::maybe_display();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_nothing_renders_and_flag_survives_when_page_param_absent(): void {
		unset( $_GET['page'] );
		Functions\when( 'get_option' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'delete_option' )->never();

		ob_start();
		ActivationNotice::maybe_display();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_renders_once_and_consumes_flag_on_plume_page(): void {
		$_GET['page'] = 'plume-dashboard';
		$this->stub_render_functions();
		Functions\when( 'get_option' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'delete_option' )->once()->with( 'plume_just_activated' )->andReturn( true );

		ob_start();
		ActivationNotice::maybe_display();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice notice-info', $output );
		$this->assertStringContainsString( 'External Services', $output );
	}

	public function test_nothing_renders_without_capability_even_on_plume_page(): void {
		$_GET['page'] = 'plume-dashboard';
		Functions\when( 'get_option' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'delete_option' )->never();

		ob_start();
		ActivationNotice::maybe_display();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_nothing_renders_when_flag_absent(): void {
		$_GET['page'] = 'plume-dashboard';
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'delete_option' )->never();

		ob_start();
		ActivationNotice::maybe_display();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}
}

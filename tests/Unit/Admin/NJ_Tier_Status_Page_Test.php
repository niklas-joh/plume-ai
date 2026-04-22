<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Admin\NJ_Tier_Status_Page;
use WP_AI_Mind\Proxy\NJ_Site_Registration;

class NJ_Tier_Status_Page_Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Stub all WP display functions used by render().
	 */
	private function stub_display_functions(): void {
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'number_format_i18n' )->alias( fn( $n ) => (string) number_format( (int) $n ) );
		Functions\when( 'get_admin_page_title' )->justReturn( 'WP AI Mind — Plan &amp; Usage' );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . ltrim( $path, '/' ) );
	}

	/**
	 * Stub get_option and get_user_meta for a given tier and registration state.
	 *
	 * @param string $tier       One of: free, trial, pro_managed, pro_byok.
	 * @param bool   $registered Whether the site has a stored token.
	 * @param int    $used       Tokens used this month (ignored for unlimited tiers).
	 */
	private function stub_tier_and_registration( string $tier, bool $registered, int $used = 0 ): void {
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );
		$token     = $registered ? 'test-site-token' : '';

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) use ( $tier, $month_key, $used ): string {
				if ( 'wp_ai_mind_tier' === $key ) {
					return $tier;
				}
				if ( $month_key === $key ) {
					return (string) $used;
				}
				return '';
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) use ( $token ) {
				if ( 'wp_ai_mind_site_token' === $key ) {
					return $token;
				}
				return $default;
			}
		);
	}

	// ── Proxy connection status row ───────────────────────────────────────────

	public function test_render_shows_connected_status_when_registered(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Connected', $output );
		$this->assertStringNotContainsString( 'Not connected', $output );
	}

	public function test_render_shows_not_connected_status_when_not_registered(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', false );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Not connected', $output );
		$this->assertStringNotContainsString( 'wpaim-status--active', $output );
	}

	// ── Upgrade section — checkout URLs ──────────────────────────────────────

	public function test_render_includes_pro_managed_monthly_checkout_url_for_free_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 1000 );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( NJ_Site_Registration::PLAN_PRO_MANAGED_MONTHLY, $output );
	}

	public function test_render_includes_pro_managed_annual_checkout_url_for_free_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 1000 );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( NJ_Site_Registration::PLAN_PRO_MANAGED_ANNUAL, $output );
	}

	public function test_render_includes_pro_byok_onetime_checkout_url_for_free_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 1000 );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( NJ_Site_Registration::PLAN_PRO_BYOK_ONETIME, $output );
	}

	public function test_render_includes_all_three_checkout_urls_for_trial_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'trial', true, 5000 );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( NJ_Site_Registration::PLAN_PRO_MANAGED_MONTHLY, $output );
		$this->assertStringContainsString( NJ_Site_Registration::PLAN_PRO_MANAGED_ANNUAL, $output );
		$this->assertStringContainsString( NJ_Site_Registration::PLAN_PRO_BYOK_ONETIME, $output );
	}

	public function test_render_omits_upgrade_section_for_pro_managed_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'pro_managed', true, 100000 );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( NJ_Site_Registration::PLAN_PRO_MANAGED_MONTHLY, $output );
		$this->assertStringNotContainsString( NJ_Site_Registration::PLAN_PRO_MANAGED_ANNUAL, $output );
		$this->assertStringNotContainsString( NJ_Site_Registration::PLAN_PRO_BYOK_ONETIME, $output );
	}

	public function test_checkout_url_embeds_site_token_in_query_string(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 0 );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'test-site-token', $output );
	}

	// ── "Manage your API keys" link ───────────────────────────────────────────

	public function test_render_shows_api_keys_link_for_pro_byok_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'pro_byok', true );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manage your API keys', $output );
		$this->assertStringContainsString( 'wp-ai-mind-api-keys', $output );
	}

	public function test_render_omits_api_keys_link_for_free_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', false );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Manage your API keys', $output );
	}

	public function test_render_omits_api_keys_link_for_pro_managed_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'pro_managed', true );

		ob_start();
		NJ_Tier_Status_Page::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Manage your API keys', $output );
	}
}

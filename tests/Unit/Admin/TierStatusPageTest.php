<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Admin\TierStatusPage;

class TierStatusPageTest extends TestCase {

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
		Functions\when( 'get_admin_page_title' )->justReturn( 'Plume AI - Write and Design - Plan &amp; Usage' );
		Functions\when( 'admin_url' )->alias( fn( $path ) => 'http://example.com/wp-admin/' . ltrim( $path, '/' ) );
	}

	/**
	 * Stub get_option and get_user_meta for a given tier and registration state.
	 *
	 * @param string $tier       One of: free, pro_managed, pro_byok.
	 * @param bool   $registered Whether the site has a stored token.
	 * @param int    $used       Credits used this month (ignored for unlimited tiers).
	 */
	private function stub_tier_and_registration( string $tier, bool $registered, int $used = 0 ): void {
		$month_key = 'plume_usage_' . gmdate( 'Y_m' );
		$token     = $registered ? 'test-site-token' : '';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key, bool $single = false ) use ( $month_key, $used ): string {
				if ( $month_key === $key ) {
					return (string) $used;
				}
				return '';
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = null ) use ( $token, $tier ) {
				if ( 'plume_site_token' === $key ) {
					return $token;
				}
				if ( 'plume_site_tier' === $key ) {
					return $tier;
				}
				return $default;
			}
		);
	}

	// ── Capability guard ─────────────────────────────────────────────────────

	public function test_render_produces_no_output_when_user_lacks_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		// No display stubs needed — render() must exit before any output.

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	// ── Proxy connection status row ───────────────────────────────────────────

	public function test_render_shows_connected_status_when_registered(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Connected', $output );
		$this->assertStringNotContainsString( 'Not connected', $output );
	}

	public function test_render_shows_not_connected_status_when_not_registered(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', false );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Not connected', $output );
		$this->assertStringNotContainsString( 'plume-status--active', $output );
	}

	// ── Upgrade section — checkout URLs ──────────────────────────────────────

	public function test_render_includes_pro_managed_monthly_checkout_url_for_free_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 1000 );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '1550505', $output );
	}

	public function test_render_includes_pro_managed_annual_checkout_url_for_free_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 1000 );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '1550477', $output );
	}

	public function test_render_offers_own_api_key_settings_link_instead_of_byok_checkout(): void {
		// BYOK is free on every plan (WP.org Guideline 5), so the upgrade card
		// links to Settings rather than a LemonSqueezy checkout.
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 1000 );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Use your own API key', $output );
		$this->assertStringContainsString( 'page=plume-settings', $output );
		$this->assertStringNotContainsString( '1550517', $output );
	}

	public function test_render_omits_upgrade_section_for_pro_managed_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'pro_managed', true, 100000 );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '1550505', $output );
		$this->assertStringNotContainsString( '1550477', $output );
		$this->assertStringNotContainsString( '1550517', $output );
	}

	public function test_checkout_url_embeds_site_token_in_query_string(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 0 );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'test-site-token', $output );
	}

	// ── Credits copy (renamed from tokens) ────────────────────────────────────

	public function test_render_shows_credits_used_and_remaining_labels_for_limited_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 50 );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Credits used this month', $output );
		$this->assertStringContainsString( 'Credits remaining', $output );
		$this->assertStringNotContainsString( 'Tokens used', $output );
		$this->assertStringNotContainsString( 'Tokens remaining', $output );
	}

	public function test_render_shows_no_credit_limit_copy_for_pro_byok(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'pro_byok', true );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No credit limit', $output );
	}

	public function test_render_upgrade_card_uses_credits_copy(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', true, 50 );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '500 credits/month', $output );
		$this->assertStringContainsString( 'no credit limit', $output );
	}

	// ── "Manage your API keys" link ───────────────────────────────────────────

	public function test_render_shows_api_keys_link_for_pro_byok_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'pro_byok', true );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Manage your API keys', $output );
		$this->assertStringContainsString( 'plume-settings', $output );
	}

	public function test_render_omits_api_keys_link_for_free_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'free', false );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Manage your API keys', $output );
	}

	public function test_render_omits_api_keys_link_for_pro_managed_tier(): void {
		$this->stub_display_functions();
		$this->stub_tier_and_registration( 'pro_managed', true );

		ob_start();
		TierStatusPage::render();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'Manage your API keys', $output );
	}
}

<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Admin\AdminMenu;

class AdminMenuTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Tier stub helpers ─────────────────────────────────────────────────────

	/**
	 * Stub TierManager-related WP functions so get_user_tier() returns 'free'.
	 */
	private function stub_free_tier(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		// Returning the default makes plume_site_tier resolve to 'free'.
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
		Functions\when( 'get_user_meta' )->justReturn( '' );
	}

	/**
	 * Stub TierManager-related WP functions so get_user_tier() returns 'pro_managed'.
	 *
	 * No HMAC secret stored → is_site_tier_verified() returns true for unregistered sites,
	 * so the paid site-option value is honoured without a signature check.
	 */
	private function stub_pro_managed_tier(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				if ( 'plume_site_tier' === $key ) {
					return 'pro_managed';
				}
				return $default; // plume_tier_sync_secret resolves to '' → unregistered path.
			}
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
	}

	/**
	 * Stub TierManager-related WP functions so get_user_tier() returns 'pro_byok'.
	 */
	private function stub_pro_byok_tier(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				if ( 'plume_site_tier' === $key ) {
					return 'pro_byok';
				}
				return $default;
			}
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
	}

	// ── register() — top-level menu ───────────────────────────────────────────

	public function test_register_calls_add_menu_page_with_edit_posts_capability_and_plume_slug(): void {
		$captured_capability = null;
		$captured_slug       = null;

		$this->stub_free_tier();
		Functions\when( 'add_menu_page' )->alias(
			function ( $page_title, $menu_title, $capability, $menu_slug ) use ( &$captured_capability, &$captured_slug ): void {
				$captured_capability = $capability;
				$captured_slug       = $menu_slug;
			}
		);
		Functions\when( 'add_submenu_page' )->justReturn( '' );

		AdminMenu::register();

		$this->assertSame( 'edit_posts', $captured_capability );
		$this->assertSame( 'plume', $captured_slug );
	}

	public function test_register_uses_base64_svg_data_uri_as_menu_icon(): void {
		$captured_icon = null;

		$this->stub_free_tier();
		Functions\when( 'add_menu_page' )->alias(
			function ( $page_title, $menu_title, $capability, $menu_slug, $callback, $icon_url ) use ( &$captured_icon ): void {
				$captured_icon = $icon_url;
			}
		);
		Functions\when( 'add_submenu_page' )->justReturn( '' );

		AdminMenu::register();

		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $captured_icon );
	}

	// ── register() — Settings submenu ─────────────────────────────────────────

	public function test_register_adds_settings_submenu_with_manage_options_capability(): void {
		$settings_capability = null;

		$this->stub_free_tier();
		Functions\when( 'add_menu_page' )->justReturn( '' );
		Functions\when( 'add_submenu_page' )->alias(
			function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug ) use ( &$settings_capability ): void {
				if ( 'plume-settings' === $menu_slug ) {
					$settings_capability = $capability;
				}
			}
		);

		AdminMenu::register();

		$this->assertSame( 'manage_options', $settings_capability );
	}

	// ── register() — conditional Upgrade submenu ───────────────────────────────

	public function test_register_adds_upgrade_submenu_for_free_tier(): void {
		$upgrade_added = false;

		$this->stub_free_tier();
		Functions\when( 'add_menu_page' )->justReturn( '' );
		Functions\when( 'add_submenu_page' )->alias(
			function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug ) use ( &$upgrade_added ): void {
				if ( 'plume-upgrade' === $menu_slug ) {
					$upgrade_added = true;
				}
			}
		);

		AdminMenu::register();

		$this->assertTrue( $upgrade_added, 'Upgrade submenu must be registered for free-tier users.' );
	}

	public function test_register_omits_upgrade_submenu_for_pro_managed_tier(): void {
		$upgrade_added = false;

		$this->stub_pro_managed_tier();
		Functions\when( 'add_menu_page' )->justReturn( '' );
		Functions\when( 'add_submenu_page' )->alias(
			function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug ) use ( &$upgrade_added ): void {
				if ( 'plume-upgrade' === $menu_slug ) {
					$upgrade_added = true;
				}
			}
		);

		AdminMenu::register();

		$this->assertFalse( $upgrade_added, 'Upgrade submenu must not be registered for pro_managed-tier users.' );
	}

	public function test_register_omits_upgrade_submenu_for_pro_byok_tier(): void {
		$upgrade_added = false;

		$this->stub_pro_byok_tier();
		Functions\when( 'add_menu_page' )->justReturn( '' );
		Functions\when( 'add_submenu_page' )->alias(
			function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug ) use ( &$upgrade_added ): void {
				if ( 'plume-upgrade' === $menu_slug ) {
					$upgrade_added = true;
				}
			}
		);

		AdminMenu::register();

		$this->assertFalse( $upgrade_added, 'Upgrade submenu must not be registered for pro_byok-tier users.' );
	}

	// ── settings_url ──────────────────────────────────────────────────────────

	/**
	 * Administrators get the settings URL.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public function test_settings_url_returns_the_settings_page_for_administrators(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'manage_options' === $cap );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'esc_url_raw' )->returnArg();

		$this->assertSame(
			'https://example.test/wp-admin/admin.php?page=plume-settings',
			AdminMenu::settings_url()
		);
	}

	/**
	 * Users who cannot open Settings get no link, so screens can omit it instead
	 * of sending them to a "not allowed" page.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public function test_settings_url_is_empty_for_users_who_cannot_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertSame( '', AdminMenu::settings_url() );
	}
}

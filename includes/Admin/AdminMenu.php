<?php
/**
 * Registers the Plume admin menu and sub-menu pages.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level Plume admin menu and all sub-menu pages.
 *
 * The 'Upgrade' sub-menu is conditionally added only for free and trial users;
 * Pro users see a clean menu without the upsell entry.
 */
class AdminMenu {

	/**
	 * Register all admin menu and sub-menu pages via WordPress hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		add_menu_page(
			__( 'Plume AI — Write and Design', 'plume' ),
			__( 'Plume AI', 'plume' ),
			'edit_posts',
			'plume',
			[ DashboardPage::class, 'render' ],
			self::get_menu_icon(),
			30
		);

		// First submenu entry must share parent slug — WordPress uses it to rename
		// the parent item in the submenu list. Label it "Dashboard".
		add_submenu_page( 'plume', __( 'Dashboard', 'plume' ), __( 'Dashboard', 'plume' ), 'edit_posts', 'plume', [ DashboardPage::class, 'render' ] );
		add_submenu_page( 'plume', __( 'Chat', 'plume' ), __( 'Chat', 'plume' ), 'edit_posts', 'plume-chat', [ ChatPage::class, 'render' ] );
		add_submenu_page( 'plume', __( 'Generator', 'plume' ), __( 'Generator', 'plume' ), 'edit_posts', 'plume-generator', [ GeneratorPage::class, 'render' ] );
		add_submenu_page( 'plume', __( 'SEO', 'plume' ), __( 'SEO', 'plume' ), 'edit_posts', 'plume-seo', [ SeoPage::class, 'render' ] );
		add_submenu_page( 'plume', __( 'Images', 'plume' ), __( 'Images', 'plume' ), 'edit_posts', 'plume-images', [ ImagesPage::class, 'render' ] );
		add_submenu_page( 'plume', __( 'Settings', 'plume' ), __( 'Settings', 'plume' ), 'manage_options', 'plume-settings', [ SettingsPage::class, 'render' ] );

		$tier = \Plume\Tiers\TierManager::get_user_tier();
		if ( in_array( $tier, [ 'free', 'trial' ], true ) ) {
			add_submenu_page(
				'plume',
				__( 'Upgrade', 'plume' ),
				__( 'Upgrade ✦', 'plume' ),
				'edit_posts',
				'plume-upgrade',
				[ \Plume\Admin\TierStatusPage::class, 'render' ]
			);
		}
	}

	/**
	 * Build the base64-encoded SVG data URI used as the menu icon.
	 *
	 * Inline SVG — Lucide `feather` icon, zinc-400 (#a1a1aa).
	 *
	 * @since 1.0.0
	 * @return string Data URI string suitable for the $icon_url parameter of add_menu_page().
	 */
	private static function get_menu_icon(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding SVG menu icon for WordPress admin, not obfuscation.
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.24 12.24a6 6 0 0 0-8.49-8.49L5 10.5V19h8.5z"/><path d="M16 8L2 22"/><path d="M17.5 15H9"/></svg>'
		);
	}
}

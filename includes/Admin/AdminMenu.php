<?php
/**
 * Registers the Stilus admin menu and sub-menu pages.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the top-level Stilus admin menu and all sub-menu pages.
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
			__( 'Stilus - Write and Design', 'stilus' ),
			__( 'Stilus', 'stilus' ),
			'edit_posts',
			'stilus',
			[ DashboardPage::class, 'render' ],
			self::get_menu_icon(),
			30
		);

		// First submenu entry must share parent slug — WordPress uses it to rename
		// the parent item in the submenu list. Label it "Dashboard".
		add_submenu_page( 'stilus', __( 'Dashboard', 'stilus' ), __( 'Dashboard', 'stilus' ), 'edit_posts', 'stilus', [ DashboardPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'Chat', 'stilus' ), __( 'Chat', 'stilus' ), 'edit_posts', 'stilus-chat', [ ChatPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'Generator', 'stilus' ), __( 'Generator', 'stilus' ), 'edit_posts', 'stilus-generator', [ GeneratorPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'SEO', 'stilus' ), __( 'SEO', 'stilus' ), 'edit_posts', 'stilus-seo', [ SeoPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'Images', 'stilus' ), __( 'Images', 'stilus' ), 'edit_posts', 'stilus-images', [ ImagesPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'Settings', 'stilus' ), __( 'Settings', 'stilus' ), 'manage_options', 'stilus-settings', [ SettingsPage::class, 'render' ] );

		$tier = \Stilus\Tiers\TierManager::get_user_tier();
		if ( in_array( $tier, [ 'free', 'trial' ], true ) ) {
			add_submenu_page(
				'stilus',
				__( 'Upgrade', 'stilus' ),
				__( 'Upgrade ✦', 'stilus' ),
				'edit_posts',
				'stilus-upgrade',
				[ \Stilus\Admin\TierStatusPage::class, 'render' ]
			);
		}
	}

	/**
	 * Build the base64-encoded SVG data URI used as the menu icon.
	 *
	 * Inline SVG — Lucide `sparkles` icon, zinc-400 (#a1a1aa).
	 *
	 * @since 1.0.0
	 * @return string Data URI string suitable for the $icon_url parameter of add_menu_page().
	 */
	private static function get_menu_icon(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding SVG menu icon for WordPress admin, not obfuscation.
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg>'
		);
	}
}

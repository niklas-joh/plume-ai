<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

class AdminMenu {

	public static function register(): void {
		add_menu_page(
			__( 'WP AI Mind', 'wp-ai-mind' ),
			__( 'AI Mind', 'wp-ai-mind' ),
			'edit_posts',
			'wp-ai-mind',
			[ DashboardPage::class, 'render' ],
			self::get_menu_icon(),
			30
		);

		// First submenu entry must share parent slug — WordPress uses it to rename
		// the parent item in the submenu list. Label it "Dashboard".
		add_submenu_page( 'wp-ai-mind', __( 'Dashboard', 'wp-ai-mind' ), __( 'Dashboard', 'wp-ai-mind' ), 'edit_posts', 'wp-ai-mind', [ DashboardPage::class, 'render' ] );
		add_submenu_page( 'wp-ai-mind', __( 'Chat', 'wp-ai-mind' ), __( 'Chat', 'wp-ai-mind' ), 'edit_posts', 'wp-ai-mind-chat', [ ChatPage::class, 'render' ] );
		add_submenu_page( 'wp-ai-mind', __( 'Generator', 'wp-ai-mind' ), __( 'Generator', 'wp-ai-mind' ), 'edit_posts', 'wp-ai-mind-generator', [ GeneratorPage::class, 'render' ] );
		add_submenu_page( 'wp-ai-mind', __( 'SEO', 'wp-ai-mind' ), __( 'SEO', 'wp-ai-mind' ), 'edit_posts', 'wp-ai-mind-seo', [ SeoPage::class, 'render' ] );
		add_submenu_page( 'wp-ai-mind', __( 'Images', 'wp-ai-mind' ), __( 'Images', 'wp-ai-mind' ), 'edit_posts', 'wp-ai-mind-images', [ ImagesPage::class, 'render' ] );
		add_submenu_page( 'wp-ai-mind', __( 'Usage', 'wp-ai-mind' ), __( 'Usage &amp; Cost', 'wp-ai-mind' ), 'manage_options', 'wp-ai-mind-usage', [ UsagePage::class, 'render' ] );
		add_submenu_page( 'wp-ai-mind', __( 'Settings', 'wp-ai-mind' ), __( 'Settings', 'wp-ai-mind' ), 'manage_options', 'wp-ai-mind-settings', [ SettingsPage::class, 'render' ] );

		$tier = \WP_AI_Mind\Tiers\NJ_Tier_Manager::get_user_tier();
		if ( in_array( $tier, [ 'free', 'trial' ], true ) ) {
			add_submenu_page(
				'wp-ai-mind',
				__( 'Upgrade', 'wp-ai-mind' ),
				__( 'Upgrade ✦', 'wp-ai-mind' ),
				'edit_posts',
				'wp-ai-mind-upgrade',
				[ \WP_AI_Mind\Admin\NJ_Tier_Status_Page::class, 'render' ]
			);
		}
	}

	/** Inline SVG — Lucide `sparkles` icon, zinc-400 (#a1a1aa). */
	private static function get_menu_icon(): string {
		return 'data:image/svg+xml;base64,' . base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding SVG menu icon for WordPress admin, not obfuscation.
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg>'
		);
	}
}

<?php
// includes/Admin/SettingsPage.php
declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Tiers\NJ_Tier_Manager;

/**
 * Renders the WP AI Mind settings admin page.
 *
 * Outputs a React mount point and enqueues the shared admin bundle.
 * The React app reads the mount-point ID or a URL hash to decide
 * which UI to render (settings vs. chat).
 */
class SettingsPage {

	public static function render(): void {
		self::enqueue_assets();
		echo '<div id="wp-ai-mind-settings" class="wp-ai-mind-page"></div>';
	}

	public static function enqueue_assets(): void {
		$asset_file = WP_AI_MIND_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		wp_enqueue_script(
			'wp-ai-mind-admin',
			WP_AI_MIND_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		wp_localize_script(
			'wp-ai-mind-admin',
			'wpAiMindData',
			[
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'restUrl'       => esc_url_raw( rest_url( 'wp-ai-mind/v1' ) ),
				'upgradeUrl'    => esc_url( admin_url( 'admin.php?page=wp-ai-mind-upgrade' ) ),
				'currentPostId' => 0,
				'isPro'         => NJ_Tier_Manager::user_can( 'generator' ),
				'siteTitle'     => get_bloginfo( 'name' ),
				'tier'          => NJ_Tier_Manager::get_user_tier(),
				'features'      => [
					'model_selection' => NJ_Tier_Manager::user_can( 'model_selection' ),
					'own_api_key'     => NJ_Tier_Manager::user_can( 'own_api_key' ),
				],
			]
		);

		wp_enqueue_style(
			'wp-ai-mind-admin',
			WP_AI_MIND_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);
	}
}

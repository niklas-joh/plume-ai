<?php
/**
 * Admin page rendering the plugin settings screen.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Tiers\TierManager;

/**
 * Renders the Plume settings admin page.
 *
 * Outputs a React mount point and enqueues the shared admin bundle.
 * The React app reads the mount-point ID or a URL hash to decide
 * which UI to render (settings vs. chat).
 */
class SettingsPage {

	/**
	 * Output the page markup and enqueue all required assets.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		self::enqueue_assets();
		echo '<div id="plume-settings" class="plume-page"></div>';
	}

	/**
	 * Enqueue the admin script and stylesheet, and localise runtime data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$asset_file = PLUME_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => PLUME_VERSION,
			];

		wp_enqueue_script(
			'plume-admin',
			PLUME_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		wp_localize_script(
			'plume-admin',
			'plumeData',
			[
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'restUrl'       => esc_url_raw( rest_url( 'plume/v1' ) ),
				'upgradeUrl'    => esc_url( admin_url( 'admin.php?page=plume-upgrade' ) ),
				'currentPostId' => 0,
				'isPro'         => TierManager::user_can( 'generator' ),
				'siteTitle'     => get_bloginfo( 'name' ),
				'tier'          => TierManager::get_user_tier(),
				'features'      => [
					'model_selection' => TierManager::user_can( 'model_selection' ),
					'own_api_key'     => TierManager::user_can( 'own_api_key' ),
				],
			]
		);

		wp_enqueue_style(
			'plume-admin',
			PLUME_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);
	}
}

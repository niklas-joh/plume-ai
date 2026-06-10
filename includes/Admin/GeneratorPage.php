<?php
/**
 * Admin page rendering the AI post-generator wizard.
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
 * Renders the Plume post-generator admin page.
 *
 * Outputs a React mount point and enqueues the generator bundle.
 */
class GeneratorPage {

	/**
	 * Output the page markup and enqueue all required assets.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		self::enqueue_assets();
		echo '<div id="plume-generator" class="plume-page"></div>';
	}

	/**
	 * Enqueue the generator script and stylesheet, and localise runtime data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$asset_file = PLUME_DIR . 'assets/generator/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => PLUME_VERSION,
			];

		\wp_enqueue_script(
			'plume-generator',
			PLUME_URL . 'assets/generator/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'plume-generator',
			'plumeData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'plume/v1' ) ),
				'currentPostId' => 0,
				'isPro'         => TierManager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		\wp_enqueue_style(
			'plume-generator',
			PLUME_URL . 'assets/generator/index.css',
			[],
			$asset['version']
		);
	}
}

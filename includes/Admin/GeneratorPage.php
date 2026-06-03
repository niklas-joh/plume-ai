<?php
/**
 * Admin page rendering the AI post-generator wizard.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Tiers\TierManager;

/**
 * Renders the Stilus post-generator admin page.
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
		echo '<div id="stilus-generator" class="stilus-page"></div>';
	}

	/**
	 * Enqueue the generator script and stylesheet, and localise runtime data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$asset_file = STILUS_DIR . 'assets/generator/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => STILUS_VERSION,
			];

		\wp_enqueue_script(
			'stilus-generator',
			STILUS_URL . 'assets/generator/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'stilus-generator',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'stilus/v1' ) ),
				'currentPostId' => 0,
				'isPro'         => TierManager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		\wp_enqueue_style(
			'stilus-generator',
			STILUS_URL . 'assets/generator/index.css',
			[],
			$asset['version']
		);
	}
}

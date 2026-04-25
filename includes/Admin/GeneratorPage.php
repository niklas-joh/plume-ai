<?php
// includes/Admin/GeneratorPage.php
declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Tiers\NJ_Tier_Manager;

/**
 * Renders the WP AI Mind post-generator admin page.
 *
 * Outputs a React mount point and enqueues the generator bundle.
 */
class GeneratorPage {

	public static function render(): void {
		self::enqueue_assets();
		echo '<div id="wp-ai-mind-generator" class="wp-ai-mind-page"></div>';
	}

	public static function enqueue_assets(): void {
		$asset_file = WP_AI_MIND_DIR . 'assets/generator/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		\wp_enqueue_script(
			'wp-ai-mind-generator',
			WP_AI_MIND_URL . 'assets/generator/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'wp-ai-mind-generator',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
				'currentPostId' => 0,
				'isPro'         => NJ_Tier_Manager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		\wp_enqueue_style(
			'wp-ai-mind-generator',
			WP_AI_MIND_URL . 'assets/generator/index.css',
			[],
			$asset['version']
		);
	}
}

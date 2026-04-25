<?php
// includes/Modules/Editor/EditorModule.php
declare( strict_types=1 );
namespace WP_AI_Mind\Modules\Editor;

use WP_AI_Mind\Tiers\NJ_Tier_Manager;

/**
 * Enqueues block-editor assets for the WP AI Mind sidebar panel.
 */
class EditorModule {

	/**
	 * Register WordPress hooks for this module.
	 */
	public static function register(): void {
		add_action( 'enqueue_block_editor_assets', [ self::class, 'enqueue_assets' ] );
	}

	/**
	 * Enqueue the editor script and stylesheet.
	 */
	public static function enqueue_assets(): void {
		$asset_file = WP_AI_MIND_DIR . 'assets/editor/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		wp_enqueue_script(
			'wp-ai-mind-editor',
			WP_AI_MIND_URL . 'assets/editor/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-block-editor' ] ),
			$asset['version'],
			true
		);

		wp_localize_script(
			'wp-ai-mind-editor',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
				'currentPostId' => isset( $GLOBALS['post'] ) ? (int) $GLOBALS['post']->ID : ( isset( $_GET['post'] ) ? \absint( $_GET['post'] ) : 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post ID for editor sidebar; no form processing.
				'isPro'         => NJ_Tier_Manager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		wp_enqueue_style(
			'wp-ai-mind-editor',
			WP_AI_MIND_URL . 'assets/editor/index.css',
			[ 'wp-components' ],
			$asset['version']
		);
	}
}

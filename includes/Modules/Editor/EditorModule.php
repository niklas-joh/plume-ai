<?php
/**
 * Editor module bootstrap — enqueues the Block Editor plugin sidebar assets.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Modules\Editor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Tiers\TierManager;

/**
 * Enqueues block-editor assets for the Plume sidebar panel.
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
		$asset_file = PLUME_DIR . 'assets/editor/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => PLUME_VERSION,
			];

		wp_enqueue_script(
			'plume-editor',
			PLUME_URL . 'assets/editor/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-i18n', 'wp-api-fetch', 'wp-data', 'wp-block-editor' ] ),
			$asset['version'],
			true
		);

		wp_localize_script(
			'plume-editor',
			'plumeData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'plume/v1' ) ),
				'currentPostId' => isset( $GLOBALS['post'] ) ? (int) $GLOBALS['post']->ID : ( isset( $_GET['post'] ) ? \absint( $_GET['post'] ) : 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post ID for editor sidebar; no form processing.
				'isPro'         => TierManager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		wp_enqueue_style(
			'plume-editor',
			PLUME_URL . 'assets/editor/index.css',
			[ 'wp-components' ],
			$asset['version']
		);
	}
}

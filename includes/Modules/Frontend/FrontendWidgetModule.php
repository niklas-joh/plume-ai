<?php
/**
 * Frontend widget module — registers the public-facing AI chat shortcode.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Modules\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Tiers\TierManager;

/**
 * Registers the front-end chat widget as a shortcode and enqueues its assets.
 *
 * The widget is mounted on any page that contains the [stilus_chat] shortcode.
 */
class FrontendWidgetModule {

	/**
	 * Register WordPress hooks for this module.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		\add_shortcode( 'stilus_chat', [ self::class, 'render_shortcode' ] );
	}

	/**
	 * Enqueue the widget script and stylesheet on the front end.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$asset_file = STILUS_DIR . 'assets/frontend/widget.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => STILUS_VERSION,
			];

		\wp_enqueue_script(
			'stilus-widget',
			STILUS_URL . 'assets/frontend/widget.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'stilus-widget',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'stilus/v1' ) ),
				'currentPostId' => \get_the_ID() ? \get_the_ID() : 0,
				'isPro'         => TierManager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		\wp_enqueue_style(
			'stilus-widget',
			STILUS_URL . 'assets/frontend/widget.css',
			[],
			$asset['version']
		);
	}

	/**
	 * Render the shortcode mount point for the front-end widget.
	 *
	 * @since 1.0.0
	 * @param array $atts Shortcode attributes; accepts 'title' (unused by the React app).
	 * @return string HTML div that the React widget mounts into.
	 */
	public static function render_shortcode( array $atts ): string {
		$atts = \shortcode_atts( [ 'title' => '' ], $atts, 'stilus_chat' );
		$id   = 'stilus-widget-' . \absint( \get_the_ID() );
		return '<div id="' . \esc_attr( $id ) . '" class="stilus-widget" data-post-id="' . \absint( \get_the_ID() ) . '"></div>';
	}
}

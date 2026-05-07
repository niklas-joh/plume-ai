<?php
/**
 * Frontend widget module — registers the public-facing AI chat shortcode.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Modules\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_AI_Mind\Tiers\NJ_Tier_Manager;

/**
 * Registers the front-end chat widget as a shortcode and enqueues its assets.
 *
 * The widget is mounted on any page that contains the [wp_ai_mind_chat] shortcode.
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
		\add_shortcode( 'wp_ai_mind_chat', [ self::class, 'render_shortcode' ] );
	}

	/**
	 * Enqueue the widget script and stylesheet on the front end.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function enqueue_assets(): void {
		$asset_file = WP_AI_MIND_DIR . 'assets/frontend/widget.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		\wp_enqueue_script(
			'wp-ai-mind-widget',
			WP_AI_MIND_URL . 'assets/frontend/widget.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'wp-ai-mind-widget',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
				'currentPostId' => \get_the_ID() ? \get_the_ID() : 0,
				'isPro'         => NJ_Tier_Manager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		\wp_enqueue_style(
			'wp-ai-mind-widget',
			WP_AI_MIND_URL . 'assets/frontend/widget.css',
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
		$atts = \shortcode_atts( [ 'title' => '' ], $atts, 'wp_ai_mind_chat' );
		$id   = 'wp-ai-mind-widget-' . \absint( \get_the_ID() );
		return '<div id="' . \esc_attr( $id ) . '" class="wp-ai-mind-widget" data-post-id="' . \absint( \get_the_ID() ) . '"></div>';
	}
}

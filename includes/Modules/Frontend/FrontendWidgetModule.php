<?php
// includes/Modules/Frontend/FrontendWidgetModule.php
declare( strict_types=1 );
namespace WP_AI_Mind\Modules\Frontend;

use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class FrontendWidgetModule {

	public static function register(): void {
		\add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		\add_shortcode( 'wp_ai_mind_chat', [ self::class, 'render_shortcode' ] );
	}

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

	public static function render_shortcode( array $atts ): string {
		$atts = \shortcode_atts( [ 'title' => '' ], $atts, 'wp_ai_mind_chat' );
		$id   = 'wp-ai-mind-widget-' . \absint( \get_the_ID() );
		return '<div id="' . \esc_attr( $id ) . '" class="wp-ai-mind-widget" data-post-id="' . \absint( \get_the_ID() ) . '"></div>';
	}
}

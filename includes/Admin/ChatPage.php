<?php
// includes/Admin/ChatPage.php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Providers\ProviderFactory;
use WP_AI_Mind\Settings\ProviderSettings;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class ChatPage {

	public static function render(): void {
		self::enqueue_assets();
		echo '<div id="wp-ai-mind-chat" class="wp-ai-mind-page"></div>';
	}

	private static function enqueue_assets(): void {
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

		$default_slug        = (string) get_option( 'wp_ai_mind_default_provider', 'claude' );
		$provider_factory    = new ProviderFactory( new ProviderSettings() );
		$default_model_label = 'AI';
		try {
			$default_provider    = $provider_factory->make( '' !== $default_slug ? $default_slug : 'claude' );
			$default_models      = $default_provider->get_models();
			$default_model_id    = $default_provider->get_default_model();
			$default_model_label = $default_models[ $default_model_id ] ?? ucfirst( $default_slug );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Intentional: fall back to 'AI' label on provider failure.
			// Leave default label as 'AI' if factory fails.
		}

		wp_localize_script(
			'wp-ai-mind-admin',
			'wpAiMindData',
			[
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'restUrl'           => esc_url_raw( rest_url( 'wp-ai-mind/v1' ) ),
				'currentPostId'     => 0,
				'isPro'             => NJ_Tier_Manager::user_can( 'chat' ),
				'siteTitle'         => get_bloginfo( 'name' ),
				'defaultModelLabel' => esc_html( $default_model_label ),
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

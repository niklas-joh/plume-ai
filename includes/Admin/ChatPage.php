<?php
/**
 * Admin page rendering the main AI chat interface.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Providers\ProviderFactory;
use Stilus\Settings\ProviderSettings;
use Stilus\Tiers\TierManager;

/**
 * Renders the Stilus chat admin page.
 *
 * Outputs a React mount point and enqueues the shared admin bundle with
 * localised data that includes the default model label for the UI header.
 */
class ChatPage {

	/**
	 * Output the page markup and enqueue all required assets.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		self::enqueue_assets();
		echo '<div id="stilus-chat" class="stilus-page"></div>';
	}

	/**
	 * Enqueue the admin script and stylesheet, and localise runtime data.
	 *
	 * Resolves the default model label by instantiating the configured provider;
	 * falls back to 'AI' if the provider factory throws (e.g. no API key set).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function enqueue_assets(): void {
		$asset_file = STILUS_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => STILUS_VERSION,
			];

		wp_enqueue_script(
			'stilus-admin',
			STILUS_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		$default_slug        = (string) get_option( 'stilus_default_provider', 'claude' );
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
			'stilus-admin',
			'wpAiMindData',
			[
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'restUrl'           => esc_url_raw( rest_url( 'stilus/v1' ) ),
				'currentPostId'     => 0,
				'isPro'             => TierManager::user_can( 'chat' ),
				'siteTitle'         => get_bloginfo( 'name' ),
				'defaultModelLabel' => esc_html( $default_model_label ),
				'defaultProvider'   => $default_slug,
			]
		);

		wp_enqueue_style(
			'stilus-admin',
			STILUS_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);
	}
}

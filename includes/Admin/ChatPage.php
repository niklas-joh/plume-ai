<?php
/**
 * Admin page rendering the main AI chat interface.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Providers\ProviderFactory;
use Plume\Settings\ProviderSettings;
use Plume\Tiers\TierManager;
use Plume\Tools\PostTypeCaps;
use Plume\Tools\ToolRegistry;

/**
 * Renders the Plume chat admin page.
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
		echo '<div id="plume-chat" class="plume-page"></div>';
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
		$asset_file = PLUME_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => PLUME_VERSION,
			];

		wp_enqueue_script(
			'plume-admin',
			PLUME_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		$default_slug        = (string) get_option( 'plume_default_provider', 'claude' );
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
			'plume-admin',
			'plumeData',
			[
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'restUrl'           => esc_url_raw( rest_url( 'plume/v1' ) ),
				'currentPostId'     => 0,
				'isPaid'            => TierManager::is_paid(),
				'siteTitle'         => get_bloginfo( 'name' ),
				'defaultModelLabel' => esc_html( $default_model_label ),
				'defaultProvider'   => $default_slug,
				'publishCaps'       => self::publish_caps(),
			]
		);

		wp_enqueue_style(
			'plume-admin',
			PLUME_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);
	}

	/**
	 * Map each writable post type to whether the current user may publish it.
	 *
	 * The plan review card uses this to offer only the statuses the user can act on:
	 * a Contributor holds edit_posts but not publish_posts, so offering them
	 * "Published" would only produce a 403 when they confirmed the plan.
	 *
	 * @since NEXT_VERSION
	 * @return array<string, bool> Post type slug => whether publishing is permitted.
	 */
	private static function publish_caps(): array {
		$caps = [];

		foreach ( ( new ToolRegistry() )->allowed_post_types() as $post_type ) {
			$type_caps = PostTypeCaps::resolve( (string) $post_type );
			if ( null === $type_caps ) {
				continue;
			}
			$caps[ (string) $post_type ] = current_user_can( $type_caps['publish'] );
		}

		return $caps;
	}
}

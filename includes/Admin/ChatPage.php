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

use Plume\Providers\ManagedDefaults;
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
	 * Resolves the default model label via default_model_label().
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
		$default_model_label = self::default_model_label(
			'' !== $default_slug ? $default_slug : 'claude',
			new ProviderFactory( new ProviderSettings() ),
			new ProviderSettings(),
			TierManager::get_user_tier()
		);

		wp_localize_script(
			'plume-admin',
			'plumeData',
			[
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'restUrl'           => esc_url_raw( rest_url( 'plume/v1' ) ),
				'currentPostId'     => 0,
				'isPaid'            => TierManager::is_paid(),
				'siteTitle'         => get_bloginfo( 'name' ),
				'defaultModelLabel' => $default_model_label,
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
	 * Resolve the label shown for the "Plugin default" model choice.
	 *
	 * Reflects the model a request with no explicit model really uses. With an own API
	 * key (or Ollama) the plugin calls the provider directly and uses its default model.
	 * Without a key the request goes through the managed proxy, whose default depends
	 * on the tier (see ManagedDefaults). Falls back to the raw model id, then the
	 * provider name, and to 'AI' if the provider cannot be created.
	 *
	 * @since NEXT_VERSION
	 * @param string           $slug     Default provider slug.
	 * @param ProviderFactory  $factory  Provider factory.
	 * @param ProviderSettings $settings Provider settings holding the API keys.
	 * @param string           $tier     Current site tier slug.
	 * @return string Human-readable model label.
	 */
	private static function default_model_label( string $slug, ProviderFactory $factory, ProviderSettings $settings, string $tier ): string {
		try {
			$provider = $factory->make( $slug );

			if ( 'ollama' !== $slug && '' === $settings->get_api_key( $slug ) ) {
				$managed_id = ManagedDefaults::model_id( $slug, $tier );
				if ( null !== $managed_id ) {
					return ManagedDefaults::label( $managed_id ) ?? $managed_id;
				}
			}

			$model_id = $provider->get_default_model();
			return $provider->get_models()[ $model_id ] ?? ( '' !== $model_id ? $model_id : ucfirst( $slug ) );
		} catch ( \Throwable $e ) {
			return 'AI';
		}
	}

	/**
	 * Map each writable post type to whether the current user may publish it.
	 *
	 * The plan review card uses this to offer only the statuses the user can act on:
	 * a Contributor holds edit_posts but not publish_posts, so offering them
	 * "Published" would only produce a 403 when they confirmed the plan.
	 *
	 * @since 1.13.2
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

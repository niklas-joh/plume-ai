<?php
// includes/Modules/Chat/SettingsRestController.php
declare( strict_types=1 );

namespace WP_AI_Mind\Modules\Chat;

use WP_AI_Mind\Settings\ProviderSettings;

/**
 * REST controller for plugin settings.
 *
 * Routes:
 *   GET  /wp-ai-mind/v1/settings — returns all plugin settings (api_keys masked).
 *   POST /wp-ai-mind/v1/settings — saves plugin settings.
 *
 * Both routes require the `manage_options` capability.
 */
class SettingsRestController {

	private const NAMESPACE = 'wp-ai-mind/v1';

	// ── Route registration ────────────────────────────────────────────────────

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_settings' ],
					'permission_callback' => [ $this, 'check_permission' ],
				],
			]
		);
	}

	// ── GET handler ───────────────────────────────────────────────────────────

	/**
	 * Returns all plugin settings. API key values are masked if set.
	 *
	 * Response includes `is_pro` (bool) indicating whether the Pro licence is
	 * active. Added alongside the `isPro` JS field rename so both layers stay
	 * consistent.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response
	 */
	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_REST_Server callback signature.
		$provider_settings = $this->make_provider_settings();

		$data = [
			'default_provider'     => sanitize_text_field( (string) get_option( 'wp_ai_mind_default_provider', 'claude' ) ),
			'image_provider'       => sanitize_text_field( (string) get_option( 'wp_ai_mind_image_provider', '' ) ),
			'site_voice'           => sanitize_text_field( (string) get_option( 'wp_ai_mind_site_voice', '' ) ),
			'enabled_modules'      => array_keys( array_filter( (array) get_option( 'wp_ai_mind_modules', [] ) ) ),
			'api_keys'             => [
				'claude'     => $this->mask_key( $provider_settings->has_key( 'claude' ) ),
				'openai'     => $this->mask_key( $provider_settings->has_key( 'openai' ) ),
				'gemini'     => $this->mask_key( $provider_settings->has_key( 'gemini' ) ),
				'ollama_url' => sanitize_text_field( (string) get_option( 'wp_ai_mind_ollama_url', '' ) ),
			],
			'allowed_post_types'   => \get_option( 'wp_ai_mind_allowed_post_types', [ 'post', 'page' ] ),
			'available_post_types' => $this->get_public_post_types(),
			'enable_write_tools'   => (bool) \get_option( 'wp_ai_mind_enable_write_tools', false ),
			'is_pro'               => (bool) \wp_ai_mind_is_pro(),
		];

		return rest_ensure_response( $data );
	}

	// ── POST handler ──────────────────────────────────────────────────────────

	/**
	 * Saves plugin settings from the request body.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_settings = $this->make_provider_settings();
		$json_params       = $request->get_json_params();
		$params            = ! empty( $json_params ) ? $json_params : [];

		// Scalar options.
		$default_provider = $request->get_param( 'default_provider' );
		if ( null !== $default_provider ) {
			update_option( 'wp_ai_mind_default_provider', sanitize_text_field( (string) $default_provider ) );
		}

		$image_provider = $request->get_param( 'image_provider' );
		if ( null !== $image_provider ) {
			update_option( 'wp_ai_mind_image_provider', sanitize_text_field( (string) $image_provider ) );
		}

		$site_voice = $request->get_param( 'site_voice' );
		if ( null !== $site_voice ) {
			update_option( 'wp_ai_mind_site_voice', sanitize_text_field( (string) $site_voice ) );
		}

		// Module toggles — convert string list to boolean map matching ModuleRegistry.
		$enabled_modules = $request->get_param( 'enabled_modules' );
		if ( null !== $enabled_modules ) {
			$enabled_list = array_map( 'sanitize_text_field', (array) $enabled_modules );
			$all_slugs    = array_keys( ( new \WP_AI_Mind\Core\ModuleRegistry() )->get_all() );
			$bool_map     = [];
			foreach ( $all_slugs as $slug ) {
				$bool_map[ $slug ] = in_array( $slug, $enabled_list, true );
			}
			update_option( 'wp_ai_mind_modules', $bool_map );
		}

		// API keys — skip any that are the mask placeholder (i.e. unchanged).
		$api_keys = $request->get_param( 'api_keys' );
		if ( is_array( $api_keys ) ) {
			$provider_map = [ 'claude', 'openai', 'gemini' ];

			foreach ( $provider_map as $provider ) {
				if ( isset( $api_keys[ $provider ] ) && '••••••' !== $api_keys[ $provider ] ) {
					$provider_settings->set_api_key( $provider, sanitize_text_field( (string) $api_keys[ $provider ] ) );
				}
			}

			if ( isset( $api_keys['ollama_url'] ) && '••••••' !== $api_keys['ollama_url'] ) {
				update_option( 'wp_ai_mind_ollama_url', esc_url_raw( (string) $api_keys['ollama_url'] ) );
			}
		}

		if ( isset( $params['allowed_post_types'] ) && \is_array( $params['allowed_post_types'] ) ) {
			$sanitised = \array_map( 'sanitize_key', $params['allowed_post_types'] );
			$valid     = \array_keys( \get_post_types( [ 'public' => true ] ) );
			$sanitised = \array_values( \array_intersect( $sanitised, $valid ) );
			\update_option( 'wp_ai_mind_allowed_post_types', $sanitised );
		}

		if ( isset( $params['enable_write_tools'] ) ) {
			\update_option( 'wp_ai_mind_enable_write_tools', (bool) $params['enable_write_tools'] );
		}

		return rest_ensure_response( [ 'saved' => true ] );
	}

	// ── Permission callback ───────────────────────────────────────────────────

	/**
	 * Checks that the current user has the `manage_options` capability.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission(): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions.', 'wp-ai-mind' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns a mask placeholder if the key is set, or an empty string if not.
	 *
	 * @param bool $has_key Whether the provider has a key stored.
	 * @return string
	 */
	private function mask_key( bool $has_key ): string {
		return $has_key ? '••••••' : '';
	}

	/**
	 * Mask a raw key string directly (used in the original spec signature).
	 *
	 * @param string $key The raw key value.
	 * @return string
	 */
	private function mask( string $key ): string {
		return '' !== $key ? '••••••' : '';
	}

	/**
	 * Factory method for ProviderSettings — overridable in tests.
	 *
	 * @return ProviderSettings
	 */
	protected function make_provider_settings(): ProviderSettings {
		return new ProviderSettings();
	}

	/**
	 * Returns all public post types as slug/label pairs.
	 *
	 * @return array<int, array{slug: string, label: string}>
	 */
	private function get_public_post_types(): array {
		$post_types = \get_post_types( [ 'public' => true ], 'objects' );
		$result     = [];
		foreach ( $post_types as $slug => $obj ) {
			$result[] = [
				'slug'  => $slug,
				'label' => $obj->labels->singular_name,
			];
		}
		return $result;
	}
}

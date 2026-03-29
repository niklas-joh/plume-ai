<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Settings\ProviderSettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OnboardingRestController {

	public static function register_routes(): void {
		register_rest_route(
			'wp-ai-mind/v1',
			'/onboarding',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'save' ],
				'permission_callback' => [ self::class, 'check_permission' ],
				'args'                => [
					'seen'           => [
						'type'     => 'boolean',
						'required' => false,
					],
					'provider'       => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'openai', 'claude', 'gemini' ],
					],
					'api_keys'       => [
						'type'     => 'object',
						'required' => false,
					],
					'image_provider' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'openai', 'gemini' ],
					],
				],
			]
		);
	}

	public static function save( WP_REST_Request $request ): WP_REST_Response {
		$seen = $request->get_param( 'seen' );

		if ( true === $seen ) {
			update_option( 'wp_ai_mind_onboarding_seen', true );
		} elseif ( false === $seen ) {
			delete_option( 'wp_ai_mind_onboarding_seen' );
		}

		$api_keys = $request->get_param( 'api_keys' );
		$provider = $request->get_param( 'provider' );
		if ( $provider ) {
			update_option( 'wp_ai_mind_default_provider', sanitize_text_field( $provider ) );
		}
		if ( $api_keys && is_array( $api_keys ) ) {
			$valid    = [ 'openai', 'claude', 'gemini' ];
			$settings = static::make_provider_settings();
			foreach ( $api_keys as $p => $key ) {
				if ( in_array( $p, $valid, true ) && ! empty( $key ) ) {
					$settings->set_api_key( $p, sanitize_text_field( (string) $key ) );
				}
			}
		}

		$image_provider = $request->get_param( 'image_provider' );
		if ( $image_provider ) {
			update_option( 'wp_ai_mind_image_provider', sanitize_text_field( $image_provider ) );
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Checks that the current user has the `manage_options` capability.
	 *
	 * @return bool|\WP_Error
	 */
	public static function check_permission(): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage plugin settings.', 'wp-ai-mind' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Factory method for ProviderSettings — overridable in tests.
	 *
	 * @return ProviderSettings
	 */
	protected static function make_provider_settings(): ProviderSettings {
		return new ProviderSettings();
	}
}

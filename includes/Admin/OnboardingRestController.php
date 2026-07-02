<?php
/**
 * REST controller for the onboarding wizard flow.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Admin;

use Plume\Settings\ProviderSettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the onboarding wizard — marks the wizard seen and saves initial API settings.
 *
 * @since 1.0.0
 */
class OnboardingRestController {

	/**
	 * Registers the onboarding REST route.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'plume/v1',
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
						'type'                 => 'object',
						'required'             => false,
						'additionalProperties' => [
							'type' => 'string',
						],
						'sanitize_callback'    => static function ( $value ) {
							if ( ! is_array( $value ) ) {
								return [];
							}
							return array_map( 'sanitize_text_field', $value );
						},
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

	/**
	 * Handles the onboarding POST endpoint.
	 *
	 * Saves provider, API keys, and onboarding-seen state from the request.
	 * API keys are accepted on every tier (WP.org Guideline 5 — bringing your
	 * own key is never plan-gated).
	 *
	 * @since 1.0.0
	 * @since NEXT_VERSION Removed the own_api_key tier gate.
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|\WP_Error 200 on success.
	 */
	public static function save( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$seen = $request->get_param( 'seen' );

		if ( true === $seen ) {
			update_option( 'plume_onboarding_seen', true );
		} elseif ( false === $seen ) {
			delete_option( 'plume_onboarding_seen' );
		}

		$api_keys = $request->get_param( 'api_keys' );
		$provider = $request->get_param( 'provider' );
		if ( $provider ) {
			update_option( 'plume_default_provider', sanitize_text_field( $provider ) );
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
			update_option( 'plume_image_provider', sanitize_text_field( $image_provider ) );
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
				__( 'You do not have permission to manage plugin settings.', 'plume' ),
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

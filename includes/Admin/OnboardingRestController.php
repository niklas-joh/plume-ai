<?php
/**
 * REST controller for the onboarding wizard flow.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Admin;

use Stilus\Settings\ProviderSettings;
use Stilus\Tiers\TierManager;
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
			'stilus/v1',
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
	 * Returns 403 if the current user's tier does not include the own_api_key feature
	 * and api_keys are present in the payload.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|\WP_Error 200 on success; WP_Error 403 if tier gate fails.
	 */
	public static function save( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$seen = $request->get_param( 'seen' );

		if ( true === $seen ) {
			update_option( 'stilus_onboarding_seen', true );
		} elseif ( false === $seen ) {
			delete_option( 'stilus_onboarding_seen' );
		}

		$api_keys = $request->get_param( 'api_keys' );
		$provider = $request->get_param( 'provider' );
		if ( $provider ) {
			update_option( 'stilus_default_provider', sanitize_text_field( $provider ) );
		}
		if ( $api_keys && is_array( $api_keys ) ) {
			if ( ! TierManager::user_can( 'own_api_key' ) ) {
				return new \WP_Error(
					'rest_plan_required',
					__( 'API key management requires the Pro BYOK plan.', 'stilus' ),
					[ 'status' => 403 ]
				);
			}

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
			update_option( 'stilus_image_provider', sanitize_text_field( $image_provider ) );
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
				__( 'You do not have permission to manage plugin settings.', 'stilus' ),
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

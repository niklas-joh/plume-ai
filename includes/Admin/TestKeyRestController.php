<?php
/**
 * REST controller for testing AI provider API key validity.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller that validates an API key by making a minimal live call.
 *
 * Route: POST /plume/v1/test-key
 * Requires the manage_options capability.
 */
class TestKeyRestController {

	/**
	 * Register the test-key REST route.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'plume/v1',
			'/test-key',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => [ self::class, 'check_permission' ],
				'args'                => [
					'provider' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'openai', 'claude', 'gemini' ],
					],
					'api_key'  => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Make a minimal live API call to validate the given key.
	 *
	 * Returns 200 on a valid key, 400 'invalid_key' when the provider rejects the
	 * key, or 502 when the check could not tell ('request_failed' for a network
	 * error, 'verification_failed' for any other provider response). The raw
	 * provider error message is forwarded to the client so users can diagnose
	 * mistyped keys without leaving the settings UI.
	 *
	 * @since 1.0.0
	 * @since NEXT_VERSION Only authentication rejections are reported as 'invalid_key';
	 *                      other provider failures return 502 'verification_failed'
	 *                      instead of being mislabelled as an invalid key.
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$provider = $request->get_param( 'provider' );
		$api_key  = $request->get_param( 'api_key' );

		switch ( $provider ) {
			case 'openai':
				$result = wp_remote_get(
					'https://api.openai.com/v1/models',
					[
						'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
						'timeout' => 10,
					]
				);
				break;

			case 'claude':
				$result = wp_remote_post(
					'https://api.anthropic.com/v1/messages',
					[
						'headers' => [
							'x-api-key'         => $api_key,
							'anthropic-version' => '2023-06-01',
							'Content-Type'      => 'application/json',
						],
						'body'    => wp_json_encode(
							[
								'model'      => apply_filters( 'plume_test_key_claude_model', 'claude-haiku-4-5-20251001' ),
								'max_tokens' => 1,
								'messages'   => [
									[
										'role'    => 'user',
										'content' => 'hi',
									],
								],
							]
						),
						'timeout' => 10,
					]
				);
				break;

			case 'gemini':
				// Key sent as a header to avoid exposure in server access logs and browser history.
				$result = wp_remote_get(
					'https://generativelanguage.googleapis.com/v1beta/models',
					[
						'headers' => [ 'x-goog-api-key' => $api_key ],
						'timeout' => 10,
					]
				);
				break;

			default:
				return new \WP_Error( 'unsupported_provider', __( 'Unsupported provider.', 'plume' ), [ 'status' => 400 ] );
		}

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'request_failed', $result->get_error_message(), [ 'status' => 502 ] );
		}

		$code = wp_remote_retrieve_response_code( $result );
		if ( 200 === $code ) {
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		$raw_body = (string) wp_remote_retrieve_body( $result );
		$body     = json_decode( $raw_body, true );
		$detail   = $body['error']['message'] ?? $body['error']['code'] ?? '';
		$detail   = is_scalar( $detail ) ? (string) $detail : '';

		if ( self::is_key_rejection( (string) $provider, (int) $code, $raw_body ) ) {
			return new \WP_Error(
				'invalid_key',
				'' !== $detail ? $detail : /* translators: generic API key error */ __( 'Invalid API key.', 'plume' ),
				[ 'status' => 400 ]
			);
		}

		// Anything else (rate limit, outage, billing, a retired probe model) says
		// nothing about the key. Callers that refuse to store a rejected key must
		// be able to tell the two apart.
		return new \WP_Error(
			'verification_failed',
			'' !== $detail
				/* translators: %s: error message returned by the AI provider. */
				? sprintf( __( 'The key could not be verified: %s', 'plume' ), $detail )
				: __( 'The key could not be verified. The provider did not give a reason.', 'plume' ),
			[ 'status' => 502 ]
		);
	}

	/**
	 * Whether a provider response means the API key itself was rejected.
	 *
	 * OpenAI and Anthropic answer 401 (or 403) for a bad key. Gemini answers 400
	 * with the reason `API_KEY_INVALID`, and uses 400 for unrelated request errors
	 * too, so its body has to be inspected.
	 *
	 * @since NEXT_VERSION
	 * @param string $provider Provider slug.
	 * @param int    $status   HTTP status returned by the provider.
	 * @param string $raw_body Raw response body returned by the provider.
	 * @return bool True when the response is an authentication rejection.
	 */
	private static function is_key_rejection( string $provider, int $status, string $raw_body ): bool {
		if ( in_array( $status, [ 401, 403 ], true ) ) {
			return true;
		}

		return 'gemini' === $provider
			&& 400 === $status
			&& ( str_contains( $raw_body, 'API_KEY_INVALID' ) || str_contains( $raw_body, 'API key not valid' ) );
	}

	/**
	 * Check that the current user has the manage_options capability.
	 *
	 * @since 1.0.0
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
}

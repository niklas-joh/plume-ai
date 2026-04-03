<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TestKeyRestController {

	public static function register_routes(): void {
		register_rest_route(
			'wp-ai-mind/v1',
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
	 * Makes a minimal live API call to validate the given key.
	 *
	 * @param WP_REST_Request $request
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
								'model'      => apply_filters( 'wp_ai_mind_test_key_claude_model', 'claude-haiku-4-5-20251001' ),
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
				return new \WP_Error( 'unsupported_provider', __( 'Unsupported provider.', 'wp-ai-mind' ), [ 'status' => 400 ] );
		}

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'request_failed', $result->get_error_message(), [ 'status' => 502 ] );
		}

		$code = wp_remote_retrieve_response_code( $result );
		if ( 200 === $code ) {
			return new WP_REST_Response( [ 'success' => true ], 200 );
		}

		$body = json_decode( wp_remote_retrieve_body( $result ), true );
		$msg  = $body['error']['message']
			?? $body['error']['code']
			?? /* translators: generic API key error */ __( 'Invalid API key.', 'wp-ai-mind' );

		return new \WP_Error( 'invalid_key', $msg, [ 'status' => 400 ] );
	}

	/**
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
}

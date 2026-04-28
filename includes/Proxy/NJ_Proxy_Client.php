<?php
/**
 * Sends authenticated requests to the Cloudflare Worker AI proxy.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends Bearer-token-authenticated requests to the Cloudflare Worker proxy.
 *
 * Free/Trial/Pro Managed users are routed through this class.
 * Pro BYOK tier bypasses this class entirely and routes via ClaudeProvider.
 */
class NJ_Proxy_Client {

	/**
	 * Send a chat request through the Cloudflare proxy.
	 *
	 * @param array<array{role: string, content: string}> $messages Chat message history.
	 * @param array<string, mixed>                        $options  Supports 'model', 'max_tokens', 'system'.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function chat( array $messages, array $options = [] ): array|WP_Error {
		$token = NJ_Site_Registration::get_site_token();
		if ( empty( $token ) ) {
			return new WP_Error( 'not_registered', __( 'Site not registered with AI proxy.', 'wp-ai-mind' ) );
		}

		$user_id = get_current_user_id();

		// Fail-fast pre-check (WordPress meta). Cloudflare KV is authoritative for enforcement.
		if ( ! NJ_Usage_Tracker::check_limit( $user_id ) ) {
			return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
		}

		$payload = [
			'messages' => $messages,
		];

		if ( isset( $options['model'] ) ) {
			$payload['model'] = $options['model'];
		}
		if ( isset( $options['max_tokens'] ) ) {
			$payload['max_tokens'] = $options['max_tokens'];
		}
		if ( isset( $options['system'] ) ) {
			$payload['system'] = $options['system'];
		}

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'json_encode_failed', __( 'Failed to encode request payload.', 'wp-ai-mind' ) );
		}

		$response = wp_remote_post(
			NJ_Tier_Config::get_proxy_url() . '/v1/chat',
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => $body_json,
				'timeout' => 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( 429 === $code ) {
			return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
		}

		if ( 401 === $code ) {
			// Token may be stale — clear it so maybe_register() re-issues on next admin_init.
			// Re-registration is async; the current request cannot be retried transparently.
			// TODO #326: inline register() + retry once to avoid user-visible auth errors.
			delete_option( NJ_Site_Registration::OPTION_TOKEN );
			return new WP_Error( 'proxy_auth_failed', __( 'Proxy authentication failed. Please reload the page and try again.', 'wp-ai-mind' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'proxy_error', $body['error'] ?? sprintf( 'Proxy returned HTTP %d', $code ) );
		}

		// Mirror usage locally for dashboard display only. KV is authoritative for enforcement.
		if ( isset( $body['usage']['input_tokens'], $body['usage']['output_tokens'] ) ) {
			$tokens = (int) $body['usage']['input_tokens'] + (int) $body['usage']['output_tokens'];
			NJ_Usage_Tracker::log_usage( $tokens, $user_id );
		}

		return $body;
	}
}

<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends signed requests to the Cloudflare Worker proxy for Free/Trial/Pro Managed users.
 * Pro BYOK tier bypasses this class entirely and routes directly through ClaudeProvider.
 */
class NJ_Proxy_Client {

	private static function get_proxy_url(): string {
		if ( defined( 'WP_AI_MIND_PROXY_URL' ) && '' !== WP_AI_MIND_PROXY_URL ) {
			return WP_AI_MIND_PROXY_URL;
		}
		return (string) get_option( 'wp_ai_mind_proxy_url', '' );
	}

	/**
	 * Send a chat request through the Cloudflare proxy.
	 *
	 * @param array<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                        $options  Supports 'model', 'max_tokens'.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function chat( array $messages, array $options = [] ): array|WP_Error {
		$url = self::get_proxy_url();
		if ( empty( $url ) ) {
			return new WP_Error( 'proxy_not_configured', __( 'Proxy URL not configured.', 'wp-ai-mind' ) );
		}

		$user_id = get_current_user_id();
		$tier    = NJ_Tier_Manager::get_user_tier( $user_id );

		// Fail-fast pre-check using local meta. Cloudflare KV is the authoritative limit.
		if ( ! NJ_Usage_Tracker::check_limit( $user_id ) ) {
			return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
		}

		$payload = [
			'user_id'    => $user_id,
			'tier'       => $tier,
			'messages'   => $messages,
			'model'      => $options['model'] ?? null,
			'max_tokens' => $options['max_tokens'] ?? null,
		];

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'json_encode_failed', __( 'Failed to encode request payload.', 'wp-ai-mind' ) );
		}

		$response = wp_remote_post(
			trailingslashit( $url ) . 'v1/chat',
			[
				'headers' => [
					'Content-Type'   => 'application/json',
					'X-WP-Signature' => self::sign( $body_json ),
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

	private static function sign( string $body ): string {
		if ( ! defined( 'WP_AI_MIND_PROXY_SECRET' ) || '' === WP_AI_MIND_PROXY_SECRET ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WP AI Mind] WP_AI_MIND_PROXY_SECRET is not defined in wp-config.php' );
			return ''; // Worker will reject the request with 401 — misconfiguration is visible in logs.
		}
		return hash_hmac( 'sha256', $body, WP_AI_MIND_PROXY_SECRET );
	}
}

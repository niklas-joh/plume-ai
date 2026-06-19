<?php
/**
 * Sends authenticated requests to the Cloudflare Worker AI proxy.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Proxy;

use WP_Error;
use Plume\Tiers\TierConfig;
use Plume\Tiers\UsageTracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends Bearer-token-authenticated requests to the Cloudflare Worker proxy.
 *
 * Free/Trial/Pro Managed users are routed through this class for all providers
 * (Claude, OpenAI, Gemini). The Pro BYOK tier bypasses this class entirely and
 * calls the provider's own API directly.
 *
 * @since 1.2.0
 */
class ProxyClient {

	/**
	 * Send a chat request through the Cloudflare proxy.
	 *
	 * @since 1.2.0
	 * @param array<array{role: string, content: string}> $messages  Chat message history.
	 * @param array<string, mixed>                        $options   Supports 'model', 'max_tokens', 'system', 'tools'.
	 * @param string                                      $provider  Provider slug: 'claude', 'openai', or 'gemini'. Defaults to 'claude'.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function chat( array $messages, array $options = [], string $provider = 'claude' ): array|WP_Error {
		$token = SiteRegistration::get_site_token();
		if ( empty( $token ) ) {
			// Inline registration risks a loopback deadlock on single-worker setups because
			// the Worker's challenge callback is a fresh HTTP request that would queue behind
			// the in-flight request.
			if ( ! has_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] ) ) {
				add_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] );
			}
			return new WP_Error( 'not_registered', __( 'Site not connected to Plume AI - Write and Design. Please reload the page.', 'plume' ) );
		}

		$user_id = get_current_user_id();

		// Fail-fast pre-check (WordPress meta). Cloudflare KV is authoritative for enforcement.
		if ( ! UsageTracker::check_limit( $user_id ) ) {
			return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'plume' ) );
		}

		$payload = [
			'messages' => $messages,
			'provider' => $provider,
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
		if ( ! empty( $options['tools'] ) ) {
			$payload['tools'] = $options['tools'];
		}

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			return new WP_Error( 'json_encode_failed', __( 'Failed to encode request payload.', 'plume' ) );
		}

		$response = wp_remote_post(
			TierConfig::get_proxy_url() . '/v1/chat',
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
			return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'plume' ) );
		}

		if ( 401 === $code ) {
			// Token may be stale — clear it so maybe_register() re-issues on next admin_init.
			// Re-registration is async; the current request cannot be retried transparently.
			// TODO #326: inline register() + retry once to avoid user-visible auth errors.
			delete_option( SiteRegistration::OPTION_TOKEN );
			if ( ! has_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] ) ) {
				add_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] );
			}
			return new WP_Error( 'auth_failed', __( 'Connection to Plume AI - Write and Design failed. Please reload the page and try again.', 'plume' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			// translators: %d is the HTTP status code returned by the service.
			return new WP_Error( 'service_error', $body['error'] ?? sprintf( __( 'Plume AI - Write and Design returned HTTP %d', 'plume' ), $code ) );
		}

		// Mirror usage locally for dashboard display only — KV is authoritative for quota enforcement.
		// The proxy stores weighted tokens (raw × model weight), so the KV quota and local counter
		// will diverge for high-weight models (e.g. Claude Opus at ×15). This is intentional:
		// the dashboard shows raw API tokens consumed while quota enforcement operates on
		// weighted tokens in KV to keep billing proportional across providers and models.
		if ( isset( $body['usage']['input_tokens'], $body['usage']['output_tokens'] ) ) {
			$tokens = (int) $body['usage']['input_tokens'] + (int) $body['usage']['output_tokens'];
			UsageTracker::log_usage( $tokens, $user_id );
		}

		return $body;
	}
}

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
	 * The Worker's KV store is the sole source of truth for credit enforcement —
	 * there is no local WordPress-side pre-check; a request that has exhausted
	 * its credits still reaches the Worker and is rejected there with a 429.
	 *
	 * @since 1.2.0
	 * @param array<array{role: string, content: string}> $messages  Chat message history.
	 * @param string                                      $feature  Feature tag the Worker uses for credit-cost lookup: 'chat', 'generator', or 'seo'. Image generation routes through generate_image() → post() directly and never reaches chat().
	 * @param array<string, mixed>                        $options   Supports 'model', 'max_tokens', 'system', 'tools'.
	 * @param string                                      $provider  Provider slug: 'claude', 'openai', or 'gemini'. Defaults to 'claude'.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function chat( array $messages, string $feature, array $options = [], string $provider = 'claude' ): array|WP_Error {
		$token = SiteRegistration::get_site_token();
		if ( empty( $token ) ) {
			// Inline registration risks a loopback deadlock on single-worker setups because
			// the Worker's challenge callback is a fresh HTTP request that would queue behind
			// the in-flight request.
			if ( ! has_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] ) ) {
				add_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] );
			}
			// Routed through SiteRegistration so a permanent verification failure on
			// record (site_unreachable) takes precedence over the generic "still
			// connecting" not_registered message — the two call sites can't drift.
			// This code (or 'not_registered') is one of REGISTRATION_RETRY_CODES in
			// src/admin/components/Chat/ChatApp.jsx; site_unreachable is deliberately
			// NOT in that set, so it surfaces immediately instead of being retried.
			return SiteRegistration::get_unavailable_error();
		}

		$user_id = get_current_user_id();

		$payload = [
			'messages' => $messages,
			'provider' => $provider,
			'feature'  => $feature,
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
			// Token may be stale — clear it so maybe_register() re-issues on the next proxy call.
			// Re-registration is async; the current request cannot be retried transparently.
			// TODO #326: inline register() + retry once to avoid user-visible auth errors.
			delete_option( SiteRegistration::OPTION_TOKEN );
			if ( ! has_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] ) ) {
				add_action( 'shutdown', [ SiteRegistration::class, 'maybe_register' ] );
			}
			// This code is one of REGISTRATION_RETRY_CODES in src/admin/components/Chat/ChatApp.jsx,
			// which silently retries the request instead of surfacing this to the user immediately.
			return new WP_Error( 'auth_failed', __( 'Reconnecting this site to Plume AI - Write and Design. Please try sending your message again in a moment.', 'plume' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			// translators: %d is the HTTP status code returned by the service.
			return new WP_Error( 'service_error', $body['error'] ?? sprintf( __( 'Plume AI - Write and Design returned HTTP %d', 'plume' ), $code ) );
		}

		// Chat credits are logged once, as an accumulated sum across all agentic-loop
		// iterations, by ChatRestController::send_message() after the loop completes
		// (each iteration is billed individually by the Worker; PHP sums what it reports).
		// The per-iteration ProxyClient call must not additionally log them here.
		if ( isset( $body['credits_charged'] ) && 'chat' !== $feature ) {
			UsageTracker::log_usage( (int) $body['credits_charged'], $user_id );
		}

		return $body;
	}
}

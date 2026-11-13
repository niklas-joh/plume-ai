<?php
/**
 * Thin client for the managed Worker's GET /v1/config endpoint.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tiers;

use Plume\Proxy\SiteRegistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches this site's configuration from the Worker.
 *
 * Owns the transport concerns shared by every consumer of `/v1/config` (the credit
 * limit and the managed default models): token check, bearer header, timeout,
 * error handling, JSON decoding and the tier guard. Consumers validate and cache
 * only the fields they need.
 *
 * @since 2.0.0
 */
final class WorkerConfig {

	/**
	 * HTTP timeout, in seconds, for the live config fetch.
	 *
	 * Deliberately short: this runs on a cache miss while rendering admin pages or
	 * resolving a usage summary, so a slow or unreachable Worker must not stall the
	 * request. Callers fall back to hardcoded constants on timeout.
	 *
	 * @since 2.0.0
	 */
	public const TIMEOUT = 3;

	/**
	 * Per-request memo of fetched config bodies, keyed by tier slug.
	 *
	 * Both `UsageTracker` (credit limit) and `ManagedCatalogue` (model catalogue)
	 * derive different fields from the same `/v1/config` body. On a cold cache an
	 * admin page needing both would otherwise make two identical HTTP round-trips,
	 * each up to self::TIMEOUT. Memoising the decoded result (including a null
	 * failure) collapses that to one call per tier per request. Each consumer keeps
	 * its own validation and transient; they just share the underlying fetch.
	 *
	 * @since 2.1.0
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $memo = [];

	/**
	 * Fetch the Worker's config for this site, memoised per request.
	 *
	 * Bearer-authenticated with the site's own token; the Worker resolves the tier
	 * from the token itself rather than trusting a caller-supplied value. That
	 * resolved tier can disagree with $tier (TierManager::get_user_tier() locally
	 * downgrades an unverified pro site to 'free' while the Worker's SiteRecord
	 * still says 'pro_managed'), so the response's `tier` must equal $tier or the
	 * result is discarded; otherwise a value could be cached under the wrong tier's
	 * key. Read-only: unlike /rotate-secret, this never mutates the Worker-side record.
	 *
	 * The result (including a null failure) is cached in a static array for the
	 * duration of the request, so repeated callers share a single HTTP round-trip.
	 *
	 * @since 2.0.0
	 * @param string $tier Tier slug the caller will cache the result under.
	 * @return array<string, mixed>|null Decoded response body, or null when the site is
	 *                                   unregistered, the request fails, the body is not
	 *                                   a JSON array, or the tier does not match.
	 */
	public static function fetch( string $tier ): ?array {
		if ( ! array_key_exists( $tier, self::$memo ) ) {
			self::$memo[ $tier ] = self::request( $tier );
		}

		return self::$memo[ $tier ];
	}

	/**
	 * Clear the per-request memo.
	 *
	 * Production code never needs this (the memo lives for one PHP request); it exists
	 * so unit tests, which share one PHP process, can start each case with a cold memo.
	 *
	 * @internal
	 * @since 2.1.0
	 */
	public static function reset_memo(): void {
		self::$memo = [];
	}

	/**
	 * Perform the live `/v1/config` HTTP request and validate the response.
	 *
	 * @since 2.1.0
	 * @param string $tier Tier slug the response's `tier` field must match.
	 * @return array<string, mixed>|null Decoded response body, or null on any failure.
	 */
	private static function request( string $tier ): ?array {
		$token = SiteRegistration::get_site_token();
		if ( '' === $token ) {
			return null;
		}

		$response = wp_remote_get(
			TierConfig::get_proxy_url() . '/v1/config',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				'timeout' => self::TIMEOUT,
			]
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		if ( ! isset( $body['tier'] ) || $tier !== $body['tier'] ) {
			return null;
		}

		return $body;
	}
}

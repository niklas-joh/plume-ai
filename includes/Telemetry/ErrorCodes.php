<?php
/**
 * Maps provider failures onto a fixed list of error categories.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Providers\HttpTimeout;
use Plume\Providers\ProviderException;

/**
 * Normalises any failure into one of LIST.
 *
 * Only machine-readable fields are read: the explicit error code, the code or type in
 * the provider's raw error body, and the HTTP status. The exception message is never
 * read, because it can contain prompt text, keys or URLs.
 *
 * @since NEXT_VERSION
 */
final class ErrorCodes {

	/**
	 * Every category an event may carry.
	 *
	 * @since NEXT_VERSION
	 * @var string[]
	 */
	public const LIST = [
		'auth_invalid',
		'rate_limited',
		'quota_exceeded',
		'model_not_found',
		'timeout',
		'network',
		'server_error',
		'provider_unavailable',
		'bad_request',
		'content_policy',
		'not_registered',
		'site_unreachable',
		'invalid_response',
		'media_error',
		'unsupported',
		'other',
	];

	/**
	 * Explicit error code => category.
	 *
	 * @since NEXT_VERSION
	 */
	private const CODE_MAP = [
		HttpTimeout::ERROR_CODE     => 'timeout',
		'rate_limit_exceeded'       => 'quota_exceeded',
		'auth_failed'               => 'auth_invalid',
		'not_registered'            => 'not_registered',
		'site_unreachable'          => 'site_unreachable',
		'provider_unavailable'      => 'provider_unavailable',
		'service_error'             => 'server_error',
		'json_encode_failed'        => 'bad_request',
		'http_request_failed'       => 'network',
		'http_failure'              => 'network',
		'http_no_url'               => 'network',
		'http_request_not_executed' => 'network',
		'invalid_response'          => 'invalid_response',
		'media_save_failed'         => 'media_error',
		'image_unsupported'         => 'unsupported',
	];

	/**
	 * Map a failure to its category.
	 *
	 * @since NEXT_VERSION
	 * @param \Throwable $e The failure.
	 * @return string One of LIST.
	 */
	public static function normalise( \Throwable $e ): string {
		if ( ! $e instanceof ProviderException ) {
			return 'other';
		}

		$code = $e->get_error_code();
		if ( '' !== $code && isset( self::CODE_MAP[ $code ] ) ) {
			return self::CODE_MAP[ $code ];
		}

		$raw        = $e->get_raw_response();
		$raw_error  = is_array( $raw['error'] ?? null ) ? $raw['error'] : [];
		$raw_code   = is_string( $raw_error['code'] ?? null ) ? $raw_error['code'] : '';
		$raw_type   = is_string( $raw_error['type'] ?? null ) ? $raw_error['type'] : '';
		$raw_values = [ $raw_code, $raw_type ];
		$status     = $e->get_http_status();

		if ( 429 === $status ) {
			return in_array( 'insufficient_quota', $raw_values, true ) ? 'quota_exceeded' : 'rate_limited';
		}
		if ( 400 === $status && in_array( 'content_policy_violation', $raw_values, true ) ) {
			return 'content_policy';
		}
		if ( 401 === $status || 403 === $status ) {
			return 'auth_invalid';
		}
		if ( 402 === $status ) {
			return 'quota_exceeded';
		}
		if ( 404 === $status ) {
			return 'model_not_found';
		}
		if ( 408 === $status || 504 === $status ) {
			return 'timeout';
		}
		if ( in_array( $status, [ 400, 409, 413, 422 ], true ) ) {
			return 'bad_request';
		}
		if ( $status >= 500 && $status <= 599 ) {
			return 'server_error';
		}
		return 'other';
	}
}

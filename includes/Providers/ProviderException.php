<?php
/**
 * Exception thrown by AI providers on API errors, timeouts, or invalid responses.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries HTTP status, provider slug, and raw response alongside the error message.
 *
 * @since 1.0.0
 */
class ProviderException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string      $message        Exception message.
	 * @param string      $provider       Provider slug.
	 * @param int         $http_status    HTTP status code.
	 * @param array       $raw_response   Raw API response.
	 * @param ?\Throwable $previous       Previous exception.
	 */
	public function __construct(
		string $message,
		private readonly string $provider = '',
		private readonly int $http_status = 0,
		private readonly array $raw_response = [],
		?\Throwable $previous = null,
	) {
		parent::__construct( $message, $http_status, $previous );
	}

	/**
	 * Get provider slug.
	 *
	 * @return string
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * Get HTTP status code.
	 *
	 * @return int
	 */
	public function get_http_status(): int {
		return $this->http_status;
	}

	/**
	 * Get raw API response.
	 *
	 * @return array
	 */
	public function get_raw_response(): array {
		return $this->raw_response;
	}

	/**
	 * Check if the error is retryable.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return in_array( $this->http_status, [ 429, 500, 502, 503, 504 ], true );
	}
}

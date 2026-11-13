<?php
/**
 * Shared REST error-envelope builder for module routes.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Modules\Concerns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the Plume REST error envelope shared by the module routes.
 *
 * Kept in one place so the envelope shape (e.g. adding a `data` key) only ever
 * has to change in a single location. Module classes `use` this trait rather
 * than each carrying their own identical copy.
 *
 * @since NEXT_VERSION
 */
trait BuildsRestErrorResponse {

	/**
	 * Build a REST error response that carries the text under both keys.
	 *
	 * `error` is the key these routes have always sent and is kept for existing
	 * consumers. `message` and `code` follow the core REST error convention:
	 * apiFetch rejects with the parsed body only, so the client can read the
	 * text and tell credit exhaustion apart without seeing the HTTP status.
	 *
	 * @since NEXT_VERSION
	 * @param string $message Translated, user-facing error text.
	 * @param string $code    Machine-readable error code.
	 * @param int    $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $message, string $code, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'error'   => $message,
				'message' => $message,
				'code'    => $code,
			],
			$status
		);
	}
}

<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Providers;

use Plume\Providers\HttpTimeout;
use PHPUnit\Framework\TestCase;

class HttpTimeoutTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function error_provider(): array {
		return [
			'curl 28 operation'  => [ 'http_request_failed', 'cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received', true ],
			'curl 28 connection' => [ 'http_request_failed', 'cURL error 28: Connection timed out after 10001 milliseconds', true ],
			'curl 28 resolving'  => [ 'http_request_failed', 'cURL error 28: Resolving timed out after 10000 milliseconds', true ],
			'streams transport'  => [ 'http_request_failed', 'Operation timed out', true ],
			'dns failure'        => [ 'http_request_failed', 'cURL error 6: Could not resolve host: api.example.com', false ],
			'ssl failure'        => [ 'http_request_failed', 'cURL error 60: SSL certificate problem', false ],
			'connection refused' => [ 'http_request_failed', 'cURL error 7: Failed to connect to localhost port 11434: Connection refused', false ],
			'other error code'   => [ 'rest_forbidden', 'The request timed out', false ],
		];
	}

	/**
	 * @dataProvider error_provider
	 */
	public function test_is_timeout_only_matches_transport_timeouts( string $code, string $message, bool $expected ): void {
		self::assertSame( $expected, HttpTimeout::is_timeout( new \WP_Error( $code, $message ) ) );
	}

	public function test_resolve_prefers_the_requested_timeout(): void {
		self::assertSame( 120, HttpTimeout::resolve( 120, 60 ) );
	}

	public function test_resolve_falls_back_to_the_default_when_unset(): void {
		self::assertSame( 60, HttpTimeout::resolve( 0, 60 ) );
	}
}

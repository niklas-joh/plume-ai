<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Providers\ClaudeProvider;
use Plume\Providers\CompletionRequest;
use Plume\Providers\GeminiProvider;
use Plume\Providers\HttpTimeout;
use Plume\Providers\OllamaProvider;
use Plume\Providers\OpenAIProvider;
use Plume\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

/**
 * Own-key and local providers: per-request HTTP timeout and timeout reporting.
 */
class RequestTimeoutTest extends TestCase {

	private const CURL_28 = 'cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: class-string, 1: string, 2: int}>
	 */
	public static function provider_data(): array {
		return [
			'claude' => [ ClaudeProvider::class, 'claude', 60 ],
			'openai' => [ OpenAIProvider::class, 'openai', 60 ],
			'gemini' => [ GeminiProvider::class, 'gemini', 60 ],
			'ollama' => [ OllamaProvider::class, 'ollama', 120 ],
		];
	}

	/**
	 * Run a completion whose HTTP call times out, returning the timeout it was sent with.
	 *
	 * @param class-string $class   Provider class.
	 * @param int          $timeout Per-request timeout (0 = provider default).
	 * @param ?int         $sent    Receives the timeout passed to wp_remote_post().
	 * @return ProviderException
	 */
	private function complete_and_time_out( string $class, int $timeout, ?int &$sent ): ProviderException {
		Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$sent ) {
				$sent = $args['timeout'];
				return new \WP_Error( 'http_request_failed', self::CURL_28 );
			}
		);

		$provider = OllamaProvider::class === $class ? new $class() : new $class( 'test-key' );
		$request  = new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ], timeout: $timeout );

		try {
			$provider->complete( $request );
		} catch ( ProviderException $e ) {
			return $e;
		}
		self::fail( 'Expected a ProviderException.' );
	}

	/**
	 * @dataProvider provider_data
	 */
	public function test_request_timeout_is_sent_to_the_api( string $class, string $slug, int $default ): void {
		$this->complete_and_time_out( $class, 120, $sent );
		self::assertSame( 120, $sent );
	}

	/**
	 * @dataProvider provider_data
	 */
	public function test_default_timeout_is_kept_when_the_request_sets_none( string $class, string $slug, int $default ): void {
		$this->complete_and_time_out( $class, 0, $sent );
		self::assertSame( $default, $sent );
	}

	/**
	 * @dataProvider provider_data
	 */
	public function test_timeout_becomes_a_request_timeout_exception_and_is_not_retried( string $class, string $slug, int $default ): void {
		$calls = 0;
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( &$calls ) {
				++$calls;
				return new \WP_Error( 'http_request_failed', self::CURL_28 );
			}
		);

		$provider = OllamaProvider::class === $class ? new $class() : new $class( 'test-key' );
		try {
			$provider->complete( new CompletionRequest( [ [ 'role' => 'user', 'content' => 'hi' ] ] ) );
			self::fail( 'Expected a ProviderException.' );
		} catch ( ProviderException $e ) {
			self::assertSame( HttpTimeout::ERROR_CODE, $e->get_error_code() );
			self::assertSame( HttpTimeout::message(), $e->getMessage() );
			self::assertStringNotContainsString( 'cURL', $e->getMessage() );
			self::assertSame( $slug, $e->get_provider() );
		}
		self::assertSame( 1, $calls, 'A timed-out request must not be retried.' );
	}
}

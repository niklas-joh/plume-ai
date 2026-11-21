<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Plume\Providers\ProviderException;
use Plume\Telemetry\ErrorCodes;
use PHPUnit\Framework\TestCase;

class ErrorCodesTest extends TestCase {

	/**
	 * @dataProvider cases
	 */
	public function test_normalise( \Throwable $e, string $expected ): void {
		$this->assertSame( $expected, ErrorCodes::normalise( $e ) );
		$this->assertContains( ErrorCodes::normalise( $e ), ErrorCodes::LIST );
	}

	public static function cases(): array {
		$code = fn( string $c ) => new ProviderException( 'x', 'claude', 0, [], null, $c );
		$http = fn( int $s, array $raw = [] ) => new ProviderException( 'x', 'openai', $s, $raw );
		return [
			'request_timeout'          => [ $code( 'request_timeout' ), 'timeout' ],
			'rate_limit_exceeded'      => [ $code( 'rate_limit_exceeded' ), 'quota_exceeded' ],
			'auth_failed'              => [ $code( 'auth_failed' ), 'auth_invalid' ],
			'not_registered'           => [ $code( 'not_registered' ), 'not_registered' ],
			'site_unreachable'         => [ $code( 'site_unreachable' ), 'site_unreachable' ],
			'provider_unavailable'     => [ $code( 'provider_unavailable' ), 'provider_unavailable' ],
			'service_error'            => [ $code( 'service_error' ), 'server_error' ],
			'json_encode_failed'       => [ $code( 'json_encode_failed' ), 'bad_request' ],
			'http_request_failed'      => [ $code( 'http_request_failed' ), 'network' ],
			'http_failure'             => [ $code( 'http_failure' ), 'network' ],
			'invalid_response'         => [ $code( 'invalid_response' ), 'invalid_response' ],
			'media_save_failed'        => [ $code( 'media_save_failed' ), 'media_error' ],
			'image_unsupported'        => [ $code( 'image_unsupported' ), 'unsupported' ],
			'429 insufficient_quota'   => [ $http( 429, [ 'error' => [ 'code' => 'insufficient_quota' ] ] ), 'quota_exceeded' ],
			'429 type insufficient'    => [ $http( 429, [ 'error' => [ 'type' => 'insufficient_quota' ] ] ), 'quota_exceeded' ],
			'429'                      => [ $http( 429 ), 'rate_limited' ],
			'400 content policy'       => [ $http( 400, [ 'error' => [ 'code' => 'content_policy_violation' ] ] ), 'content_policy' ],
			'401'                      => [ $http( 401 ), 'auth_invalid' ],
			'403'                      => [ $http( 403 ), 'auth_invalid' ],
			'402'                      => [ $http( 402 ), 'quota_exceeded' ],
			'404'                      => [ $http( 404 ), 'model_not_found' ],
			'408'                      => [ $http( 408 ), 'timeout' ],
			'504'                      => [ $http( 504 ), 'timeout' ],
			'400'                      => [ $http( 400 ), 'bad_request' ],
			'409'                      => [ $http( 409 ), 'bad_request' ],
			'413'                      => [ $http( 413 ), 'bad_request' ],
			'422'                      => [ $http( 422 ), 'bad_request' ],
			'500'                      => [ $http( 500 ), 'server_error' ],
			'529'                      => [ $http( 529 ), 'server_error' ],
			'unknown code, status 0'   => [ $code( 'something_new' ), 'other' ],
			'no code, status 0'        => [ new ProviderException( 'x' ), 'other' ],
			'other throwable'          => [ new \RuntimeException( 'x' ), 'other' ],
			'unknown status'           => [ $http( 302 ), 'other' ],
		];
	}

	public function test_a_secret_in_the_message_never_changes_the_result(): void {
		$plain  = new ProviderException( 'Bad request', 'openai', 400 );
		$secret = new ProviderException( 'sk-live-123 timeout rate_limit_exceeded https://example.com', 'openai', 400 );
		$this->assertSame( ErrorCodes::normalise( $plain ), ErrorCodes::normalise( $secret ) );
	}
}

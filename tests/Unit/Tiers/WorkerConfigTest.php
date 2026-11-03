<?php
/**
 * Unit tests for WorkerConfig.
 *
 * @package Plume\Tests\Unit\Tiers
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Tiers\WorkerConfig;

/**
 * Covers the shared GET /v1/config fetch.
 *
 * @since NEXT_VERSION
 */
class WorkerConfigTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the site token option; an empty string models an unregistered site.
	 *
	 * @param string $token Site token.
	 */
	private function stub_token( string $token ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => 'plume_site_token' === $key ? $token : $default
		);
	}

	/**
	 * Stub a 200 response carrying the given raw body.
	 *
	 * @param string $body Raw response body.
	 */
	private function stub_ok_response( string $body ): void {
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_returns_null_without_a_token_and_makes_no_request(): void {
		$this->stub_token( '' );
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertNull( WorkerConfig::fetch( 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_sends_bearer_token_and_short_timeout_and_returns_body(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'wp_remote_get' )
			->once()
			->with(
				\Mockery::pattern( '#/v1/config$#' ),
				[
					'headers' => [ 'Authorization' => 'Bearer sometoken' ],
					'timeout' => WorkerConfig::TIMEOUT,
				]
			)
			->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"tier":"free","credit_limit":100}' );

		$this->assertSame(
			[
				'tier'         => 'free',
				'credit_limit' => 100,
			],
			WorkerConfig::fetch( 'free' )
		);
		$this->assertSame( 3, WorkerConfig::TIMEOUT );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_returns_null_on_wp_error(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$this->assertNull( WorkerConfig::fetch( 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_returns_null_on_non_200(): void {
		$this->stub_token( 'sometoken' );
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 500 ] ] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );

		$this->assertNull( WorkerConfig::fetch( 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_returns_null_when_body_is_not_a_json_array(): void {
		$this->stub_token( 'sometoken' );
		$this->stub_ok_response( 'not json' );

		$this->assertNull( WorkerConfig::fetch( 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_returns_null_when_response_tier_differs_from_requested_tier(): void {
		$this->stub_token( 'sometoken' );
		$this->stub_ok_response( '{"tier":"pro_managed","credit_limit":500}' );

		$this->assertNull( WorkerConfig::fetch( 'free' ) );
	}

	/**
	 * @since NEXT_VERSION
	 */
	public function test_returns_null_when_response_has_no_tier(): void {
		$this->stub_token( 'sometoken' );
		$this->stub_ok_response( '{"credit_limit":100}' );

		$this->assertNull( WorkerConfig::fetch( 'free' ) );
	}
}

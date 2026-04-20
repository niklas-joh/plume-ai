<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Proxy\NJ_Proxy_Client;

class NJProxyClientTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_chat_returns_error_when_not_registered(): void {
		Functions\expect( 'get_option' )
			->with( 'wp_ai_mind_site_token', '' )
			->andReturn( '' );

		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = NJ_Proxy_Client::chat( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_registered', $result->code );
	}
}

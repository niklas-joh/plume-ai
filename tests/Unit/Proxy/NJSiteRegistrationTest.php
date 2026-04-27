<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Proxy\NJ_Site_Registration;

class NJSiteRegistrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_site_token_returns_stored_token(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'abc123' );

		$this->assertSame( 'abc123', NJ_Site_Registration::get_site_token() );
	}

	public function test_get_site_token_returns_empty_string_when_not_registered(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );

		$this->assertSame( '', NJ_Site_Registration::get_site_token() );
	}

	public function test_is_registered_returns_true_when_token_exists(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'some-token' );

		$this->assertTrue( NJ_Site_Registration::is_registered() );
	}

	public function test_is_registered_returns_false_when_no_token(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( '' );

		$this->assertFalse( NJ_Site_Registration::is_registered() );
	}

	public function test_checkout_url_embeds_site_token(): void {
		Functions\expect( 'get_option' )
			->with( NJ_Site_Registration::OPTION_TOKEN, '' )
			->andReturn( 'mytoken' );

		$url = NJ_Site_Registration::checkout_url_pro_managed_monthly();

		$this->assertStringContainsString( 'lemonsqueezy.com', $url );
		$this->assertStringContainsString( 'mytoken', $url );
	}
}

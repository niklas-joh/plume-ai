<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Telemetry\Utm;
use Plume\Tests\Helpers\TelemetryHarness;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'PLUME_WEBSITE_URL' ) ) {
	define( 'PLUME_WEBSITE_URL', 'https://wpaimind.com' );
}

class UtmTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		TelemetryHarness::stub_url_functions();
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $v ) ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tags_website_links(): void {
		$this->assertSame(
			'https://wpaimind.com/pricing?utm_source=plugin&utm_medium=dashboard&utm_content=upgrade',
			Utm::tag( 'https://wpaimind.com/pricing', 'dashboard', 'upgrade' )
		);
	}

	public function test_content_is_optional_and_other_parameters_are_kept(): void {
		$this->assertSame(
			'https://wpaimind.com/docs?ref=x&utm_source=plugin&utm_medium=activation_notice',
			Utm::tag( 'https://wpaimind.com/docs?ref=x', 'activation_notice' )
		);
	}

	public function test_other_hosts_are_unchanged(): void {
		$url = 'https://plume.lemonsqueezy.com/checkout/buy/1?checkout[custom][site_token]=t';
		$this->assertSame( $url, Utm::tag( $url, 'dashboard' ) );
		$this->assertSame( 'https://wpaimind.com.evil.test/', Utm::tag( 'https://wpaimind.com.evil.test/', 'dashboard' ) );
		$this->assertSame( 'not a url', Utm::tag( 'not a url', 'dashboard' ) );
	}
}

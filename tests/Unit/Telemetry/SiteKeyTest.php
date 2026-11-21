<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Telemetry\SiteKey;
use PHPUnit\Framework\TestCase;

/**
 * Runs the fixture shared with plume-proxy/test/metrics.test.ts through SiteKey, so the
 * heartbeat site_key always equals the Worker's siteKeyFromUrl().
 */
class SiteKeyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function fixture_cases(): array {
		$path  = dirname( __DIR__, 3 ) . '/plume-proxy/test/fixtures/site-key-cases.json';
		$cases = json_decode( (string) file_get_contents( $path ), true );
		$out   = [];
		foreach ( (array) $cases as $i => $case ) {
			$out[ $i . ': ' . substr( (string) $case[0], 0, 40 ) ] = [ (string) $case[0], (string) $case[1] ];
		}
		return $out;
	}

	/**
	 * @dataProvider fixture_cases
	 */
	public function test_matches_the_shared_fixture( string $input, string $expected ): void {
		if ( 1 === preg_match( '~^\s*[a-z]+://[^/]*[\x80-\xFF]~i', $input ) && ! function_exists( 'idn_to_ascii' ) ) {
			$this->markTestSkipped( 'The intl extension is not loaded, so IDN hosts cannot be converted.' );
		}
		$this->assertSame( $expected, SiteKey::from_url( $input ) );
	}

	public function test_fixture_is_not_empty(): void {
		$this->assertGreaterThanOrEqual( 20, count( self::fixture_cases() ) );
	}

	public function test_every_non_empty_expected_value_is_valid(): void {
		foreach ( self::fixture_cases() as [ , $expected ] ) {
			if ( '' !== $expected ) {
				$this->assertTrue( SiteKey::is_valid( $expected ), $expected );
			}
		}
	}

	/**
	 * @dataProvider non_canonical_cases
	 */
	public function test_rejects_non_canonical_keys( string $key ): void {
		$this->assertFalse( SiteKey::is_valid( $key ) );
	}

	/**
	 * @return array<int, array{0: string}>
	 */
	public static function non_canonical_cases(): array {
		return [ [ 'Example.com' ], [ 'www.example.com' ], [ 'example.com/' ], [ 'https://example.com' ], [ '' ] ];
	}

	public function test_non_ascii_host_without_intl_gives_empty_key(): void {
		if ( function_exists( 'idn_to_ascii' ) ) {
			// Documents the fallback: without intl a non-ASCII host is never sent.
			$this->assertSame( 'xn--bcher-kva.example', SiteKey::from_url( 'https://bücher.example/' ) );
			return;
		}
		$this->assertSame( '', SiteKey::from_url( 'https://bücher.example/' ) );
	}

	public function test_ipv6_hosts_are_refused(): void {
		$this->assertSame( '', SiteKey::from_url( 'http://[::1]:8080/' ) );
	}

	public function test_current_uses_home_url(): void {
		Functions\when( 'home_url' )->justReturn( 'https://www.Example.com/blog/' );
		$this->assertSame( 'example.com/blog', SiteKey::current() );
	}
}

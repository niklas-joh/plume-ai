<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Telemetry\ErrorCodes;
use Plume\Telemetry\EventSchema;
use Plume\Telemetry\Telemetry;
use PHPUnit\Framework\TestCase;

class EventSchemaTest extends TestCase {

	public function test_unknown_event_returns_null(): void {
		$this->assertNull( EventSchema::sanitise( 'page_view', [] ) );
	}

	public function test_unknown_keys_are_dropped_and_missing_keys_left_out(): void {
		$clean = EventSchema::sanitise( 'upgrade_click', [ 'placement' => 'onboarding', 'url' => 'https://example.com' ] );
		$this->assertSame( [ 'placement' => 'onboarding' ], $clean );
	}

	public function test_invalid_enum_becomes_empty_string(): void {
		$clean = EventSchema::sanitise( 'onboarding_done', [ 'route' => 'byok' ] );
		$this->assertSame( [ 'route' => '' ], $clean );
		$clean = EventSchema::sanitise( 'onboarding_done', [ 'route' => 1 ] );
		$this->assertSame( [ 'route' => '' ], $clean );
	}

	public function test_ints_are_clamped_and_fall_back_to_the_default(): void {
		$clean = EventSchema::sanitise(
			'ai_request',
			[
				'http_status'   => 900,
				'latency_ms'    => -5,
				'attempts'      => 'two',
				'image_count'   => 2.0,
				'input_tokens'  => 1.5,
				'output_tokens' => INF,
			]
		);
		$this->assertSame( 599, $clean['http_status'] );
		$this->assertSame( 0, $clean['latency_ms'] );
		$this->assertSame( 1, $clean['attempts'] );
		$this->assertSame( 2, $clean['image_count'] );
		$this->assertSame( 0, $clean['input_tokens'] );
		$this->assertSame( 0, $clean['output_tokens'] );

		$clean = EventSchema::sanitise( 'heartbeat', [ 'days_since_activation' => null ] );
		$this->assertSame( -1, $clean['days_since_activation'] );
	}

	/**
	 * @dataProvider pattern_cases
	 */
	public function test_patterns( string $event, string $field, string $value, string $expected ): void {
		$this->assertSame( $expected, EventSchema::sanitise( $event, [ $field => $value ] )[ $field ] );
	}

	public static function pattern_cases(): array {
		return [
			'known model'          => [ 'ai_request', 'model', 'gpt-4.1', 'gpt-4.1' ],
			'model with space'     => [ 'ai_request', 'model', 'my model', '' ],
			'model too long'       => [ 'ai_request', 'model', str_repeat( 'a', 65 ), '' ],
			'wp version'           => [ 'heartbeat', 'wp_version', '6.7', '6.7' ],
			'wp patch version'     => [ 'heartbeat', 'wp_version', '6.7.1', '' ],
			'locale'               => [ 'heartbeat', 'locale', 'en_GB', 'en_GB' ],
			'locale with variant'  => [ 'heartbeat', 'locale', 'de_DE_formal', 'de_DE_formal' ],
			'locale invalid'       => [ 'heartbeat', 'locale', 'EN-gb', '' ],
			'uuid upper case'      => [ 'ai_request', 'turn_id', '3F2B6C1E-8D4A-4B7F-9A1C-2E5D7F9B0C13', '3f2b6c1e-8d4a-4b7f-9a1c-2e5d7f9b0c13' ],
			'uuid v1'              => [ 'ai_request', 'turn_id', '3f2b6c1e-8d4a-1b7f-9a1c-2e5d7f9b0c13', '' ],
		];
	}

	public function test_providers_are_normalised(): void {
		$clean = EventSchema::sanitise( 'heartbeat', [ 'providers' => 'openai, claude,openai,acme' ] );
		$this->assertSame( 'claude,openai', $clean['providers'] );
		$this->assertSame( '', EventSchema::sanitise( 'heartbeat', [ 'providers' => [ 'claude' ] ] )['providers'] );
	}

	public function test_is_uuid4(): void {
		$this->assertTrue( EventSchema::is_uuid4( '3f2b6c1e-8d4a-4b7f-9a1c-2e5d7f9b0c13' ) );
		$this->assertFalse( EventSchema::is_uuid4( 'not-a-uuid' ) );
	}

	public function test_client_events_are_in_the_spec(): void {
		foreach ( array_keys( EventSchema::CLIENT_EVENTS ) as $event ) {
			$this->assertArrayHasKey( $event, EventSchema::SPEC );
		}
	}

	public function test_error_enums_match_error_codes_list(): void {
		$this->assertSame( ErrorCodes::LIST, EventSchema::SPEC['ai_request']['error_code']['values'] );
		$this->assertSame( ErrorCodes::LIST, EventSchema::SPEC['ai_request']['first_error_code']['values'] );
	}

	/**
	 * Contract: the PHP copy and the Worker's JSON allowlist must be identical.
	 */
	public function test_spec_matches_worker_event_schema_json(): void {
		$path = dirname( __DIR__, 3 ) . '/plume-proxy/src/event-schema.json';
		$json = json_decode( (string) file_get_contents( $path ), true );

		$this->assertIsArray( $json );
		$this->assertSame( $json['events'], EventSchema::SPEC );
		$this->assertSame( Telemetry::SCHEMA_VERSION, $json['schema_version'] );
		$this->assertGreaterThanOrEqual( Telemetry::BATCH_SIZE, $json['max_events'] );
	}

	public function test_site_key_keeps_only_the_canonical_form(): void {
		Monkey\setUp();
		try {
			Functions\when( 'wp_parse_url' )->alias( fn( $url, $component = -1 ) => parse_url( $url, $component ) );
			$cases = [
				'example.com/blog'      => 'example.com/blog',
				'xn--bcher-kva.example' => 'xn--bcher-kva.example',
				'Example.com'           => '',
				'www.example.com'       => '',
				'example.com/'          => '',
				'https://example.com'   => '',
				'example.com/a?b'       => '',
				str_repeat( 'a', 97 )   => '',
			];
			foreach ( $cases as $input => $expected ) {
				$this->assertSame( $expected, EventSchema::sanitise( 'heartbeat', [ 'site_key' => (string) $input ] )['site_key'], (string) $input );
			}
			$this->assertSame( '', EventSchema::sanitise( 'heartbeat', [ 'site_key' => 42 ] )['site_key'] );
		} finally {
			Monkey\tearDown();
		}
	}
}

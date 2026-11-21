<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Plume\Telemetry\Consent;
use Plume\Telemetry\EventSchema;
use Plume\Telemetry\Telemetry;
use Plume\Tests\Helpers\TelemetryHarness;
use PHPUnit\Framework\TestCase;

class ConsentTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Telemetry::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_is_unset(): void {
		TelemetryHarness::install();
		$this->assertSame( Consent::UNSET, Consent::get() );
	}

	public function test_invalid_stored_value_reads_as_unset(): void {
		TelemetryHarness::install( [ Telemetry::OPTION_CONSENT => 'maybe' ] );
		$this->assertSame( Consent::UNSET, Consent::get() );
	}

	public function test_invalid_value_or_source_is_rejected(): void {
		$h = TelemetryHarness::install();
		$this->assertFalse( Consent::set( 'unset', 'settings' ) );
		$this->assertFalse( Consent::set( 'yes', 'cli' ) );
		$this->assertArrayNotHasKey( Telemetry::OPTION_CONSENT, $h->options );
	}

	public function test_yes_creates_an_install_id_schedules_and_queues_consent_and_heartbeat(): void {
		$h = TelemetryHarness::install();
		$this->assertTrue( Consent::set( 'yes', 'onboarding' ) );

		$this->assertSame( 'yes', $h->options[ Telemetry::OPTION_CONSENT ] );
		$this->assertTrue( $h->autoload[ Telemetry::OPTION_CONSENT ] );
		$this->assertTrue( EventSchema::is_uuid4( $h->options[ Telemetry::OPTION_INSTALL_ID ] ) );
		$this->assertFalse( $h->autoload[ Telemetry::OPTION_INSTALL_ID ] );
		$this->assertArrayHasKey( Telemetry::CRON_HOOK, $h->scheduled );
		$this->assertTrue( Telemetry::is_active() );

		$queue = $h->flush();
		$this->assertSame( [ 'consent_given', 'heartbeat' ], array_column( $queue, 'name' ) );
		$this->assertSame( 'onboarding', $queue[0]['source'] );
		$this->assertGreaterThan( 0, $h->options[ Telemetry::OPTION_STATE ]['last_heartbeat_at'] );
		$this->assertSame( [], $h->posts, 'Consent never sends inline.' );
	}

	public function test_yes_twice_records_only_once(): void {
		$h = TelemetryHarness::install();
		Consent::set( 'yes', 'settings' );
		Consent::set( 'yes', 'settings' );
		$this->assertCount( 2, $h->flush() );
	}

	public function test_yes_clears_a_stale_queue_left_by_an_earlier_withdrawal(): void {
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_CONSENT => 'no',
				Telemetry::OPTION_STATE   => [ 'queue' => [ [ 'name' => 'onboarding_done', 'ts' => 3600, 'route' => 'managed' ] ] ],
			]
		);
		Consent::set( 'yes', 'settings' );
		$this->assertSame( [ 'consent_given', 'heartbeat' ], array_column( $h->flush(), 'name' ) );
	}

	public function test_no_deletes_the_id_clears_the_queue_and_the_cron_and_posts_nothing(): void {
		$h = TelemetryHarness::install();
		Consent::set( 'yes', 'settings' );
		$h->flush();
		Telemetry::record( 'onboarding_done', [ 'route' => 'managed' ] );

		$this->assertTrue( Consent::set( 'no', 'settings' ) );

		$this->assertSame( 'no', $h->options[ Telemetry::OPTION_CONSENT ] );
		$this->assertArrayNotHasKey( Telemetry::OPTION_INSTALL_ID, $h->options );
		$this->assertArrayNotHasKey( Telemetry::OPTION_STATE, $h->options );
		$this->assertArrayNotHasKey( Telemetry::CRON_HOOK, $h->scheduled );
		$this->assertFalse( Telemetry::is_active() );
		Telemetry::persist();
		$this->assertArrayNotHasKey( Telemetry::OPTION_STATE, $h->options, 'The buffer was dropped too.' );
		$this->assertSame( [], $h->posts );
	}

	public function test_no_works_while_unavailable(): void {
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_CONSENT    => 'yes',
				Telemetry::OPTION_INSTALL_ID => TelemetryHarness::INSTALL_ID,
			]
		);
		Filters\expectApplied( 'plume_telemetry_enabled' )->andReturn( false );
		$this->assertTrue( Consent::set( 'no', 'settings' ) );
		$this->assertArrayNotHasKey( Telemetry::OPTION_INSTALL_ID, $h->options );
	}

	public function test_yes_while_unavailable_records_nothing(): void {
		$h = TelemetryHarness::install( [], 'local' );
		Consent::set( 'yes', 'settings' );
		$this->assertFalse( Telemetry::is_active() );
		Telemetry::persist();
		$this->assertArrayNotHasKey( Telemetry::OPTION_STATE, $h->options );
		$this->assertArrayNotHasKey( Telemetry::CRON_HOOK, $h->scheduled );
	}
}

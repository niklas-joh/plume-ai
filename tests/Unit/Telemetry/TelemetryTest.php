<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Plume\Telemetry\Telemetry;
use Plume\Tests\Helpers\TelemetryHarness;
use Plume\Tiers\TierConfig;
use PHPUnit\Framework\TestCase;

class TelemetryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Telemetry::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Activation ────────────────────────────────────────────────────────────

	public function test_inactive_by_default_and_record_makes_no_wordpress_calls(): void {
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->assertFalse( Telemetry::is_active() );
		Telemetry::record( 'consent_given', [ 'source' => 'settings' ] );
		Telemetry::persist();
		$this->assertSame( '', Telemetry::new_turn_id() );
	}

	public function test_refresh_without_consent_never_checks_environment_or_filter(): void {
		Functions\when( 'get_option' )->alias( fn( $key, $fallback = false ) => $fallback );
		Functions\expect( 'wp_get_environment_type' )->never();
		Filters\expectApplied( 'plume_telemetry_enabled' )->never();

		Telemetry::refresh();
		$this->assertFalse( Telemetry::is_active() );
	}

	public function test_consent_yes_and_available_makes_it_active(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		$this->assertTrue( Telemetry::is_active() );
	}

	public function test_filter_returning_false_keeps_it_inactive(): void {
		$h = TelemetryHarness::install();
		Filters\expectApplied( 'plume_telemetry_enabled' )->once()->andReturn( false );
		$h->consent_yes();
		$this->assertFalse( Telemetry::is_active() );
	}

	public function test_local_environment_with_production_proxy_is_unavailable(): void {
		TelemetryHarness::install( [], 'local' );
		$this->assertFalse( Telemetry::is_available() );
		$this->assertFalse( Telemetry::default_enabled( 'development', TierConfig::DEFAULT_PROXY_URL . '/' ) );
	}

	public function test_local_environment_with_overridden_proxy_is_available(): void {
		$this->assertTrue( Telemetry::default_enabled( 'local', 'https://plume-proxy-dev.plumewp.workers.dev' ) );
		$this->assertTrue( Telemetry::default_enabled( 'production', TierConfig::DEFAULT_PROXY_URL ) );
		$this->assertTrue( Telemetry::default_enabled( 'staging', TierConfig::DEFAULT_PROXY_URL ) );
	}

	public function test_new_turn_id_is_a_uuid_while_active(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		$this->assertNotSame( '', Telemetry::new_turn_id() );
	}

	// ── record() / persist() ──────────────────────────────────────────────────

	public function test_ts_is_the_exact_time(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		$before = time();
		Telemetry::record( 'consent_given', [ 'source' => 'settings' ] );
		$queue = $h->flush();

		$this->assertCount( 1, $queue );
		$this->assertGreaterThanOrEqual( $before, $queue[0]['ts'] );
		$this->assertLessThanOrEqual( time(), $queue[0]['ts'] );
		$this->assertSame( 'consent_given', $queue[0]['name'] );
		$this->assertSame( 'settings', $queue[0]['source'] );
	}

	public function test_unknown_events_and_keys_are_not_recorded(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::record( 'page_view', [ 'url' => 'https://example.com' ] );
		Telemetry::record( 'onboarding_done', [ 'route' => 'managed', 'email' => 'a@example.com' ] );
		$queue = $h->flush();

		$this->assertCount( 1, $queue );
		$this->assertArrayNotHasKey( 'email', $queue[0] );
	}

	public function test_persist_does_nothing_on_an_empty_buffer(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::persist();
		$this->assertSame( 0, $h->state_writes );
		$this->assertSame( 0, $h->state_reads );
	}

	public function test_persist_writes_once_without_autoload(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::record( 'onboarding_done', [ 'route' => 'managed' ] );
		Telemetry::record( 'onboarding_done', [ 'route' => 'skipped' ] );
		Telemetry::persist();

		$this->assertSame( 1, $h->state_writes );
		$this->assertSame( 1, $h->state_reads );
		$this->assertFalse( $h->autoload[ Telemetry::OPTION_STATE ] );
		$this->assertCount( 2, $h->queue() );

		// The buffer is gone, so a second shutdown callback writes nothing.
		Telemetry::persist();
		$this->assertSame( 1, $h->state_writes );
	}

	public function test_queue_is_capped_dropping_the_oldest(): void {
		$old = [];
		for ( $i = 0; $i < Telemetry::MAX_QUEUE; $i++ ) {
			$old[] = [ 'name' => 'onboarding_done', 'ts' => 3600, 'route' => 'skipped' ];
		}
		$h = TelemetryHarness::install( [ Telemetry::OPTION_STATE => [ 'queue' => $old ] ] );
		$h->consent_yes();
		Telemetry::record( 'onboarding_done', [ 'route' => 'managed' ] );
		$queue = $h->flush();

		$this->assertCount( Telemetry::MAX_QUEUE, $queue );
		$this->assertSame( 'managed', end( $queue )['route'] );
	}

	public function test_buffer_is_capped_within_one_request(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		for ( $i = 0; $i < Telemetry::MAX_QUEUE + 10; $i++ ) {
			Telemetry::record( 'onboarding_done', [ 'route' => 'managed' ] );
		}
		$this->assertCount( Telemetry::MAX_QUEUE, $h->flush() );
	}

	public function test_last_ai_at_is_set_only_when_an_ai_request_is_buffered(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::record( 'onboarding_done', [ 'route' => 'managed' ] );
		$h->flush();
		$this->assertSame( 0, $h->options[ Telemetry::OPTION_STATE ]['last_ai_at'] );

		Telemetry::record( 'ai_request', [ 'feature' => 'chat' ] );
		$h->flush();
		$last = $h->options[ Telemetry::OPTION_STATE ]['last_ai_at'];
		$this->assertGreaterThan( 0, $last );
		$this->assertSame( 0, $last % 3600 );
	}

	public function test_refresh_to_inactive_clears_the_buffer(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::record( 'onboarding_done', [ 'route' => 'managed' ] );
		$h->options[ Telemetry::OPTION_CONSENT ] = 'no';
		Telemetry::refresh();
		Telemetry::persist();
		$this->assertSame( 0, $h->state_writes );
	}

	// ── send() ────────────────────────────────────────────────────────────────

	public function test_send_posts_batches_of_25_and_empties_the_queue(): void {
		$queue = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$queue[] = [ 'name' => 'onboarding_done', 'ts' => 3600, 'route' => 'managed' ];
		}
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_STATE => [
					'queue'             => $queue,
					'last_heartbeat_at' => time(),
				],
			]
		);
		$h->consent_yes();
		Telemetry::send();

		$this->assertCount( 3, $h->posts );
		$this->assertSame( [ 25, 25, 10 ], array_map( fn( $b ) => count( $b['events'] ), $h->sent_bodies() ) );
		foreach ( $h->posts as $post ) {
			$this->assertSame( PLUME_PROXY_URL . '/v1/events', $post['url'] );
			$this->assertTrue( $post['args']['blocking'] );
			$this->assertSame( 5, $post['args']['timeout'] );
			$this->assertSame( 0, $post['args']['redirection'] );
			$this->assertSame( 'application/json', $post['args']['headers']['Content-Type'] );
		}
		$body = $h->sent_bodies()[0];
		$this->assertSame( 2, $body['schema'] );
		$this->assertSame( TelemetryHarness::INSTALL_ID, $body['install_id'] );
		$this->assertSame( PLUME_VERSION, $body['plugin_version'] );
		$this->assertSame( [], $h->queue() );
		$this->assertSame( 1, $h->state_writes );
	}

	public function test_send_stops_at_the_first_failed_batch(): void {
		$queue = array_fill( 0, 60, [ 'name' => 'onboarding_done', 'ts' => 3600, 'route' => 'managed' ] );
		$h     = TelemetryHarness::install(
			[
				Telemetry::OPTION_STATE => [
					'queue'             => $queue,
					'last_heartbeat_at' => time(),
				],
			]
		);
		$h->post_result = new \WP_Error( 'http_request_failed', 'down' );
		$h->consent_yes();
		Telemetry::send();

		$this->assertCount( 1, $h->posts );
		$this->assertSame( [], $h->queue(), 'Unsent events are dropped.' );
	}

	public function test_send_adds_a_heartbeat_only_after_24_hours(): void {
		$h = TelemetryHarness::install( [ Telemetry::OPTION_STATE => [ 'last_heartbeat_at' => time() - 3600 ] ] );
		$h->consent_yes();
		Telemetry::send();
		$this->assertSame( [], $h->posts, 'Nothing queued and no heartbeat due: nothing is sent.' );

		$h->options[ Telemetry::OPTION_STATE ]['last_heartbeat_at'] = time() - DAY_IN_SECONDS;
		Telemetry::send();
		$this->assertCount( 1, $h->posts );
		$events = $h->sent_bodies()[0]['events'];
		$this->assertSame( 'heartbeat', $events[0]['name'] );
		$this->assertGreaterThanOrEqual( time() - 5, $h->options[ Telemetry::OPTION_STATE ]['last_heartbeat_at'] );
	}

	public function test_send_while_inactive_unschedules_clears_the_queue_and_posts_nothing(): void {
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_STATE => [ 'queue' => [ [ 'name' => 'deactivated', 'ts' => 3600 ] ] ],
			]
		);
		$h->scheduled[ Telemetry::CRON_HOOK ] = time();
		Telemetry::refresh();
		Telemetry::send();

		$this->assertSame( [], $h->posts );
		$this->assertArrayNotHasKey( Telemetry::CRON_HOOK, $h->scheduled );
		$this->assertArrayNotHasKey( Telemetry::OPTION_STATE, $h->options );
	}

	public function test_wp_remote_post_throwing_is_swallowed(): void {
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_STATE => [
					'queue'             => [ [ 'name' => 'deactivated', 'ts' => 3600 ] ],
					'last_heartbeat_at' => time(),
				],
			]
		);
		$h->post_result = function () {
			throw new \RuntimeException( 'boom' );
		};
		$h->consent_yes();
		Telemetry::send();
		$this->assertCount( 1, $h->posts );
	}

	public function test_heartbeat_props_shape(): void {
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_ACTIVATED_AT => time() - 3 * DAY_IN_SECONDS - 10,
				'plume_ollama_url'             => 'http://localhost:11434',
			]
		);
		$props = Telemetry::heartbeat_props( [ 'last_ai_at' => time() - DAY_IN_SECONDS - 5 ] );

		$this->assertSame(
			[ 'tier', 'providers', 'wp_version', 'php_version', 'locale', 'days_since_activation', 'days_since_last_ai', 'site_key' ],
			array_keys( $props )
		);
		$this->assertSame( 'example.com/blog', $props['site_key'] );
		$this->assertSame( 'free', $props['tier'] );
		$this->assertSame( 'ollama', $props['providers'] );
		$this->assertSame( '6.7', $props['wp_version'] );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $props['php_version'] );
		$this->assertSame( 'en_GB', $props['locale'] );
		$this->assertSame( 3, $props['days_since_activation'] );
		$this->assertSame( 1, $props['days_since_last_ai'] );

		$props = Telemetry::heartbeat_props( [] );
		$this->assertSame( -1, $props['days_since_last_ai'] );
	}

	public function test_heartbeat_leaves_out_site_key_when_home_url_cannot_be_normalised(): void {
		TelemetryHarness::install();
		Functions\when( 'home_url' )->justReturn( '' );
		$this->assertArrayNotHasKey( 'site_key', Telemetry::heartbeat_props( [] ) );
	}

	public function test_sent_heartbeat_carries_the_site_key(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::send();
		$events = $h->sent_bodies()[0]['events'];
		$beats  = array_values( array_filter( $events, fn( $e ) => 'heartbeat' === $e['name'] ) );
		$this->assertCount( 1, $beats );
		$this->assertSame( 'example.com/blog', $beats[0]['site_key'] );
	}

	// ── Deactivation and schedule ─────────────────────────────────────────────

	public function test_on_deactivate_sends_only_a_deactivated_event_then_clears(): void {
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_STATE => [ 'queue' => [ [ 'name' => 'onboarding_done', 'ts' => 3600, 'route' => 'managed' ] ] ],
			]
		);
		$h->scheduled[ Telemetry::CRON_HOOK ] = time();
		$h->consent_yes();
		Telemetry::on_deactivate();

		$this->assertCount( 1, $h->posts );
		$this->assertFalse( $h->posts[0]['args']['blocking'] );
		$events = $h->sent_bodies()[0]['events'];
		$this->assertCount( 1, $events );
		$this->assertSame( 'deactivated', $events[0]['name'] );
		$this->assertArrayNotHasKey( Telemetry::OPTION_STATE, $h->options );
		$this->assertArrayNotHasKey( Telemetry::CRON_HOOK, $h->scheduled );
	}

	public function test_missing_install_id_is_never_recreated_and_nothing_is_sent(): void {
		// A withdrawal in another request deleted the ID after this cron request booted
		// active: send() and on_deactivate() must not write a new ID or post anything.
		$h = TelemetryHarness::install(
			[
				Telemetry::OPTION_STATE => [
					'queue'             => [ [ 'name' => 'onboarding_done', 'ts' => 3600, 'route' => 'managed' ] ],
					'last_heartbeat_at' => time(),
				],
			]
		);
		$h->consent_yes();
		unset( $h->options[ Telemetry::OPTION_INSTALL_ID ] );

		Telemetry::send();
		Telemetry::on_deactivate();

		$this->assertSame( [], $h->posts );
		$this->assertArrayNotHasKey( Telemetry::OPTION_INSTALL_ID, $h->options );
	}

	public function test_on_deactivate_while_inactive_sends_nothing(): void {
		$h = TelemetryHarness::install();
		Telemetry::refresh();
		Telemetry::on_deactivate();
		$this->assertSame( [], $h->posts );
	}

	public function test_maybe_reschedule_heals_both_directions(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::maybe_reschedule();
		$this->assertArrayHasKey( Telemetry::CRON_HOOK, $h->scheduled );

		$h->options[ Telemetry::OPTION_CONSENT ] = 'no';
		Telemetry::refresh();
		Telemetry::maybe_reschedule();
		$this->assertArrayNotHasKey( Telemetry::CRON_HOOK, $h->scheduled );
	}

	public function test_schedule_is_hourly_and_idempotent(): void {
		$scheduled = false;
		Functions\when( 'wp_next_scheduled' )->alias( function () use ( &$scheduled ) {
			return $scheduled;
		} );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'hourly', Telemetry::CRON_HOOK )
			->andReturnUsing(
				function ( $ts ) use ( &$scheduled ) {
					$scheduled = $ts;
					return true;
				}
			);
		Telemetry::schedule();
		Telemetry::schedule();
		$this->assertGreaterThan( time(), $scheduled );
	}

	public function test_no_http_call_outside_send_and_deactivate(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		Telemetry::record( 'ai_request', [ 'feature' => 'chat' ] );
		Telemetry::persist();
		Telemetry::maybe_reschedule();
		Telemetry::refresh();
		$this->assertSame( [], $h->posts );
	}
}

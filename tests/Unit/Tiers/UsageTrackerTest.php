<?php

namespace Plume\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Tests\Helpers\WpdbStubFactory;
use Plume\Tiers\UsageTracker;

class UsageTrackerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default site-tier lookup returns the supplied default so tests focused on
		// per-user meta resolution do not need to stub the new SITE_OPTION read.
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
	}

	protected function tearDown(): void {
		// Restore a valid $wpdb baseline so log_usage() does not crash in later test classes.
		global $wpdb;
		$wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_usage_returns_correct_structure_for_free_user(): void {
		$month_key = 'plume_credits_' . gmdate( 'Y_m' );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_user_meta' )
			->once()->with( 1, $month_key, true )->andReturn( '25000' );
		// get_user_tier() resolves to 'free' (site option default); get_cached_credit_limit()
		// hits its transient cache miss and falls back to FALLBACK_LIMIT.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$usage = UsageTracker::get_usage();

		$this->assertSame( 'free', $usage['tier'] );
		$this->assertSame( 25000, $usage['used'] );
		$this->assertSame( UsageTracker::FALLBACK_LIMIT, $usage['limit'] );
		$this->assertTrue( $usage['can_use'] );
	}

	public function test_get_usage_can_use_is_always_true(): void {
		// can_use is hardcoded true — the Worker's KV ledger is the sole enforcement
		// point now; the local mirror is for dashboard display only.
		$month_key = 'plume_credits_' . gmdate( 'Y_m' );

		Functions\expect( 'get_user_meta' )
			->once()->with( 2, $month_key, true )->andReturn( '999999999' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$usage = UsageTracker::get_usage( 2 );
		$this->assertTrue( $usage['can_use'] );
	}

	public function test_get_usage_pro_byok_is_always_unlimited(): void {
		$month_key = 'plume_credits_' . gmdate( 'Y_m' );

		// pro_byok lives on the site option.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\expect( 'get_user_meta' )
			->once()->with( 3, $month_key, true )->andReturn( '999999' );

		$usage = UsageTracker::get_usage( 3 );
		$this->assertTrue( $usage['can_use'] );
		$this->assertNull( $usage['limit'] );
		$this->assertNull( $usage['remaining'] );
	}

	public function test_log_usage_performs_atomic_sql_increment(): void {
		global $wpdb;
		$month_key = 'plume_credits_' . gmdate( 'Y_m' );

		$wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta      = 'wp_usermeta';
		$wpdb->rows_affected = 1;
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );

		UsageTracker::log_usage( 500 );
		$this->addToAssertionCount( 1 );
	}

	public function test_log_usage_falls_back_to_insert_when_no_row_exists(): void {
		global $wpdb;
		$month_key = 'plume_credits_' . gmdate( 'Y_m' );

		$wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta      = 'wp_usermeta';
		$wpdb->rows_affected = 0; // No existing row.
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'add_user_meta' )
			->once()
			->with( 1, $month_key, 500, true )
			->andReturn( true );

		UsageTracker::log_usage( 500 );
		$this->addToAssertionCount( 1 );
	}

	public function test_log_usage_skips_db_write_when_credits_are_zero(): void {
		// BYOK users bypass the Worker — credits_charged is always 0 for them.
		// Ensure no DB write occurs to avoid a no-op UPDATE on every chat message.
		global $wpdb;
		$wpdb           = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta = 'wp_usermeta';
		$wpdb->shouldReceive( 'query' )->never();

		UsageTracker::log_usage( 0 );
		$this->addToAssertionCount( 1 );
	}

	public function test_get_current_month_key_returns_expected_format(): void {
		$key = UsageTracker::get_current_month_key();

		$this->assertMatchesRegularExpression( '/^plume_credits_\d{4}_\d{2}$/', $key );
		$this->assertSame( 'plume_credits_' . gmdate( 'Y_m' ), $key );
	}

	// ── check_limit() removed entirely — no replacement method, no stub ───────

	public function test_check_limit_method_does_not_exist(): void {
		$this->assertFalse(
			method_exists( UsageTracker::class, 'check_limit' ),
			'check_limit() must be removed — the Worker KV ledger is the sole enforcement point now.'
		);
	}

	// ── get_cached_credit_limit() ──────────────────────────────────────────────

	public function test_get_cached_credit_limit_returns_cached_transient_value_on_hit(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'plume_credit_limit_free' )
			->andReturn( 100 );
		Functions\expect( 'set_transient' )->never();
		// No fetch attempted on a cache hit.
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertSame( 100, UsageTracker::get_cached_credit_limit( 'free' ) );
	}

	public function test_get_cached_credit_limit_returns_null_for_pro_byok(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();
		Functions\expect( 'wp_remote_get' )->never();

		$this->assertNull( UsageTracker::get_cached_credit_limit( 'pro_byok' ) );
	}

	public function test_get_cached_credit_limit_falls_back_to_hardcoded_value_when_site_is_unregistered(): void {
		Functions\expect( 'get_transient' )
			->once()->with( 'plume_credit_limit_pro_managed' )->andReturn( false );
		// SiteRegistration::get_site_token() reads this option; empty means unregistered.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'plume_site_token' === $key ? '' : $default
		);
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_credit_limit_pro_managed', UsageTracker::PRO_MANAGED_CREDITS, \DAY_IN_SECONDS )
			->andReturn( true );

		$this->assertSame( UsageTracker::PRO_MANAGED_CREDITS, UsageTracker::get_cached_credit_limit( 'pro_managed' ) );
	}

	public function test_get_cached_credit_limit_uses_live_worker_value_on_success(): void {
		Functions\expect( 'get_transient' )
			->once()->with( 'plume_credit_limit_pro_managed' )->andReturn( false );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'plume_site_token' === $key ? 'sometoken' : $default
		);
		Functions\expect( 'wp_remote_get' )
			->once()
			->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'credit_limit' => 1_000_000, 'tier' => 'pro_managed' ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_credit_limit_pro_managed', 1_000_000, \DAY_IN_SECONDS )
			->andReturn( true );

		$this->assertSame( 1_000_000, UsageTracker::get_cached_credit_limit( 'pro_managed' ) );
	}

	public function test_get_cached_credit_limit_falls_back_when_worker_call_fails(): void {
		Functions\expect( 'get_transient' )
			->once()->with( 'plume_credit_limit_free' )->andReturn( false );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'plume_site_token' === $key ? 'sometoken' : $default
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_credit_limit_free', UsageTracker::FREE_CREDITS, \DAY_IN_SECONDS )
			->andReturn( true );

		$this->assertSame( UsageTracker::FREE_CREDITS, UsageTracker::get_cached_credit_limit( 'free' ) );
	}

	public function test_get_cached_credit_limit_falls_back_when_worker_response_is_malformed(): void {
		Functions\expect( 'get_transient' )
			->once()->with( 'plume_credit_limit_free' )->andReturn( false );
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) => 'plume_site_token' === $key ? 'sometoken' : $default
		);
		Functions\expect( 'wp_remote_get' )->once()->andReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		// Body has no credit_limit field at all.
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( [ 'tier' => 'free' ] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_credit_limit_free', UsageTracker::FREE_CREDITS, \DAY_IN_SECONDS )
			->andReturn( true );

		$this->assertSame( UsageTracker::FREE_CREDITS, UsageTracker::get_cached_credit_limit( 'free' ) );
	}
}

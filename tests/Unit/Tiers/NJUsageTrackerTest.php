<?php

namespace WP_AI_Mind\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Tests\Helpers\WpdbStubFactory;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

class NJUsageTrackerTest extends TestCase {

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
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_user_meta' )
			->once()->with( 1, 'wp_ai_mind_tier', true )->andReturn( 'free' );
		Functions\expect( 'get_user_meta' )
			->once()->with( 1, $month_key, true )->andReturn( '25000' );

		$usage = NJ_Usage_Tracker::get_usage();

		$this->assertSame( 'free', $usage['tier'] );
		$this->assertSame( 25000, $usage['used'] );
		$this->assertSame( 50000, $usage['limit'] );
		$this->assertSame( 25000, $usage['remaining'] );
		$this->assertTrue( $usage['can_use'] );
	}

	public function test_get_usage_can_use_false_when_limit_exceeded(): void {
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );

		// Passing explicit user_id — get_current_user_id is NOT called.
		Functions\expect( 'get_user_meta' )
			->once()->with( 2, 'wp_ai_mind_tier', true )->andReturn( 'free' );
		Functions\expect( 'get_user_meta' )
			->once()->with( 2, $month_key, true )->andReturn( '55000' );

		$usage = NJ_Usage_Tracker::get_usage( 2 );
		$this->assertFalse( $usage['can_use'] );
		$this->assertSame( 0, $usage['remaining'] );
	}

	public function test_get_usage_trial_tier_uses_300k_limit(): void {
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );

		// get_user_tier reads tier meta; is_trial_active reads tier + trial_started meta;
		// then get_usage reads month meta. Stub generically.
		Functions\when( 'get_user_meta' )->alias(
			function ( $uid, $key, $single ) use ( $month_key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'trial';
				}
				if ( 'wp_ai_mind_trial_started' === $key ) {
					return (string) time();
				}
				if ( $month_key === $key ) {
					return '100000';
				}
				return '';
			}
		);

		$usage = NJ_Usage_Tracker::get_usage( 5 );
		$this->assertSame( 'trial', $usage['tier'] );
		$this->assertSame( 300000, $usage['limit'] );
		$this->assertSame( 200000, $usage['remaining'] );
		$this->assertTrue( $usage['can_use'] );
	}

	public function test_get_usage_pro_byok_is_always_unlimited(): void {
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );

		// pro_byok now lives on the site option, not user meta.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'wp_ai_mind_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\expect( 'get_user_meta' )
			->once()->with( 3, $month_key, true )->andReturn( '999999' );

		$usage = NJ_Usage_Tracker::get_usage( 3 );
		$this->assertTrue( $usage['can_use'] );
		$this->assertNull( $usage['limit'] );
		$this->assertNull( $usage['remaining'] );
	}

	public function test_log_usage_performs_atomic_sql_increment(): void {
		global $wpdb;
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );

		$wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta      = 'wp_usermeta';
		$wpdb->rows_affected = 1;
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );

		NJ_Usage_Tracker::log_usage( 500 );
		$this->addToAssertionCount( 1 );
	}

	public function test_log_usage_falls_back_to_insert_when_no_row_exists(): void {
		global $wpdb;
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );

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

		NJ_Usage_Tracker::log_usage( 500 );
		$this->addToAssertionCount( 1 );
	}

	public function test_get_current_month_key_returns_expected_format(): void {
		$key = NJ_Usage_Tracker::get_current_month_key();

		$this->assertMatchesRegularExpression( '/^wp_ai_mind_usage_\d{4}_\d{2}$/', $key );
		$this->assertSame( 'wp_ai_mind_usage_' . gmdate( 'Y_m' ), $key );
	}

	public function test_check_limit_returns_false_when_exhausted(): void {
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_user_meta' )
			->once()->with( 1, 'wp_ai_mind_tier', true )->andReturn( 'free' );
		Functions\expect( 'get_user_meta' )
			->once()->with( 1, $month_key, true )->andReturn( '60000' );

		$this->assertFalse( NJ_Usage_Tracker::check_limit() );
	}
}

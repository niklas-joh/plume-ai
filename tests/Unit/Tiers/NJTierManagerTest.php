<?php
namespace WP_AI_Mind\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

/**
 * Unit tests for NJ_Tier_Manager.
 *
 * @since 1.1.0
 */
class NJTierManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── get_user_tier resolution order (paid > active trial > site default) ──

	public function test_get_user_tier_defaults_to_free_when_no_meta_and_no_site_option(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_option' )->once()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'free' );
		Functions\expect( 'get_user_meta' )->once()->with( 1, 'wp_ai_mind_tier', true )->andReturn( '' );

		$this->assertSame( 'free', NJ_Tier_Manager::get_user_tier() );
	}

	public function test_get_user_tier_returns_site_option_when_site_is_pro_managed(): void {
		// Site option wins over trial meta — paid status is per-site, not per-user.
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 5 );
		Functions\expect( 'get_option' )->once()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );

		$this->assertSame( 'pro_managed', NJ_Tier_Manager::get_user_tier() );
	}

	public function test_get_user_tier_returns_site_option_when_site_is_pro_byok(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 5 );
		Functions\expect( 'get_option' )->once()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'pro_byok' );

		$this->assertSame( 'pro_byok', NJ_Tier_Manager::get_user_tier() );
	}

	public function test_get_user_tier_returns_trial_when_meta_is_trial_and_active_and_site_is_free(): void {
		$started = (string) time();
		Functions\expect( 'get_option' )->once()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'free' );
		// get_user_tier reads meta once; is_trial_active reads it twice more.
		Functions\when( 'get_user_meta' )->alias(
			function ( $uid, $key ) use ( $started ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'trial';
				}
				return $started;
			}
		);

		$this->assertSame( 'trial', NJ_Tier_Manager::get_user_tier( 6 ) );
	}

	public function test_get_user_tier_returns_site_option_when_meta_is_trial_but_expired(): void {
		$expired = (string) ( time() - ( 31 * DAY_IN_SECONDS ) );
		Functions\expect( 'get_option' )->once()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'free' );
		Functions\when( 'get_user_meta' )->alias(
			function ( $uid, $key ) use ( $expired ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'trial';
				}
				return $expired;
			}
		);

		$this->assertSame( 'free', NJ_Tier_Manager::get_user_tier( 6 ) );
	}

	public function test_get_user_tier_short_circuits_to_site_option_when_no_user(): void {
		// $user_id <= 0 path: skip meta entirely, consult site option directly.
		Functions\expect( 'get_option' )->once()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );
		Functions\expect( 'get_user_meta' )->never();

		$this->assertSame( 'pro_managed', NJ_Tier_Manager::get_user_tier( 0 ) );
	}

	public function test_get_user_tier_returns_free_when_no_user_and_no_site_option(): void {
		Functions\expect( 'get_option' )->once()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'free' );
		Functions\expect( 'get_user_meta' )->never();

		$this->assertSame( 'free', NJ_Tier_Manager::get_user_tier( 0 ) );
	}

	// ── set_user_tier ────────────────────────────────────────────────────────

	public function test_set_user_tier_rejects_invalid_tier(): void {
		$this->assertFalse( NJ_Tier_Manager::set_user_tier( 'enterprise', 1 ) );
	}

	public function test_set_user_tier_stores_valid_tier(): void {
		Functions\expect( 'update_user_meta' )->once()->with( 3, 'wp_ai_mind_tier', 'pro_managed' )->andReturn( true );

		$this->assertTrue( NJ_Tier_Manager::set_user_tier( 'pro_managed', 3 ) );
	}

	// ── set_site_tier ────────────────────────────────────────────────────────

	public function test_set_site_tier_rejects_invalid_tier(): void {
		Functions\expect( 'update_option' )->never();
		$this->assertFalse( NJ_Tier_Manager::set_site_tier( 'enterprise' ) );
	}

	public function test_set_site_tier_stores_valid_tier_with_autoload_false(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( NJ_Tier_Manager::SITE_OPTION, 'pro_managed', false )
			->andReturn( true );
		Functions\expect( 'do_action' )
			->once()
			->with( 'wp_ai_mind_tier_changed', 'pro_managed' );

		$this->assertTrue( NJ_Tier_Manager::set_site_tier( 'pro_managed' ) );
	}

	public function test_set_site_tier_does_not_fire_action_on_failed_write(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( NJ_Tier_Manager::SITE_OPTION, 'pro_managed', false )
			->andReturn( false );
		Functions\expect( 'do_action' )->never();

		$this->assertFalse( NJ_Tier_Manager::set_site_tier( 'pro_managed' ) );
	}

	// ── user_can — exercised against the get_user_tier resolution path ──────

	public function test_free_user_can_chat_but_not_model_selection(): void {
		Functions\expect( 'get_current_user_id' )->twice()->andReturn( 1 );
		Functions\expect( 'get_option' )->twice()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'free' );
		Functions\expect( 'get_user_meta' )->twice()->with( 1, 'wp_ai_mind_tier', true )->andReturn( '' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'chat' ) );
		$this->assertFalse( NJ_Tier_Manager::user_can( 'model_selection' ) );
	}

	public function test_pro_managed_site_grants_model_selection(): void {
		Functions\expect( 'get_current_user_id' )->twice()->andReturn( 2 );
		Functions\expect( 'get_option' )->twice()->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'chat' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'model_selection' ) );
	}

	public function test_pro_byok_site_grants_all_features(): void {
		Functions\expect( 'get_current_user_id' )->times( 6 )->andReturn( 7 );
		Functions\expect( 'get_option' )->times( 6 )->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'pro_byok' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'chat' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'model_selection' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'own_api_key' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'generator' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'seo' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'images' ) );
	}

	public function test_free_user_cannot_use_content_features(): void {
		Functions\expect( 'get_current_user_id' )->times( 3 )->andReturn( 1 );
		Functions\expect( 'get_option' )->times( 3 )->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'free' );
		Functions\expect( 'get_user_meta' )->times( 3 )->with( 1, 'wp_ai_mind_tier', true )->andReturn( '' );

		$this->assertFalse( NJ_Tier_Manager::user_can( 'generator' ) );
		$this->assertFalse( NJ_Tier_Manager::user_can( 'seo' ) );
		$this->assertFalse( NJ_Tier_Manager::user_can( 'images' ) );
	}

	public function test_pro_managed_site_can_use_content_features(): void {
		Functions\expect( 'get_current_user_id' )->times( 3 )->andReturn( 2 );
		Functions\expect( 'get_option' )->times( 3 )->with( NJ_Tier_Manager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'generator' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'seo' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'images' ) );
	}

	// ── Monthly limit helpers ───────────────────────────────────────────────

	public function test_trial_tier_monthly_limit(): void {
		$this->assertSame( 300000, NJ_Tier_Manager::get_monthly_limit( 'trial' ) );
	}

	public function test_get_monthly_limit_returns_null_for_pro_byok(): void {
		$this->assertNull( NJ_Tier_Manager::get_monthly_limit( 'pro_byok' ) );
	}

	public function test_get_monthly_limit_returns_correct_values(): void {
		$this->assertSame( 50000, NJ_Tier_Manager::get_monthly_limit( 'free' ) );
		$this->assertSame( 300000, NJ_Tier_Manager::get_monthly_limit( 'trial' ) );
		$this->assertSame( 2000000, NJ_Tier_Manager::get_monthly_limit( 'pro_managed' ) );
	}

	// ── Trial management ───────────────────────────────────────────────────

	public function test_start_trial_sets_tier_and_timestamp(): void {
		Functions\expect( 'update_user_meta' )
			->once()->with( 4, 'wp_ai_mind_tier', 'trial' )->andReturn( true );
		Functions\expect( 'update_user_meta' )
			->once()->with( 4, 'wp_ai_mind_trial_started', \Mockery::type( 'int' ) )->andReturn( true );

		$this->assertTrue( NJ_Tier_Manager::start_trial( 4 ) );
	}

	public function test_is_trial_active_returns_false_for_non_trial_tier(): void {
		// is_trial_active() reads meta directly, not via get_user_tier.
		Functions\expect( 'get_user_meta' )->once()->with( 5, 'wp_ai_mind_tier', true )->andReturn( 'free' );

		$this->assertFalse( NJ_Tier_Manager::is_trial_active( 5 ) );
	}

	public function test_is_trial_active_returns_true_within_window(): void {
		Functions\expect( 'get_user_meta' )->once()->with( 6, 'wp_ai_mind_tier', true )->andReturn( 'trial' );
		Functions\expect( 'get_user_meta' )->once()->with( 6, 'wp_ai_mind_trial_started', true )->andReturn( (string) time() );

		$this->assertTrue( NJ_Tier_Manager::is_trial_active( 6 ) );
	}

	public function test_is_trial_active_returns_false_after_thirty_days(): void {
		$started = time() - ( 31 * DAY_IN_SECONDS );
		Functions\expect( 'get_user_meta' )->once()->with( 6, 'wp_ai_mind_tier', true )->andReturn( 'trial' );
		Functions\expect( 'get_user_meta' )->once()->with( 6, 'wp_ai_mind_trial_started', true )->andReturn( (string) $started );

		$this->assertFalse( NJ_Tier_Manager::is_trial_active( 6 ) );
	}

	public function test_is_trial_active_returns_true_within_thirty_days(): void {
		$started = time() - ( 29 * DAY_IN_SECONDS );
		Functions\expect( 'get_user_meta' )->once()->with( 6, 'wp_ai_mind_tier', true )->andReturn( 'trial' );
		Functions\expect( 'get_user_meta' )->once()->with( 6, 'wp_ai_mind_trial_started', true )->andReturn( (string) $started );

		$this->assertTrue( NJ_Tier_Manager::is_trial_active( 6 ) );
	}

	// ── maybe_demote_expired_trials — now deletes meta instead of overwriting ─

	/**
	 * Verifies that the loop exits after one pass when a full batch yields zero successful demotions.
	 *
	 * @since 1.1.0
	 */
	public function test_maybe_demote_expired_trials_exits_when_no_demotions_in_full_batch(): void {
		// 200 trial users, all still active — loop must exit after one pass (no progress).
		$user_ids   = range( 1, 200 );
		$started_at = (string) time(); // all trials started now, so none are expired.

		Functions\expect( 'get_users' )
			->once()
			->andReturn( $user_ids );

		// is_trial_active() calls get_user_meta twice per user: once for tier, once for trial_started.
		Functions\expect( 'get_user_meta' )
			->times( 400 )
			->andReturnUsing(
				function ( $uid, $key ) use ( $started_at ) {
					if ( 'wp_ai_mind_tier' === $key ) {
						return 'trial';
					}
					return $started_at;
				}
			);

		// delete_user_meta must NOT be called — no users are demoted.
		Functions\expect( 'delete_user_meta' )->never();

		NJ_Tier_Manager::maybe_demote_expired_trials();
		$this->addToAssertionCount( 1 ); // loop exited without infinite loop
	}

	/**
	 * Verifies that all expired-trial users are demoted across multiple batches and the loop terminates.
	 *
	 * @since 1.1.0
	 */
	public function test_maybe_demote_expired_trials_demotes_expired_users_and_continues(): void {
		$expired_start = time() - ( 31 * DAY_IN_SECONDS );

		Functions\expect( 'get_users' )
			->twice()
			->andReturn( range( 1, 200 ), range( 201, 210 ) );

		Functions\expect( 'get_user_meta' )
			->andReturnUsing(
				function ( $uid, $key ) use ( $expired_start ) {
					if ( 'wp_ai_mind_tier' === $key ) {
						return 'trial';
					}
					return (string) $expired_start;
				}
			);

		// delete_user_meta called once per expired user (210 total).
		Functions\expect( 'delete_user_meta' )
			->times( 210 )
			->with( \Mockery::type( 'int' ), 'wp_ai_mind_tier' )
			->andReturn( true );

		NJ_Tier_Manager::maybe_demote_expired_trials();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Verifies that the loop continues when a full batch contains a mix of expired and active
	 * trials, and terminates once a subsequent batch is smaller than the batch size.
	 *
	 * @since 1.1.0
	 */
	public function test_maybe_demote_expired_trials_continues_while_partial_batch_demoted(): void {
		$expired_start = time() - ( 31 * DAY_IN_SECONDS );
		$active_start  = (string) time();

		Functions\expect( 'get_users' )
			->twice()
			->andReturn( range( 1, 200 ), range( 201, 210 ) );

		Functions\expect( 'get_user_meta' )
			->andReturnUsing(
				function ( $uid, $key ) use ( $expired_start, $active_start ) {
					if ( 'wp_ai_mind_tier' === $key ) {
						return 'trial';
					}
					if ( $uid >= 101 && $uid <= 200 ) {
						return $active_start;
					}
					return (string) $expired_start;
				}
			);

		// delete_user_meta called once per expired user: 100 from batch 1 + 10 from batch 2.
		Functions\expect( 'delete_user_meta' )
			->times( 110 )
			->with( \Mockery::type( 'int' ), 'wp_ai_mind_tier' )
			->andReturn( true );

		NJ_Tier_Manager::maybe_demote_expired_trials();
		$this->addToAssertionCount( 1 );
	}

	public function test_tier_config_has_four_tiers(): void {
		$this->assertContains( 'free', NJ_Tier_Config::get_valid_tiers() );
		$this->assertContains( 'trial', NJ_Tier_Config::get_valid_tiers() );
		$this->assertContains( 'pro_managed', NJ_Tier_Config::get_valid_tiers() );
		$this->assertContains( 'pro_byok', NJ_Tier_Config::get_valid_tiers() );
		$this->assertCount( 4, NJ_Tier_Config::get_valid_tiers() );
	}
}

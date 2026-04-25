<?php
namespace WP_AI_Mind\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class NJTierManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_user_tier_defaults_to_free_when_no_meta(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_user_meta' )->once()->with( 1, 'wp_ai_mind_tier', true )->andReturn( '' );

		$this->assertSame( 'free', NJ_Tier_Manager::get_user_tier() );
	}

	public function test_get_user_tier_returns_stored_value(): void {
		Functions\expect( 'get_current_user_id' )->once()->andReturn( 5 );
		Functions\expect( 'get_user_meta' )->once()->with( 5, 'wp_ai_mind_tier', true )->andReturn( 'pro_byok' );

		$this->assertSame( 'pro_byok', NJ_Tier_Manager::get_user_tier() );
	}

	public function test_set_user_tier_rejects_invalid_tier(): void {
		$this->assertFalse( NJ_Tier_Manager::set_user_tier( 'enterprise', 1 ) );
	}

	public function test_set_user_tier_stores_valid_tier(): void {
		Functions\expect( 'update_user_meta' )->once()->with( 3, 'wp_ai_mind_tier', 'pro_managed' )->andReturn( true );

		$this->assertTrue( NJ_Tier_Manager::set_user_tier( 'pro_managed', 3 ) );
	}

	public function test_free_user_can_chat_but_not_model_selection(): void {
		Functions\expect( 'get_current_user_id' )->twice()->andReturn( 1 );
		Functions\expect( 'get_user_meta' )->twice()->with( 1, 'wp_ai_mind_tier', true )->andReturn( 'free' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'chat' ) );
		$this->assertFalse( NJ_Tier_Manager::user_can( 'model_selection' ) );
	}

	public function test_trial_user_can_chat_but_not_model_selection(): void {
		Functions\expect( 'get_current_user_id' )->twice()->andReturn( 10 );
		Functions\expect( 'get_user_meta' )->twice()->with( 10, 'wp_ai_mind_tier', true )->andReturn( 'trial' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'chat' ) );
		$this->assertFalse( NJ_Tier_Manager::user_can( 'model_selection' ) );
	}

	public function test_trial_tier_monthly_limit(): void {
		$this->assertSame( 300000, NJ_Tier_Manager::get_monthly_limit( 'trial' ) );
	}

	public function test_pro_managed_user_can_chat_and_model_selection(): void {
		Functions\expect( 'get_current_user_id' )->twice()->andReturn( 2 );
		Functions\expect( 'get_user_meta' )->twice()->with( 2, 'wp_ai_mind_tier', true )->andReturn( 'pro_managed' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'chat' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'model_selection' ) );
	}

	public function test_pro_byok_user_can_all_features(): void {
		Functions\expect( 'get_current_user_id' )->times( 6 )->andReturn( 7 );
		Functions\expect( 'get_user_meta' )->times( 6 )->with( 7, 'wp_ai_mind_tier', true )->andReturn( 'pro_byok' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'chat' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'model_selection' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'own_api_key' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'generator' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'seo' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'images' ) );
	}

	public function test_free_user_cannot_use_content_features(): void {
		Functions\expect( 'get_current_user_id' )->times( 3 )->andReturn( 1 );
		Functions\expect( 'get_user_meta' )->times( 3 )->with( 1, 'wp_ai_mind_tier', true )->andReturn( 'free' );

		$this->assertFalse( NJ_Tier_Manager::user_can( 'generator' ) );
		$this->assertFalse( NJ_Tier_Manager::user_can( 'seo' ) );
		$this->assertFalse( NJ_Tier_Manager::user_can( 'images' ) );
	}

	public function test_trial_user_can_use_content_features(): void {
		Functions\expect( 'get_current_user_id' )->times( 3 )->andReturn( 10 );
		Functions\expect( 'get_user_meta' )->times( 3 )->with( 10, 'wp_ai_mind_tier', true )->andReturn( 'trial' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'generator' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'seo' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'images' ) );
	}

	public function test_pro_managed_user_can_use_content_features(): void {
		Functions\expect( 'get_current_user_id' )->times( 3 )->andReturn( 2 );
		Functions\expect( 'get_user_meta' )->times( 3 )->with( 2, 'wp_ai_mind_tier', true )->andReturn( 'pro_managed' );

		$this->assertTrue( NJ_Tier_Manager::user_can( 'generator' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'seo' ) );
		$this->assertTrue( NJ_Tier_Manager::user_can( 'images' ) );
	}

	public function test_get_monthly_limit_returns_null_for_pro_byok(): void {
		$this->assertNull( NJ_Tier_Manager::get_monthly_limit( 'pro_byok' ) );
	}

	public function test_get_monthly_limit_returns_correct_values(): void {
		$this->assertSame( 50000, NJ_Tier_Manager::get_monthly_limit( 'free' ) );
		$this->assertSame( 300000, NJ_Tier_Manager::get_monthly_limit( 'trial' ) );
		$this->assertSame( 2000000, NJ_Tier_Manager::get_monthly_limit( 'pro_managed' ) );
	}

	public function test_start_trial_sets_tier_and_timestamp(): void {
		Functions\expect( 'update_user_meta' )
			->once()->with( 4, 'wp_ai_mind_tier', 'trial' )->andReturn( true );
		Functions\expect( 'update_user_meta' )
			->once()->with( 4, 'wp_ai_mind_trial_started', \Mockery::type( 'int' ) )->andReturn( true );

		$this->assertTrue( NJ_Tier_Manager::start_trial( 4 ) );
	}

	public function test_is_trial_active_returns_false_for_non_trial_tier(): void {
		Functions\expect( 'get_current_user_id' )->never();
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

	public function test_tier_config_has_four_tiers(): void {
		$this->assertContains( 'free', NJ_Tier_Config::get_valid_tiers() );
		$this->assertContains( 'trial', NJ_Tier_Config::get_valid_tiers() );
		$this->assertContains( 'pro_managed', NJ_Tier_Config::get_valid_tiers() );
		$this->assertContains( 'pro_byok', NJ_Tier_Config::get_valid_tiers() );
		$this->assertCount( 4, NJ_Tier_Config::get_valid_tiers() );
	}
}

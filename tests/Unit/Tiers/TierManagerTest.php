<?php
namespace Plume\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Payments\TierUpdateWebhookController;
use Plume\Tiers\TierConfig;
use Plume\Tiers\TierManager;

/**
 * Unit tests for TierManager.
 *
 * @since 1.1.0
 */
class TierManagerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── get_user_tier resolution order (paid > site default; no per-user trial) ──

	/**
	 * get_user_tier() takes no parameters — paid status is a site-level fact and
	 * there is no longer a per-user trial state to resolve, so $user_id (which was
	 * never actually consumed by the resolution logic) was removed entirely rather
	 * than kept unused for call-site compatibility.
	 */
	/**
	 * user_can() was deleted outright once its last two gates (model_selection,
	 * own_api_key) were removed for WP.org Guideline 5 compliance — no feature
	 * is tier-gated in PHP any more, so a feature-gate API must not exist for
	 * future code to quietly reintroduce one.
	 */
	public function test_user_can_method_does_not_exist(): void {
		$this->assertFalse(
			method_exists( TierManager::class, 'user_can' ),
			'user_can() must not exist — no feature is tier-gated (WP.org Guideline 5).'
		);
	}

	public function test_get_user_tier_has_no_parameters(): void {
		$method = new \ReflectionMethod( TierManager::class, 'get_user_tier' );
		$this->assertCount( 0, $method->getParameters() );
	}

	public function test_get_user_tier_defaults_to_free_when_no_site_option(): void {
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( 'free' );

		$this->assertSame( 'free', TierManager::get_user_tier() );
	}

	public function test_get_user_tier_returns_site_option_when_site_is_pro_managed(): void {
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );
		Functions\expect( 'get_option' )->once()->with( TierUpdateWebhookController::OPTION_SECRET, '' )->andReturn( '' );

		$this->assertSame( 'pro_managed', TierManager::get_user_tier() );
	}

	public function test_get_user_tier_returns_site_option_when_site_is_pro_byok(): void {
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( 'pro_byok' );
		Functions\expect( 'get_option' )->once()->with( TierUpdateWebhookController::OPTION_SECRET, '' )->andReturn( '' );

		$this->assertSame( 'pro_byok', TierManager::get_user_tier() );
	}

	// ── set_site_tier ────────────────────────────────────────────────────────

	public function test_set_site_tier_rejects_invalid_tier(): void {
		Functions\expect( 'update_option' )->never();
		$this->assertFalse( TierManager::set_site_tier( 'enterprise' ) );
	}

	public function test_set_site_tier_rejects_trial_tier(): void {
		Functions\expect( 'update_option' )->never();
		$this->assertFalse( TierManager::set_site_tier( 'trial' ) );
	}

	public function test_set_site_tier_stores_valid_tier_with_autoload_false(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( TierManager::SITE_OPTION, 'pro_managed', false )
			->andReturn( true );
		// No sync secret on this site — skip signature write.
		Functions\expect( 'get_option' )
			->once()
			->with( TierUpdateWebhookController::OPTION_SECRET, '' )
			->andReturn( '' );
		Functions\expect( 'do_action' )
			->once()
			->with( 'plume_tier_changed', 'pro_managed' );

		$this->assertTrue( TierManager::set_site_tier( 'pro_managed' ) );
	}

	public function test_set_site_tier_does_not_fire_action_on_failed_write(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( TierManager::SITE_OPTION, 'pro_managed', false )
			->andReturn( false );
		Functions\expect( 'do_action' )->never();

		$this->assertFalse( TierManager::set_site_tier( 'pro_managed' ) );
	}

	public function test_is_paid_returns_false_for_free_tier(): void {
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( 'free' );

		$this->assertFalse( TierManager::is_paid() );
	}

	public function test_is_paid_returns_true_for_pro_managed(): void {
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );
		Functions\expect( 'get_option' )->once()->with( TierUpdateWebhookController::OPTION_SECRET, '' )->andReturn( '' );

		$this->assertTrue( TierManager::is_paid() );
	}

	public function test_tier_config_has_three_tiers(): void {
		$this->assertContains( 'free', TierConfig::get_valid_tiers() );
		$this->assertContains( 'pro_managed', TierConfig::get_valid_tiers() );
		$this->assertContains( 'pro_byok', TierConfig::get_valid_tiers() );
		$this->assertNotContains( 'trial', TierConfig::get_valid_tiers() );
		$this->assertCount( 3, TierConfig::get_valid_tiers() );
	}

	// ── Trial lifecycle methods removed entirely — no successor methods ──────

	public function test_start_trial_method_does_not_exist(): void {
		$this->assertFalse( method_exists( TierManager::class, 'start_trial' ) );
	}

	public function test_is_trial_active_method_does_not_exist(): void {
		$this->assertFalse( method_exists( TierManager::class, 'is_trial_active' ) );
	}

	public function test_maybe_demote_expired_trials_method_does_not_exist(): void {
		$this->assertFalse( method_exists( TierManager::class, 'maybe_demote_expired_trials' ) );
	}

	public function test_get_monthly_limit_method_does_not_exist(): void {
		$this->assertFalse( method_exists( TierManager::class, 'get_monthly_limit' ) );
	}

	public function test_set_user_tier_method_does_not_exist(): void {
		// Zero production callers once the trial tier (its only per-user write
		// path) was removed — deleted outright rather than kept "just in case".
		$this->assertFalse( method_exists( TierManager::class, 'set_user_tier' ) );
	}

	public function test_trial_started_meta_constant_does_not_exist(): void {
		$this->assertFalse(
			( new \ReflectionClass( TierManager::class ) )->hasConstant( 'TRIAL_STARTED_META' )
		);
	}

	// ── Tier integrity (HMAC signature verification) ──────────────────────────

	/**
	 * @since 1.10.0
	 */
	public function test_get_user_tier_returns_paid_tier_when_signature_is_valid(): void {
		$secret = 'test-secret';
		$tier   = 'pro_byok';
		$sig    = hash_hmac( 'sha256', $tier, $secret );

		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( $tier );
		Functions\expect( 'get_option' )->once()->with( TierUpdateWebhookController::OPTION_SECRET, '' )->andReturn( $secret );
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION_SIG, '' )->andReturn( $sig );

		$this->assertSame( 'pro_byok', TierManager::get_user_tier() );
	}

	/**
	 * Rewritten for the no-trial-meta body: a wrong signature falls straight through
	 * to 'free' (no more "fall through to trial check" branch to fall through to).
	 *
	 * @since 1.10.0
	 */
	public function test_get_user_tier_returns_free_when_signature_is_wrong(): void {
		$secret = 'test-secret';
		$tier   = 'pro_byok';

		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( $tier );
		Functions\expect( 'get_option' )->once()->with( TierUpdateWebhookController::OPTION_SECRET, '' )->andReturn( $secret );
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION_SIG, '' )->andReturn( 'tampered-value' );
		Functions\expect( 'get_user_meta' )->never();

		$this->assertSame( 'free', TierManager::get_user_tier() );
	}

	/**
	 * Rewritten for the no-trial-meta body: an absent signature falls straight
	 * through to 'free'.
	 *
	 * @since 1.10.0
	 */
	public function test_get_user_tier_returns_free_when_signature_is_absent_but_secret_exists(): void {
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );
		Functions\expect( 'get_option' )->once()->with( TierUpdateWebhookController::OPTION_SECRET, '' )->andReturn( 'some-secret' );
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION_SIG, '' )->andReturn( '' );
		Functions\expect( 'get_user_meta' )->never();

		$this->assertSame( 'free', TierManager::get_user_tier() );
	}

	/**
	 * @since 1.10.0
	 */
	public function test_get_user_tier_trusts_paid_tier_when_no_secret_exists(): void {
		// Unregistered site — no secret means no verification; stored value is trusted.
		Functions\expect( 'get_option' )->once()->with( TierManager::SITE_OPTION, 'free' )->andReturn( 'pro_managed' );
		Functions\expect( 'get_option' )->once()->with( TierUpdateWebhookController::OPTION_SECRET, '' )->andReturn( '' );

		$this->assertSame( 'pro_managed', TierManager::get_user_tier() );
	}

	/**
	 * @since 1.10.0
	 */
	public function test_set_site_tier_writes_hmac_signature_when_secret_exists(): void {
		$secret = 'my-secret';
		$tier   = 'pro_managed';
		$sig    = hash_hmac( 'sha256', $tier, $secret );

		Functions\expect( 'update_option' )
			->once()
			->with( TierManager::SITE_OPTION, $tier, false )
			->andReturn( true );
		Functions\expect( 'get_option' )
			->once()
			->with( TierUpdateWebhookController::OPTION_SECRET, '' )
			->andReturn( $secret );
		Functions\expect( 'update_option' )
			->once()
			->with( TierManager::SITE_OPTION_SIG, $sig, false )
			->andReturn( true );
		Functions\expect( 'do_action' )
			->once()
			->with( 'plume_tier_changed', $tier );

		$this->assertTrue( TierManager::set_site_tier( $tier ) );
	}

	/**
	 * @since 1.10.0
	 */
	public function test_set_site_tier_skips_signature_when_no_secret_exists(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( TierManager::SITE_OPTION, 'free', false )
			->andReturn( true );
		Functions\expect( 'get_option' )
			->once()
			->with( TierUpdateWebhookController::OPTION_SECRET, '' )
			->andReturn( '' );
		// SITE_OPTION_SIG must NOT be written when there is no secret.
		Functions\expect( 'update_option' )
			->never()
			->with( TierManager::SITE_OPTION_SIG, \Mockery::any(), false );
		Functions\expect( 'do_action' )
			->once()
			->with( 'plume_tier_changed', 'free' );

		$this->assertTrue( TierManager::set_site_tier( 'free' ) );
	}
}

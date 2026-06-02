<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Admin\DevToolsPage;

// Ensure the constant is defined for all tests in this class.
if ( ! defined( 'WP_AI_MIND_DEV_KEY' ) ) {
	define( 'WP_AI_MIND_DEV_KEY', 'test-dev-key' );
}

class DevToolsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── is_active() — capability guard ───────────────────────────────────────

	public function test_is_active_returns_false_when_user_lacks_manage_options(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( DevToolsPage::is_active() );
	}

	// ── is_active() — first activation ───────────────────────────────────────

	public function test_is_active_stores_hash_and_returns_true_on_first_activation(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		// No stored hash yet — simulate a fresh install.
		Functions\expect( 'get_option' )
			->once()
			->andReturn( '' );
		Functions\expect( 'update_option' )
			->once()
			->andReturn( true );

		$this->assertTrue( DevToolsPage::is_active() );
	}

	// ── is_active() — subsequent call, matching hash ─────────────────────────

	public function test_is_active_returns_true_when_stored_hash_matches(): void {
		$expected_hash = hash_hmac( 'sha256', 'test-dev-key', 'test-salt' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\expect( 'get_option' )
			->once()
			->andReturn( $expected_hash );

		$this->assertTrue( DevToolsPage::is_active() );
	}

	// ── is_active() — key changed, hash mismatch ─────────────────────────────

	public function test_is_active_returns_false_when_stored_hash_does_not_match(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		// Stored hash belongs to a different (old) key value.
		Functions\expect( 'get_option' )
			->once()
			->andReturn( 'stale-hash-that-does-not-match' );

		$this->assertFalse( DevToolsPage::is_active() );
	}
}

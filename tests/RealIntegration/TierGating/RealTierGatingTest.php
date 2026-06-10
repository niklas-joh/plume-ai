<?php
/**
 * Real tier gating tests — no API key required.
 *
 * Exercises the full WordPress REST permission system without any HTTP mocking.
 * Rejections happen before any AI call, so no secrets are needed.
 * Covers all 4 tiers × gated features from TierConfig::FEATURES.
 *
 * @package Plume\Tests\RealIntegration\TierGating
 */

declare( strict_types=1 );

namespace Plume\Tests\RealIntegration\TierGating;

use Plume\Tests\RealIntegration\RealIntegrationTestCase;

/**
 * @since 1.8.0
 */
class RealTierGatingTest extends RealIntegrationTestCase {

	// ── Free tier — only chat is accessible

	/**
	 * Free tier: conversation creation (chat gateway) is permitted.
	 *
	 * Conversation creation does not invoke an AI call, so this test runs
	 * without any API key.
	 *
	 * @since 1.8.0
	 */
	public function test_free_tier_can_create_conversation(): void {
		$this->activate_free_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'Free Tier Test' ] );
		$this->assertSame(
			201,
			$response->get_status(),
			'Free tier must be able to create a conversation (chat is universally accessible).'
		);
	}

	/**
	 * Free tier: generator endpoint returns 403.
	 *
	 * @since 1.8.0
	 */
	public function test_free_tier_generator_is_blocked(): void {
		$this->activate_free_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/generate', [ 'title' => 'test' ] );
		$this->assertSame( 403, $response->get_status(), 'Free tier must receive 403 from the generator endpoint.' );
	}

	/**
	 * Free tier: SEO generate endpoint returns 403.
	 *
	 * @since 1.8.0
	 */
	public function test_free_tier_seo_is_blocked(): void {
		$this->activate_free_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$post_id  = self::factory()->post->create( [ 'post_status' => 'draft', 'post_author' => self::$editor_user_id ] );
		$response = $this->rest_do( 'POST', '/plume/v1/seo/generate', [ 'post_id' => $post_id ] );
		$this->assertSame( 403, $response->get_status(), 'Free tier must receive 403 from the SEO endpoint.' );
	}

	/**
	 * Free tier: images generate endpoint returns 403.
	 *
	 * @since 1.8.0
	 */
	public function test_free_tier_images_is_blocked(): void {
		$this->activate_free_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/images/generate', [ 'prompt' => 'test' ] );
		$this->assertSame( 403, $response->get_status(), 'Free tier must receive 403 from the images endpoint.' );
	}

	/**
	 * Subscriber role: chat endpoint returns 403 regardless of tier.
	 *
	 * Verifies that the WordPress capability check (edit_posts) operates
	 * independently of tier.
	 *
	 * @since 1.8.0
	 */
	public function test_subscriber_role_is_blocked_from_chat(): void {
		$this->activate_trial_tier( self::$subscriber_user_id );
		wp_set_current_user( self::$subscriber_user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'Subscriber Test' ] );
		$this->assertSame(
			403,
			$response->get_status(),
			'Subscribers (no edit_posts) must receive 403 even on trial tier.'
		);
	}

	// ── Trial tier — all features accessible

	/**
	 * Trial tier: generator endpoint is accessible (permission check passes).
	 *
	 * No AI call happens — the endpoint will return 422 (missing API key) or
	 * similar once past the gate. Any non-403 status confirms the gate opened.
	 *
	 * @since 1.8.0
	 */
	public function test_trial_tier_generator_is_accessible(): void {
		$this->activate_trial_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/generate', [ 'title' => 'test' ] );
		$this->assertNotSame(
			403,
			$response->get_status(),
			'Trial tier must not receive 403 from the generator endpoint.'
		);
	}

	/**
	 * Trial tier: SEO endpoint is accessible.
	 *
	 * @since 1.8.0
	 */
	public function test_trial_tier_seo_is_accessible(): void {
		$this->activate_trial_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$post_id  = self::factory()->post->create( [ 'post_status' => 'draft', 'post_author' => self::$editor_user_id ] );
		$response = $this->rest_do( 'POST', '/plume/v1/seo/generate', [ 'post_id' => $post_id ] );
		$this->assertNotSame(
			403,
			$response->get_status(),
			'Trial tier must not receive 403 from the SEO endpoint.'
		);
	}

	/**
	 * Trial tier: images endpoint is accessible.
	 *
	 * @since 1.8.0
	 */
	public function test_trial_tier_images_is_accessible(): void {
		$this->activate_trial_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/images/generate', [ 'prompt' => 'test' ] );
		$this->assertNotSame(
			403,
			$response->get_status(),
			'Trial tier must not receive 403 from the images endpoint.'
		);
	}

	// ── Conversation ownership

	/**
	 * A user cannot send messages to another user's conversation.
	 *
	 * @since 1.8.0
	 */
	public function test_cross_user_conversation_access_blocked(): void {
		// Owner creates a conversation.
		$this->activate_trial_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );
		$create  = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'Owner Convo' ] );
		$conv_id = $create->get_data()['id'];

		// A different user tries to post into it.
		$other_user = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->activate_trial_tier( $other_user );
		wp_set_current_user( $other_user );

		$response = $this->rest_do(
			'POST',
			"/plume/v1/conversations/{$conv_id}/messages",
			[ 'content' => 'Intrusion attempt.' ]
		);
		$this->assertSame( 403, $response->get_status(), 'Cross-user conversation access must be blocked.' );
	}
}

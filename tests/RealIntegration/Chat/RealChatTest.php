<?php
/**
 * Real integration tests for the chat feature.
 *
 * Makes live Anthropic API calls via the Pro-BYOK path.
 * SKIPPED when CLAUDE_API_KEY is absent.
 *
 * Cost: ~$0.0003/run (claude-haiku-4-5-20251001, minimal prompts).
 *
 * @package Plume\Tests\RealIntegration\Chat
 */

declare( strict_types=1 );

namespace Plume\Tests\RealIntegration\Chat;

use Plume\Tests\RealIntegration\RealIntegrationTestCase;

/**
 * @since 1.8.0
 */
class RealChatTest extends RealIntegrationTestCase {

	/** @since 1.8.0 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::skip_without_api_key();
	}

	/** @since 1.8.0 */
	public function setUp(): void {
		parent::setUp();
		$this->activate_byok_tier( self::$editor_user_id );
	}

	/**
	 * Chat feature returns a real non-empty AI response via Pro-BYOK.
	 *
	 * @since 1.8.0
	 */
	public function test_send_message_returns_ai_response(): void {
		wp_set_current_user( self::$editor_user_id );

		$create = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'Real Chat Test' ] );
		$this->assertSame( 201, $create->get_status() );
		$conv_id = $create->get_data()['id'];

		// No HTTP mock — live Anthropic API call.
		$response = $this->rest_do(
			'POST',
			"/plume/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'Reply with only the word "pong". Nothing else.',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$data = $response->get_data();
		$this->assertSame(
			200,
			$response->get_status(),
			sprintf( 'Expected 200, got %d. Body: %s', $response->get_status(), wp_json_encode( $data ) )
		);
		$this->assertNotEmpty( $data['content'] ?? '' );
		// BYOK tier calls Anthropic directly; credits_charged = 0 (no Plume credits consumed).
		$this->assertGreaterThanOrEqual( 0, (int) ( $data['credits'] ?? 0 ) );
	}

	/**
	 * Conversation history is persisted after a real AI turn.
	 *
	 * @since 1.8.0
	 */
	public function test_message_history_persisted_after_real_turn(): void {
		wp_set_current_user( self::$editor_user_id );

		$create  = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'History Test' ] );
		$conv_id = $create->get_data()['id'];

		$this->rest_do(
			'POST',
			"/plume/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'Say "ok".',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$history  = $this->rest_do( 'GET', "/plume/v1/conversations/{$conv_id}/messages" );
		$messages = $history->get_data();

		$this->assertGreaterThanOrEqual( 2, count( $messages ), 'Must have user + assistant turns.' );
		$this->assertContains( 'user', array_column( $messages, 'role' ) );
		$this->assertContains( 'assistant', array_column( $messages, 'role' ) );
	}

	/**
	 * Multi-turn conversation context is preserved across real API turns.
	 *
	 * @since 1.8.0
	 */
	public function test_multi_turn_conversation_maintains_context(): void {
		wp_set_current_user( self::$editor_user_id );

		$create  = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'Context Test' ] );
		$conv_id = $create->get_data()['id'];

		// First turn: tell Claude a number. Assert it succeeds so a failure here
		// produces a clear diagnostic rather than a confusing "no context" message
		// on the second turn caused by a missing assistant message in history.
		$first = $this->rest_do(
			'POST',
			"/plume/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'My secret number is 77. Acknowledge with just "ack".',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);
		$first_data = $first->get_data();
		$this->assertSame(
			200,
			$first->get_status(),
			sprintf( 'First turn must succeed (200) before testing context. Got %d. Body: %s', $first->get_status(), wp_json_encode( $first_data ) )
		);

		// Verify both user and assistant messages are in the DB before the second turn.
		$history = $this->rest_do( 'GET', "/plume/v1/conversations/{$conv_id}/messages" );
		$this->assertGreaterThanOrEqual( 2, count( $history->get_data() ), 'History must contain user + assistant before second turn.' );

		// Second turn: ask Claude to repeat from the in-context messages above.
		// Phrasing avoids "secret"/"recall" language that triggers Haiku's
		// "I don't have persistent memory" reflex; instead anchors the question
		// explicitly to the current conversation's message history.
		$recall = $this->rest_do(
			'POST',
			"/plume/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'Looking at the messages above in this conversation, what number did I give you at the start? Reply with just the number.',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$recall_data = $recall->get_data();
		$this->assertSame(
			200,
			$recall->get_status(),
			sprintf( 'Second turn must return 200. Got %d. Body: %s', $recall->get_status(), wp_json_encode( $recall_data ) )
		);
		$this->assertStringContainsString(
			'77',
			(string) ( $recall_data['content'] ?? '' ),
			sprintf( 'Context not preserved. Claude replied: %s', $recall_data['content'] ?? '(empty)' )
		);
	}
}

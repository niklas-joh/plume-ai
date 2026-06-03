<?php
/**
 * Integration tests for the chat conversation REST endpoints.
 *
 * Exercises the full conversation lifecycle — creation, message sending,
 * and history retrieval — against a real WordPress instance, including
 * permission checks and database writes.
 *
 * @package Stilus\Tests\Integration\Chat
 */

declare( strict_types=1 );

namespace Stilus\Tests\Integration\Chat;

use Stilus\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for the chat conversation REST endpoints.
 *
 * Covers:
 *   POST   /stilus/v1/conversations               — creation + permissions
 *   POST   /stilus/v1/conversations/{id}/messages — AI turn + HTTP interception
 *   GET    /stilus/v1/conversations/{id}/messages — history retrieval
 *
 * @since 1.0.0
 */
class ChatConversationTest extends IntegrationTestCase {

	/**
	 * Verify that a user without edit_posts cannot create a conversation.
	 *
	 * A subscriber lacks the edit_posts capability, so the check_permission
	 * callback must reject the request with a 403 before any DB write.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_create_conversation_requires_edit_posts(): void {
		wp_set_current_user( self::$subscriber_user_id );

		$response = $this->rest_do( 'POST', '/stilus/v1/conversations', [ 'title' => 'Test Conversation' ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verify that an editor-role user can create a conversation and receives 201.
	 *
	 * The response body must contain an 'id' key so subsequent requests can
	 * reference the conversation.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_create_conversation_succeeds_for_editor(): void {
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do( 'POST', '/stilus/v1/conversations', [ 'title' => 'My Test Chat' ] );

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data, 'Response must include an id key.' );
		$this->assertGreaterThan( 0, $data['id'], 'Returned conversation id must be a positive integer.' );
	}

	/**
	 * Verify that sending a message to a conversation returns the AI response.
	 *
	 * An editor creates a conversation, installs an HTTP fixture that returns
	 * a known response text, and then posts a message. The test asserts that
	 * the response status is 200 and the body contains the fixture text.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_send_message_and_receive_response(): void {
		wp_set_current_user( self::$editor_user_id );

		// Step 1 — create a conversation.
		$create_response = $this->rest_do( 'POST', '/stilus/v1/conversations', [ 'title' => 'AI Test' ] );
		$this->assertSame( 201, $create_response->get_status() );
		$conv_id = $create_response->get_data()['id'];

		// Step 2 — install HTTP fixture returning a known Claude-format response.
		$fixture_text = 'Integration test response text';
		$fixture      = [
			'content' => [
				[
					'type' => 'text',
					'text' => $fixture_text,
				],
			],
			'usage'   => [
				'input_tokens'  => 10,
				'output_tokens' => 5,
			],
			'model'   => 'claude-opus-4-6',
		];
		$this->mock_http_with_claude_fixture( $fixture );

		// Step 3 — send a message.
		$response = $this->rest_do(
			'POST',
			"/stilus/v1/conversations/{$conv_id}/messages",
			[ 'content' => 'Hello' ]
		);

		$this->assertSame( 200, $response->get_status(), 'Message endpoint must return 200 for a valid editor.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'content', $data, 'Response must include a content key.' );
		$this->assertSame( $fixture_text, $data['content'], 'Response content must match the fixture text.' );
	}

	/**
	 * Verify that the messages endpoint returns the conversation history after sending a message.
	 *
	 * After posting a user message (intercepted by the HTTP mock), a GET request
	 * to the messages endpoint must return at least the user's message.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_list_messages_returns_conversation_history(): void {
		wp_set_current_user( self::$editor_user_id );

		// Create the conversation.
		$create_response = $this->rest_do( 'POST', '/stilus/v1/conversations', [ 'title' => 'History Test' ] );
		$this->assertSame( 201, $create_response->get_status() );
		$conv_id = $create_response->get_data()['id'];

		// Install HTTP fixture so the AI turn completes successfully.
		$this->mock_http_with_claude_fixture(
			[
				'content' => [
					[
						'type' => 'text',
						'text' => 'Reply from AI.',
					],
				],
				'usage'   => [
					'input_tokens'  => 5,
					'output_tokens' => 3,
				],
				'model'   => 'claude-opus-4-6',
			]
		);

		// Send a message so there is something in the DB.
		$this->rest_do(
			'POST',
			"/stilus/v1/conversations/{$conv_id}/messages",
			[ 'content' => 'What is the capital of Sweden?' ]
		);

		// Retrieve history.
		$response = $this->rest_do( 'GET', "/stilus/v1/conversations/{$conv_id}/messages" );

		$this->assertSame( 200, $response->get_status() );

		$messages = $response->get_data();
		$this->assertIsArray( $messages, 'Messages response must be an array.' );
		$this->assertNotEmpty( $messages, 'Messages list must not be empty after sending a message.' );

		// The first stored message should be the user turn.
		$roles = array_column( $messages, 'role' );
		$this->assertContains( 'user', $roles, 'History must include at least one user message.' );
	}
}

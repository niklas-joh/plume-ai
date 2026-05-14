<?php
/**
 * Integration-layer tier × feature matrix tests.
 *
 * Makes real REST requests and asserts on HTTP status codes to verify that
 * tier gates allow or block access exactly as defined in NJ_Tier_Config::FEATURES.
 *
 * @package WP_AI_Mind\Tests\Integration
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Integration;

/**
 * Parametrised integration tests covering all tier × feature × expected-status combinations.
 *
 * Free tier: chat=allowed, seo/images/generator=blocked (403).
 * Trial tier: all features allowed (2xx).
 *
 * @since 1.0.0
 */
class TierFeatureMatrixTest extends IntegrationTestCase {

	// ── Data provider ─────────────────────────────────────────────────────────

	/**
	 * Return all tier × feature × expected-status rows to drive the matrix test.
	 *
	 * Each row: [ tier, feature, expected_status ].
	 * expected_status is the HTTP status that the permission gate produces:
	 * 403 = blocked; 0 = allowed (any 2xx is accepted, since endpoint success
	 * codes vary: chat=200, seo=200, generator=201, images=201/207).
	 *
	 * @since 1.0.0
	 * @return array<string, array{string, string, int}>
	 */
	public static function tier_feature_matrix(): array {
		return [
			// Free tier — only chat is permitted.
			'free/chat'      => [ 'free', 'chat', 0 ],
			'free/seo'       => [ 'free', 'seo', 403 ],
			'free/generator' => [ 'free', 'generator', 403 ],
			'free/images'    => [ 'free', 'images', 403 ],

			// Trial tier — all features are permitted.
			'trial/chat'      => [ 'trial', 'chat', 0 ],
			'trial/seo'       => [ 'trial', 'seo', 0 ],
			'trial/generator' => [ 'trial', 'generator', 0 ],
			'trial/images'    => [ 'trial', 'images', 0 ],
		];
	}

	// ── Test ──────────────────────────────────────────────────────────────────

	/**
	 * Verify that each tier × feature combination produces the expected HTTP status.
	 *
	 * When expected_status is 403, the test asserts the permission gate blocked access.
	 * When expected_status is 0, the test asserts the gate allowed access (any 2xx).
	 *
	 * @since 1.0.0
	 * @dataProvider tier_feature_matrix
	 * @param string $tier            Tier slug to assign to the test user.
	 * @param string $feature         Feature slug under test ('chat', 'seo', 'generator', 'images').
	 * @param int    $expected_status 403 = must be blocked; 0 = must be allowed (2xx).
	 * @return void
	 */
	public function test_tier_feature_gate( string $tier, string $feature, int $expected_status ): void {
		// Create a fresh editor user per row to avoid state leaking between iterations.
		$user_id = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => "matrix_{$tier}_{$feature}_" . uniqid(),
			]
		);
		$this->set_user_tier( $user_id, $tier );
		wp_set_current_user( $user_id );

		// When expecting success, install an HTTP mock before calling the endpoint.
		if ( 0 === $expected_status ) {
			$this->install_mock_for_feature( $feature );
		}

		$response = $this->call_feature_endpoint( $feature, $user_id );
		$status   = $response->get_status();

		if ( 403 === $expected_status ) {
			$this->assertSame(
				403,
				$status,
				"Expected tier '{$tier}' to block feature '{$feature}' with 403, got {$status}."
			);
		} else {
			$this->assertGreaterThanOrEqual(
				200,
				$status,
				"Expected tier '{$tier}' to allow feature '{$feature}', got {$status}."
			);
			$this->assertLessThan(
				400,
				$status,
				"Expected tier '{$tier}' to allow feature '{$feature}' with a 2xx, got {$status}."
			);
		}
	}

	/**
	 * Explicitly documents that the messages endpoint has no tier gate.
	 *
	 * The permission_callback for POST /conversations/{id}/messages checks only
	 * edit_posts. This test makes that contract explicit so any future addition of
	 * a tier check immediately surfaces as a named failure rather than a silent
	 * regression buried inside the matrix loop.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_chat_messages_endpoint_has_no_tier_gate_for_free_users(): void {
		$user_id = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => 'no_gate_contract_' . uniqid(),
			]
		);
		$this->set_user_tier( $user_id, 'free' );
		wp_set_current_user( $user_id );

		$this->install_mock_for_feature( 'chat' );

		// Create a conversation — not tier-gated either.
		$create = $this->rest_do( 'POST', '/wp-ai-mind/v1/conversations', [ 'title' => 'No-gate contract test' ] );
		$this->assertSame( 201, $create->get_status(), 'Free users must be able to create conversations.' );

		$conv_data = $create->get_data();
		$this->assertArrayHasKey( 'id', $conv_data, 'Conversation response must include an id.' );

		// Send a message — the messages endpoint must remain accessible to free users.
		$response = $this->rest_do(
			'POST',
			"/wp-ai-mind/v1/conversations/{$conv_data['id']}/messages",
			[ 'content' => 'No-gate contract check' ]
		);
		$status = $response->get_status();

		$this->assertGreaterThanOrEqual(
			200,
			$status,
			'Messages endpoint must be accessible to free users (no tier gate).'
		);
		$this->assertLessThan(
			400,
			$status,
			'Messages endpoint must not return an error for free users.'
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Install an appropriate HTTP mock fixture for the given feature.
	 *
	 * Chat, SEO, and generator use the Claude proxy, so a standard Claude-format
	 * fixture is sufficient. Images use the Gemini Imagen provider, which expects
	 * a base64-encoded PNG in the predictions array.
	 *
	 * @since 1.0.0
	 * @param string $feature Feature slug ('chat', 'seo', 'generator', 'images').
	 * @return void
	 */
	private function install_mock_for_feature( string $feature ): void {
		if ( 'images' === $feature ) {
			// Images route through GeminiProvider, not the Claude proxy — use the
			// Gemini-specific mock so the fixture origin is unambiguous.
			$this->mock_http_with_gemini_fixture( $this->gemini_image_fixture() );
			return;
		}

		// Standard Claude text-completion fixture works for chat, seo, and generator.
		$text = 'seo' === $feature
			? wp_json_encode(
				[
					'meta_title'     => 'Matrix Test Title',
					'og_description' => 'Matrix Test Desc',
					'excerpt'        => 'Matrix excerpt.',
					'alt_text'       => 'Matrix alt.',
				]
			)
			: 'Matrix test AI response.';

		$this->mock_http_with_claude_fixture(
			[
				'content' => [
					[
						'type' => 'text',
						'text' => $text,
					],
				],
				'usage'   => [
					'input_tokens'  => 10,
					'output_tokens' => 5,
				],
				'model'   => 'claude-opus-4-6',
			]
		);
	}

	/**
	 * Build a Gemini Imagen-compatible HTTP fixture containing a minimal 1×1 PNG.
	 *
	 * The GeminiProvider::generate_image() method reads
	 * $raw['predictions'][0]['bytesBase64Encoded'] and writes the decoded bytes
	 * to a temp file before calling media_handle_sideload().
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Fixture body compatible with GeminiProvider::generate_image().
	 */
	private function gemini_image_fixture(): array {
		// Minimal valid 1×1 PNG — sufficient for media_handle_sideload to accept.
		$tiny_png_b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4DwAAAQEABRjYTgAAAABJRU5ErkJggg==';

		return [
			'predictions' => [
				[
					'bytesBase64Encoded' => $tiny_png_b64,
					'mimeType'           => 'image/png',
				],
			],
		];
	}

	/**
	 * Dispatch the appropriate REST request for a feature and return the response.
	 *
	 * For chat, first creates a conversation and then posts a message (the message
	 * endpoint is where the tier gate would apply in a full system, but the
	 * conversation endpoint itself is gated only on edit_posts, not tier — so we
	 * test conversation creation as the representative chat gate).
	 *
	 * @since 1.0.0
	 * @param string $feature Feature slug under test.
	 * @param int    $user_id User ID (used to create owned resources like posts).
	 * @return \WP_REST_Response
	 */
	private function call_feature_endpoint( string $feature, int $user_id ): \WP_REST_Response {
		switch ( $feature ) {
			case 'chat':
				return $this->call_chat_endpoint( $user_id );

			case 'seo':
				$post_id = self::factory()->post->create(
					[
						'post_status' => 'publish',
						'post_author' => $user_id,
					]
				);
				return $this->rest_do( 'POST', '/wp-ai-mind/v1/seo/generate', [ 'post_id' => $post_id ] );

			case 'generator':
				return $this->rest_do( 'POST', '/wp-ai-mind/v1/generate', [ 'title' => 'Matrix Test Post' ] );

			case 'images':
				return $this->rest_do( 'POST', '/wp-ai-mind/v1/images/generate', [ 'prompt' => 'A test image', 'count' => 1 ] );

			default:
				$this->fail( "Unknown feature: {$feature}" );
		}
	}

	/**
	 * Drive the chat feature gate test by creating a conversation.
	 *
	 * The chat permission_callback checks only edit_posts (no tier gate), so a
	 * conversation creation reaching the handler means the permission gate passed.
	 * For free-tier users this returns 201 (allowed); the matrix row for
	 * free/chat sets expected_status=0 (allowed), consistent with the tier config.
	 *
	 * When a mock is installed, also send a message to exercise the full AI turn.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID making the request.
	 * @return \WP_REST_Response
	 */
	private function call_chat_endpoint( int $user_id ): \WP_REST_Response {
		$create = $this->rest_do( 'POST', '/wp-ai-mind/v1/conversations', [ 'title' => 'Matrix Chat Test' ] );

		// When the creation succeeded, also exercise the message turn so the
		// full provider path is covered (including availability check).
		if ( 201 === $create->get_status() ) {
			$conv_data = $create->get_data();
			if ( isset( $conv_data['id'] ) ) {
				return $this->rest_do(
					'POST',
					"/wp-ai-mind/v1/conversations/{$conv_data['id']}/messages",
					[ 'content' => 'Hello matrix' ]
				);
			}
		}

		return $create;
	}
}

<?php
/**
 * Integration-layer tier × feature matrix tests.
 *
 * Makes real REST requests and asserts on HTTP status codes to verify that
 * every tier × feature combination is now uniformly allowed — the
 * permission_callback no longer checks tier or quota; credit enforcement
 * happens entirely on the Worker side.
 *
 * @package Plume\Tests\Integration
 */

declare( strict_types=1 );

namespace Plume\Tests\Integration;

/**
 * Parametrised integration tests covering all tier × feature combinations.
 *
 * Every tier (free, pro_managed, pro_byok) is permitted to use every
 * feature (chat, seo, generator, images) — the only remaining gate is
 * the edit_posts capability, covered separately.
 *
 * @since 1.0.0
 */
class TierFeatureMatrixTest extends IntegrationTestCase {

	// ── Data provider ─────────────────────────────────────────────────────────

	/**
	 * Return all tier × feature rows to drive the matrix test.
	 *
	 * Each row: [ tier, feature ]. Every combination must be allowed (2xx) —
	 * there is no longer a blocked combination, so unlike the pre-redesign
	 * version of this test, expected_status is not a parameter.
	 *
	 * @since 1.0.0
	 * @return array<string, array{string, string}>
	 */
	public static function tier_feature_matrix(): array {
		return [
			'free/chat'             => [ 'free', 'chat' ],
			'free/seo'              => [ 'free', 'seo' ],
			'free/generator'        => [ 'free', 'generator' ],
			'free/images'           => [ 'free', 'images' ],

			'pro_managed/chat'      => [ 'pro_managed', 'chat' ],
			'pro_managed/seo'       => [ 'pro_managed', 'seo' ],
			'pro_managed/generator' => [ 'pro_managed', 'generator' ],
			'pro_managed/images'    => [ 'pro_managed', 'images' ],
		];
	}

	/**
	 * Remove the image key the images rows set, so it cannot leak into other tests.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public function tearDown(): void {
		putenv( 'GEMINI_API_KEY' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test fixture.
		parent::tearDown();
	}

	// ── Test ──────────────────────────────────────────────────────────────────

	/**
	 * Without an own image key, every tier gets the same 422 explanation.
	 *
	 * Complements the matrix: the images rows above run with a key, this pins
	 * down what a keyless site sees, and that it is not a tier-dependent 403.
	 *
	 * @since NEXT_VERSION
	 * @dataProvider managed_tiers
	 * @param string $tier Tier slug to assign to the test user.
	 * @return void
	 */
	public function test_images_without_an_own_key_explains_the_missing_key_on_every_tier( string $tier ): void {
		$user_id = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => "no_image_key_{$tier}_" . uniqid(),
			]
		);
		$this->set_user_tier( $user_id, $tier );
		wp_set_current_user( $user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/images/generate', [ 'prompt' => 'A test image', 'count' => 1 ] );
		$data     = $response->get_data();

		$this->assertSame( 422, $response->get_status(), "Tier '{$tier}' without an image key must get 422, not a tier gate." );
		$this->assertSame( 'image_key_missing', $data['code'] );
		$this->assertNotEmpty( $data['message'] );
	}

	/**
	 * Tiers that use the managed service for text and therefore may have no own key.
	 *
	 * @since NEXT_VERSION
	 * @return array<string, array{string}>
	 */
	public static function managed_tiers(): array {
		return [
			'free'        => [ 'free' ],
			'pro_managed' => [ 'pro_managed' ],
		];
	}

	/**
	 * Verify that every tier × feature combination is allowed (2xx, never 403).
	 *
	 * @since 1.0.0
	 * @dataProvider tier_feature_matrix
	 * @param string $tier    Tier slug to assign to the test user.
	 * @param string $feature Feature slug under test ('chat', 'seo', 'generator', 'images').
	 * @return void
	 */
	public function test_tier_feature_gate_allows_every_combination( string $tier, string $feature ): void {
		// Create a fresh editor user per row to avoid state leaking between iterations.
		$user_id = self::factory()->user->create(
			[
				'role'       => 'editor',
				'user_login' => "matrix_{$tier}_{$feature}_" . uniqid(),
			]
		);
		$this->set_user_tier( $user_id, $tier );
		wp_set_current_user( $user_id );

		$this->install_mock_for_feature( $feature );

		$response = $this->call_feature_endpoint( $feature, $user_id );
		$status   = $response->get_status();

		$this->assertNotSame(
			403,
			$status,
			"Expected tier '{$tier}' to be allowed to use feature '{$feature}' (permission_callback no longer tier-gates), got 403."
		);
		$this->assertGreaterThanOrEqual(
			200,
			$status,
			"Expected tier '{$tier}' to successfully reach feature '{$feature}', got {$status}."
		);
		$this->assertLessThan(
			400,
			$status,
			"Expected tier '{$tier}' to reach feature '{$feature}' with a 2xx, got {$status}."
		);
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
		$create = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'No-gate contract test' ] );
		$this->assertSame( 201, $create->get_status(), 'Free users must be able to create conversations.' );

		$conv_data = $create->get_data();
		$this->assertArrayHasKey( 'id', $conv_data, 'Conversation response must include an id.' );

		// Send a message — the messages endpoint must remain accessible to free users.
		$response = $this->rest_do(
			'POST',
			"/plume/v1/conversations/{$conv_data['id']}/messages",
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
			// Images are generated with the site's own key on every tier; without
			// one the route answers 422 'image_key_missing' before any tier or
			// provider logic runs. That is a setup requirement, not a tier gate, so
			// the matrix gives the site a key and tests what it is here to test.
			putenv( 'GEMINI_API_KEY=matrix-test-key' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test fixture.
			return;
		}

		// The proxy normalises responses to a plain string content field; chat, seo,
		// and generator all route through the proxy. ProxyResponse::from_array()
		// adapts the string to Claude wire format before
		// ClaudeProvider::parse_response() is called, so the fixture matches proxy output.
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
				'content' => $text,
				'usage'   => [
					'input_tokens'  => 10,
					'output_tokens' => 5,
				],
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
	 * endpoint is where a tier gate would historically apply, but the conversation
	 * endpoint itself is gated only on edit_posts, not tier — so we test
	 * conversation creation as the representative chat gate).
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
				return $this->rest_do( 'POST', '/plume/v1/seo/generate', [ 'post_id' => $post_id ] );

			case 'generator':
				return $this->rest_do( 'POST', '/plume/v1/generate', [ 'title' => 'Matrix Test Post' ] );

			case 'images':
				return $this->rest_do( 'POST', '/plume/v1/images/generate', [ 'prompt' => 'A test image', 'count' => 1 ] );

			default:
				$this->fail( "Unknown feature: {$feature}" );
		}
	}

	/**
	 * Drive the chat feature gate test by creating a conversation.
	 *
	 * The chat permission_callback checks only edit_posts (no tier gate), so a
	 * conversation creation reaching the handler means the permission gate passed.
	 *
	 * When a mock is installed, also send a message to exercise the full AI turn.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID making the request.
	 * @return \WP_REST_Response
	 */
	private function call_chat_endpoint( int $user_id ): \WP_REST_Response {
		$create = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'Matrix Chat Test' ] );

		// When the creation succeeded, also exercise the message turn so the
		// full provider path is covered (including availability check).
		if ( 201 === $create->get_status() ) {
			$conv_data = $create->get_data();
			if ( isset( $conv_data['id'] ) ) {
				return $this->rest_do(
					'POST',
					"/plume/v1/conversations/{$conv_data['id']}/messages",
					[ 'content' => 'Hello matrix' ]
				);
			}
		}

		return $create;
	}
}

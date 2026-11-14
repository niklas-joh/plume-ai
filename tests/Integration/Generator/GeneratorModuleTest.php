<?php
/**
 * Integration tests for the Generator REST endpoint.
 *
 * Exercises the full REST lifecycle against a real WordPress instance,
 * including permission checks, prompt construction, draft post creation,
 * and response shape. Tier/quota gating was removed from the permission
 * callback in the credits-based redesign — every tier now reaches the
 * handler; only the edit_posts capability is still enforced here.
 *
 * @package Plume\Tests\Integration\Generator
 */

declare( strict_types=1 );

namespace Plume\Tests\Integration\Generator;

use Plume\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for POST /plume/v1/generate.
 *
 * @since 1.0.0
 */
class GeneratorModuleTest extends IntegrationTestCase {

	/**
	 * Verify that a user without edit_posts cannot access the generate endpoint.
	 *
	 * A subscriber has no edit_posts capability, so the permission_callback must
	 * reject the request with 403 before any AI call is made.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_requires_edit_posts_capability(): void {
		wp_set_current_user( self::$subscriber_user_id );

		$response = $this->rest_do( 'POST', '/plume/v1/generate', [ 'title' => 'Test Post' ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verify that an editor on the free tier CAN access the generate endpoint.
	 *
	 * The permission_callback no longer checks tier or quota — credit
	 * enforcement happens entirely on the Worker side. A free-tier editor
	 * with edit_posts must reach the handler, not be blocked with 403.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_allowed_for_free_tier(): void {
		$this->set_user_tier( self::$editor_user_id, 'free' );
		wp_set_current_user( self::$editor_user_id );

		$this->mock_http_with_claude_fixture(
			[
				'content' => 'Generated body text.',
				'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 10 ],
			]
		);

		$response = $this->rest_do( 'POST', '/plume/v1/generate', [ 'title' => 'Test Post' ] );

		$this->assertNotSame( 403, $response->get_status(), 'Free-tier editors must not be blocked by the permission_callback.' );
	}

	/**
	 * Verify that the generate endpoint returns 201 with the expected response shape
	 * and creates a draft post in the database.
	 *
	 * A free-tier editor submits a title, keywords, tone, and length. The outbound
	 * HTTP call to the proxy is mocked. The test asserts the 201 response includes
	 * post_id, edit_url, content, and credits_used, and that the draft post exists.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_returns_201_with_draft_post(): void {
		$this->set_user_tier( self::$editor_user_id, 'free' );
		wp_set_current_user( self::$editor_user_id );

		$prompt_tokens     = 120;
		$completion_tokens = 80;

		$this->mock_http_with_claude_fixture(
			[
				'content'         => '<h2>Intro</h2><p>AI generated body content for the test post.</p>',
				'usage'           => [
					'input_tokens'  => $prompt_tokens,
					'output_tokens' => $completion_tokens,
				],
				'credits_charged' => 10,
			]
		);

		$response = $this->rest_do(
			'POST',
			'/plume/v1/generate',
			[
				'title'    => 'My Integration Test Post',
				'keywords' => 'integration, testing',
				'tone'     => 'professional',
				'length'   => 'medium',
			]
		);

		$this->assertSame( 201, $response->get_status(), 'Generator must return 201 for a free-tier editor.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'post_id', $data, 'Response must include post_id.' );
		$this->assertArrayHasKey( 'edit_url', $data, 'Response must include edit_url.' );
		$this->assertArrayHasKey( 'content', $data, 'Response must include content.' );
		$this->assertArrayHasKey( 'credits_used', $data, 'Response must include credits_used.' );

		$this->assertIsInt( $data['post_id'], 'post_id must be an integer.' );
		$this->assertGreaterThan( 0, $data['post_id'], 'post_id must be a positive integer.' );
		$this->assertSame( 10, $data['credits_used'], 'credits_used must reflect credits_charged from the Worker fixture.' );

		// Verify the draft post was actually created in the database.
		$post = get_post( $data['post_id'] );
		$this->assertNotNull( $post, 'A draft post must exist in the database.' );
		$this->assertSame( 'draft', $post->post_status, 'Created post must be a draft.' );
		$this->assertSame( 'My Integration Test Post', $post->post_title, 'Post title must match the request title.' );
	}

	/**
	 * Verify that the generate endpoint injects the title and keywords into the
	 * AI provider request body.
	 *
	 * A free-tier editor submits a known title and keywords string. The test
	 * asserts both strings appear in the captured outbound HTTP request body.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_injects_title_and_keywords_into_ai_request(): void {
		$this->set_user_tier( self::$editor_user_id, 'free' );
		wp_set_current_user( self::$editor_user_id );

		$this->mock_http_with_claude_fixture(
			[
				'content' => 'Generated body text.',
				'usage'   => [ 'input_tokens' => 50, 'output_tokens' => 30 ],
			]
		);

		$this->rest_do(
			'POST',
			'/plume/v1/generate',
			[
				'title'    => 'Distinctive Generator Title',
				'keywords' => 'distinctive-keyword-xyz',
				'tone'     => 'casual',
				'length'   => 'short',
			]
		);

		$this->assertNotNull( $this->last_http_args, 'HTTP mock was never triggered — check provider routing.' );

		$body = $this->last_http_args['body'];
		$this->assertStringContainsString(
			'Distinctive Generator Title',
			$body,
			'Post title must appear in the AI provider request body.'
		);
		$this->assertStringContainsString(
			'distinctive-keyword-xyz',
			$body,
			'Keywords must appear in the AI provider request body.'
		);
	}

	/**
	 * Verify that a proxy-level error returns 502 with the provider's message.
	 *
	 * An HTTP filter returns a proxy 401 (stale/missing site token). This causes
	 * ProxyClient to return WP_Error → ProviderException → the generator
	 * handler catches it and returns 502 'provider_error'. Importantly, this
	 * validates that the handler does NOT crash PHP (i.e. the catch block works
	 * correctly) and that the body carries `message` and `code` next to the
	 * legacy `error` key, which is what the wizard reads.
	 *
	 * @since 1.0.0
	 * @since NEXT_VERSION Provider failures are 502 'provider_error' (500 is kept
	 *                      for unexpected errors).
	 * @return void
	 */
	public function test_generate_returns_502_on_provider_failure(): void {
		$this->set_user_tier( self::$editor_user_id, 'free' );
		wp_set_current_user( self::$editor_user_id );

		// A 401 from the proxy clears the site token and throws ProviderException.
		// Priority 5 ensures this fires before the IntegrationTestCase default mock.
		$error_mock = static function () {
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => wp_json_encode( [ 'message' => 'Unauthorised' ] ),
				'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
				'cookies'  => [],
			];
		};
		add_filter( 'pre_http_request', $error_mock, 5, 3 );

		$response = $this->rest_do(
			'POST',
			'/plume/v1/generate',
			[ 'title' => 'Test Post For Error Path' ]
		);

		remove_filter( 'pre_http_request', $error_mock, 5 );

		$this->assertSame( 502, $response->get_status(), 'Generator must return 502 when the provider fails.' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data, 'Error response must keep the legacy error key.' );
		$this->assertSame( 'provider_error', $data['code'], 'Error response must carry a machine-readable code.' );
		$this->assertSame( $data['error'], $data['message'], 'Error response must carry the text under message too.' );
	}
}

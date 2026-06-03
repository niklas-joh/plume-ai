<?php
/**
 * Integration tests for the Generator REST endpoint.
 *
 * Exercises the full REST lifecycle against a real WordPress instance,
 * including permission checks, tier gating, prompt construction, draft
 * post creation, and response shape.
 *
 * @package Stilus\Tests\Integration\Generator
 */

declare( strict_types=1 );

namespace Stilus\Tests\Integration\Generator;

use Stilus\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for POST /stilus/v1/generate.
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

		$response = $this->rest_do( 'POST', '/stilus/v1/generate', [ 'title' => 'Test Post' ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verify that an editor on the free tier cannot access the generate endpoint.
	 *
	 * The free tier has generator=false in TierConfig::FEATURES, so the
	 * permission_callback must reject with 403 even though the user has edit_posts.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_blocked_for_free_tier(): void {
		$this->set_user_tier( self::$editor_user_id, 'free' );
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do( 'POST', '/stilus/v1/generate', [ 'title' => 'Test Post' ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verify that the generate endpoint returns 201 with the expected response shape
	 * and creates a draft post in the database.
	 *
	 * A trial-tier editor submits a title, keywords, tone, and length. The outbound
	 * HTTP call to the proxy is mocked. The test asserts the 201 response includes
	 * post_id, edit_url, content, and tokens_used, and that the draft post exists.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_returns_201_with_draft_post_for_trial_user(): void {
		$this->set_user_tier( self::$editor_user_id, 'trial' );
		wp_set_current_user( self::$editor_user_id );

		$prompt_tokens     = 120;
		$completion_tokens = 80;

		$this->mock_http_with_claude_fixture(
			[
				'content' => '<h2>Intro</h2><p>AI generated body content for the test post.</p>',
				'usage'   => [
					'input_tokens'  => $prompt_tokens,
					'output_tokens' => $completion_tokens,
				],
			]
		);

		$response = $this->rest_do(
			'POST',
			'/stilus/v1/generate',
			[
				'title'    => 'My Integration Test Post',
				'keywords' => 'integration, testing',
				'tone'     => 'professional',
				'length'   => 'medium',
			]
		);

		$this->assertSame( 201, $response->get_status(), 'Generator must return 201 for a trial-tier editor.' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'post_id', $data, 'Response must include post_id.' );
		$this->assertArrayHasKey( 'edit_url', $data, 'Response must include edit_url.' );
		$this->assertArrayHasKey( 'content', $data, 'Response must include content.' );
		$this->assertArrayHasKey( 'tokens_used', $data, 'Response must include tokens_used.' );

		$this->assertIsInt( $data['post_id'], 'post_id must be an integer.' );
		$this->assertGreaterThan( 0, $data['post_id'], 'post_id must be a positive integer.' );
		$this->assertSame( $prompt_tokens + $completion_tokens, $data['tokens_used'], 'tokens_used must equal input + output tokens from fixture.' );

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
	 * A trial-tier editor submits a known title and keywords string. The test
	 * asserts both strings appear in the captured outbound HTTP request body.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_injects_title_and_keywords_into_ai_request(): void {
		$this->set_user_tier( self::$editor_user_id, 'trial' );
		wp_set_current_user( self::$editor_user_id );

		$this->mock_http_with_claude_fixture(
			[
				'content' => 'Generated body text.',
				'usage'   => [ 'input_tokens' => 50, 'output_tokens' => 30 ],
			]
		);

		$this->rest_do(
			'POST',
			'/stilus/v1/generate',
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
	 * Verify that a proxy-level error returns 500 with an 'error' key.
	 *
	 * An HTTP filter returns a proxy 401 (stale/missing site token). This causes
	 * ProxyClient to return WP_Error → ProviderException → the generator
	 * handler catches it and returns 500. Importantly, this validates that the
	 * handler does NOT crash PHP (i.e. the catch block works correctly).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_returns_500_on_provider_failure(): void {
		$this->set_user_tier( self::$editor_user_id, 'trial' );
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
			'/stilus/v1/generate',
			[ 'title' => 'Test Post For Error Path' ]
		);

		remove_filter( 'pre_http_request', $error_mock, 5 );

		$this->assertSame( 500, $response->get_status(), 'Generator must return 500 when the provider fails.' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data, 'Error response must include an error key.' );
	}
}

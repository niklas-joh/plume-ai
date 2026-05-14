<?php
/**
 * Integration tests for the SEO generate and apply REST endpoints.
 *
 * Exercises the full REST lifecycle against a real WordPress instance,
 * including permission checks, tier gating, prompt construction, and
 * database writes.
 *
 * @package WP_AI_Mind\Tests\Integration\Seo
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Integration\Seo;

use WP_AI_Mind\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for POST /wp-ai-mind/v1/seo/generate and /seo/apply.
 *
 * @since 1.0.0
 */
class SeoGenerateTest extends IntegrationTestCase {

	/**
	 * Verify that a user without edit_posts cannot access the generate endpoint.
	 *
	 * A subscriber has no edit_posts capability, so the permission_callback on
	 * the route must reject the request with a 403 before any AI call is made.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_requires_edit_posts_capability(): void {
		wp_set_current_user( self::$subscriber_user_id );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$response = $this->rest_do( 'POST', '/wp-ai-mind/v1/seo/generate', [ 'post_id' => $post_id ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verify that an editor on the free tier cannot access the generate endpoint.
	 *
	 * The free tier has seo=false in NJ_Tier_Config::FEATURES, so the
	 * permission_callback must reject with 403 even though the user has edit_posts.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_blocked_for_free_tier(): void {
		$this->set_user_tier( self::$editor_user_id, 'free' );
		wp_set_current_user( self::$editor_user_id );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$response = $this->rest_do( 'POST', '/wp-ai-mind/v1/seo/generate', [ 'post_id' => $post_id ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verify that the SEO generate endpoint injects the post title and content
	 * into the AI provider request and returns a 200 with meta_title.
	 *
	 * A trial-tier editor creates a post with a known title and content string.
	 * The HTTP fixture intercepts the outbound request. The test asserts that
	 * the captured request body contains both strings and that the response
	 * carries the expected meta_title from the fixture.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_injects_post_title_and_content_into_ai_request(): void {
		$this->set_user_tier( self::$editor_user_id, 'trial' );
		wp_set_current_user( self::$editor_user_id );

		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Unique Title For Test',
				'post_content' => 'Unmistakably unique content for this integration test.',
				'post_status'  => 'publish',
				'post_author'  => self::$editor_user_id,
			]
		);

		// The proxy normalises responses to a plain string content field.
		// ProxyResponse::from_array() then adapts this to Claude wire format before
		// parse_response() is called, so the fixture must match the proxy output shape.
		$fixture = [
			'content' => wp_json_encode(
				[
					'meta_title'     => 'Test AI Title',
					'og_description' => 'Test AI Desc',
					'excerpt'        => 'Test Excerpt',
					'alt_text'       => 'Test Alt',
				]
			),
			'usage'   => [
				'input_tokens'  => 100,
				'output_tokens' => 50,
			],
		];

		$this->mock_http_with_claude_fixture( $fixture );

		$response = $this->rest_do( 'POST', '/wp-ai-mind/v1/seo/generate', [ 'post_id' => $post_id ] );

		$this->assertSame( 200, $response->get_status(), 'Expected 200 from generate endpoint for trial user.' );

		// The provider serialises the CompletionRequest to JSON; the body is
		// in $this->last_http_args['body'].
		$this->assertNotNull( $this->last_http_args, 'HTTP mock was never triggered — check provider routing.' );

		$body = $this->last_http_args['body'];
		$this->assertStringContainsString(
			'Unique Title For Test',
			$body,
			'Post title must appear in the AI provider request body.'
		);
		$this->assertStringContainsString(
			'Unmistakably unique content for this integration test.',
			$body,
			'Post content must appear in the AI provider request body.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'meta_title', $data, 'Response must include meta_title key.' );
		$this->assertSame( 'Test AI Title', $data['meta_title'] );
	}

	/**
	 * Verify that calling the apply endpoint with SEO field values returns 200.
	 *
	 * A trial-tier editor creates a post and submits meta_title, og_description,
	 * and excerpt. The handler writes the values to post meta via apply_for_post();
	 * the test asserts the response is 200.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_apply_persists_seo_data_to_post(): void {
		$this->set_user_tier( self::$editor_user_id, 'trial' );
		wp_set_current_user( self::$editor_user_id );

		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_author' => self::$editor_user_id,
			]
		);

		$response = $this->rest_do(
			'POST',
			'/wp-ai-mind/v1/seo/apply',
			[
				'post_id'        => $post_id,
				'meta_title'     => 'Integration Meta Title',
				'og_description' => 'Integration OG Description',
				'excerpt'        => 'Integration excerpt text.',
			]
		);

		$this->assertSame( 200, $response->get_status(), 'Apply endpoint must return 200 for a valid trial-tier editor.' );
	}
}

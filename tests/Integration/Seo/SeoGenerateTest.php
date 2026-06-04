<?php
/**
 * Integration tests for the SEO generate and apply REST endpoints.
 *
 * Exercises the full REST lifecycle against a real WordPress instance,
 * including permission checks, tier gating, prompt construction, and
 * database writes.
 *
 * @package Stilus\Tests\Integration\Seo
 */

declare( strict_types=1 );

namespace Stilus\Tests\Integration\Seo;

use Stilus\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for POST /stilus/v1/seo/generate and /seo/apply.
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

		$response = $this->rest_do( 'POST', '/stilus/v1/seo/generate', [ 'post_id' => $post_id ] );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Verify that an editor on the free tier cannot access the generate endpoint.
	 *
	 * The free tier has seo=false in TierConfig::FEATURES, so the
	 * permission_callback must reject with 403 even though the user has edit_posts.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_generate_blocked_for_free_tier(): void {
		$this->set_user_tier( self::$editor_user_id, 'free' );
		wp_set_current_user( self::$editor_user_id );

		$post_id = self::factory()->post->create( [ 'post_status' => 'publish' ] );

		$response = $this->rest_do( 'POST', '/stilus/v1/seo/generate', [ 'post_id' => $post_id ] );

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

		$response = $this->rest_do( 'POST', '/stilus/v1/seo/generate', [ 'post_id' => $post_id ] );

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
	 * Verify that calling the apply endpoint with SEO field values returns 200
	 * and persists all three fields to the database.
	 *
	 * A trial-tier editor creates a post and submits meta_title, og_description,
	 * and excerpt. The handler writes the values via apply_for_post(): meta_title
	 * and og_description go to both the Yoast and Rank Math meta keys; excerpt is
	 * written to post_excerpt via wp_update_post(). All three writes are verified
	 * after the HTTP 200 check to catch silent persistence failures.
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
			'/stilus/v1/seo/apply',
			[
				'post_id'        => $post_id,
				'meta_title'     => 'Integration Meta Title',
				'og_description' => 'Integration OG Description',
				'excerpt'        => 'Integration excerpt text.',
			]
		);

		$this->assertSame( 200, $response->get_status(), 'Apply endpoint must return 200 for a valid trial-tier editor.' );

		// Dual-write means both meta keys must be set; assert each individually.
		$this->assertSame(
			'Integration Meta Title',
			get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'meta_title must be persisted to the Yoast meta key'
		);
		$this->assertSame(
			'Integration Meta Title',
			get_post_meta( $post_id, 'rank_math_title', true ),
			'meta_title must be persisted to the Rank Math meta key'
		);

		$this->assertSame(
			'Integration OG Description',
			get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'og_description must be persisted to the Yoast meta key'
		);
		$this->assertSame(
			'Integration OG Description',
			get_post_meta( $post_id, 'rank_math_description', true ),
			'og_description must be persisted to the Rank Math meta key'
		);

		// excerpt is stored in the post row via wp_update_post(), not in post meta.
		$this->assertSame(
			'Integration excerpt text.',
			get_post_field( 'post_excerpt', $post_id ),
			'excerpt must be persisted to post_excerpt'
		);
	}

	/**
	 * Verify that apply_for_post() writes alt_text to the featured image attachment meta.
	 *
	 * The fourth write path in apply_for_post() calls update_post_meta() on the thumbnail
	 * only when a featured image is set and alt_text is non-empty. A featured image is
	 * attached here so the branch is exercised and a regression in it would fail this test.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function test_apply_persists_alt_text_to_featured_image(): void {
		$this->set_user_tier( self::$editor_user_id, 'trial' );
		wp_set_current_user( self::$editor_user_id );

		$post_id = self::factory()->post->create(
			[
				'post_status' => 'publish',
				'post_author' => self::$editor_user_id,
			]
		);

		$thumb_id = self::factory()->attachment->create(
			[
				'post_parent'    => $post_id,
				'post_mime_type' => 'image/jpeg',
			]
		);
		set_post_thumbnail( $post_id, $thumb_id );

		$response = $this->rest_do(
			'POST',
			'/stilus/v1/seo/apply',
			[
				'post_id'  => $post_id,
				'alt_text' => 'Integration Alt Text',
			]
		);

		$this->assertSame( 200, $response->get_status(), 'Apply endpoint must return 200 for a valid trial-tier editor' );
		$this->assertSame(
			'Integration Alt Text',
			get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
			'alt_text must be written to _wp_attachment_image_alt on the featured image'
		);
	}
}

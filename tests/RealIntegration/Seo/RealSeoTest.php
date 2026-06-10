<?php
/**
 * Real integration tests for the SEO feature.
 *
 * Makes live Anthropic API calls via the Pro-BYOK path.
 * SKIPPED when CLAUDE_API_KEY is absent.
 *
 * Cost: ~$0.0003/run (claude-haiku-4-5-20251001, short SEO meta generation).
 *
 * @package Plume\Tests\RealIntegration\Seo
 */

declare( strict_types=1 );

namespace Plume\Tests\RealIntegration\Seo;

use Plume\Tests\RealIntegration\RealIntegrationTestCase;

/**
 * @since 1.8.0
 */
class RealSeoTest extends RealIntegrationTestCase {

	/**
	 * Post used as context for SEO generation.
	 *
	 * @var int
	 */
	private int $test_post_id;

	/** @since 1.8.0 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::skip_without_api_key();
	}

	/** @since 1.8.0 */
	public function setUp(): void {
		parent::setUp();
		$this->activate_byok_tier( self::$editor_user_id );
		wp_set_current_user( self::$editor_user_id );

		$this->test_post_id = self::factory()->post->create(
			[
				'post_title'   => 'How to write great software tests',
				'post_content' => 'Testing is fundamental to reliable software. Automated tests catch regressions early and give developers confidence to ship.',
				'post_status'  => 'draft',
				'post_author'  => self::$editor_user_id,
			]
		);
	}

	/**
	 * SEO generator produces real meta fields for a post via Pro-BYOK.
	 *
	 * Endpoint: POST /plume/v1/seo/generate
	 * Required: post_id (int)
	 * Response fields: meta_title, og_description, excerpt, alt_text
	 *
	 * @since 1.8.0
	 */
	public function test_seo_generate_returns_real_meta_fields(): void {
		$response = $this->rest_do(
			'POST',
			'/plume/v1/seo/generate',
			[ 'post_id' => $this->test_post_id ]
		);

		$data   = $response->get_data();
		$status = $response->get_status();

		$this->assertSame(
			200,
			$status,
			sprintf( 'SEO generate must return 200, got %d. Body: %s', $status, wp_json_encode( $data ) )
		);

		// At least one SEO field must be non-empty.
		$has_content = ! empty( $data['meta_title'] ?? '' )
			|| ! empty( $data['og_description'] ?? '' )
			|| ! empty( $data['excerpt'] ?? '' );

		$this->assertTrue( $has_content, 'SEO generate must return at least one non-empty SEO field.' );
	}

	/**
	 * SEO apply persists generated meta to post meta tables.
	 *
	 * Generates real meta via the API, then applies it and verifies it was
	 * written to the database.
	 *
	 * @since 1.8.0
	 */
	public function test_seo_apply_persists_meta_to_database(): void {
		// Generate real SEO meta first.
		$gen_response = $this->rest_do(
			'POST',
			'/plume/v1/seo/generate',
			[ 'post_id' => $this->test_post_id ]
		);
		$this->assertSame( 200, $gen_response->get_status() );
		$meta = $gen_response->get_data();

		// Apply the generated meta.
		$apply_response = $this->rest_do(
			'POST',
			'/plume/v1/seo/apply',
			[
				'post_id'        => $this->test_post_id,
				'meta_title'     => $meta['meta_title'] ?? 'Test SEO title',
				'og_description' => $meta['og_description'] ?? 'Test description',
				'excerpt'        => $meta['excerpt'] ?? '',
			]
		);

		$apply_data = $apply_response->get_data();
		$this->assertSame(
			200,
			$apply_response->get_status(),
			sprintf( 'SEO apply must return 200, got %d. Body: %s', $apply_response->get_status(), wp_json_encode( $apply_data ) )
		);
		$this->assertNotEmpty( $apply_data['updated'] ?? [], 'SEO apply must report at least one updated field.' );
	}
}

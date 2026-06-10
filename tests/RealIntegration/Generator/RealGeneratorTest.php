<?php
/**
 * Real integration tests for the content generator feature.
 *
 * Makes live Anthropic API calls via the Pro-BYOK path.
 * SKIPPED when CLAUDE_API_KEY is absent.
 *
 * Cost: ~$0.0005/run (claude-haiku-4-5-20251001, short post generation).
 *
 * @package Plume\Tests\RealIntegration\Generator
 */

declare( strict_types=1 );

namespace Plume\Tests\RealIntegration\Generator;

use Plume\Tests\RealIntegration\RealIntegrationTestCase;

/**
 * @since 1.8.0
 */
class RealGeneratorTest extends RealIntegrationTestCase {

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
	 * Generator produces real content and persists a draft post via Pro-BYOK.
	 *
	 * Endpoint: POST /plume/v1/generate
	 * Required: title (string)
	 * Optional: keywords, tone, length (short|medium|long)
	 *
	 * @since 1.8.0
	 */
	public function test_generate_creates_draft_post_with_real_content(): void {
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do(
			'POST',
			'/plume/v1/generate',
			[
				'title'    => 'Why automated tests matter',
				'tone'     => 'professional',
				'length'   => 'short',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$data   = $response->get_data();
		$status = $response->get_status();

		$this->assertSame(
			201,
			$status,
			sprintf( 'Generator must return 201, got %d. Body: %s', $status, wp_json_encode( $data ) )
		);
		$this->assertNotEmpty( $data['post_id'] ?? 0, 'Generator must return a post_id.' );
		$this->assertNotEmpty( $data['content'] ?? '', 'Generator must return non-empty content.' );
		$this->assertGreaterThan( 0, (int) ( $data['tokens_used'] ?? 0 ), 'Generator must return tokens_used > 0.' );

		// Verify the draft post actually exists in the database.
		$post = get_post( (int) $data['post_id'] );
		$this->assertNotNull( $post, 'Generated post must exist in the database.' );
		$this->assertSame( 'draft', $post->post_status, 'Generated post must be a draft.' );
		$this->assertNotEmpty( $post->post_content, 'Generated post must have non-empty content.' );
	}
}

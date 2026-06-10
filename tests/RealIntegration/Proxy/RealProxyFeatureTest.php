<?php
/**
 * Real integration tests for the proxy (Trial/Pro-Managed) path.
 *
 * Routes AI calls through the real Cloudflare Worker. Tests chat, generator,
 * and SEO features to verify the full proxy pipeline works for all features
 * that require the proxy tier, not just chat.
 *
 * SKIPPED when PLUME_CI_SITE_TOKEN is absent.
 * Cost: ~$0.001/run (claude-haiku-4-5-20251001, three short AI turns).
 *
 * @package Plume\Tests\RealIntegration\Proxy
 */

declare( strict_types=1 );

namespace Plume\Tests\RealIntegration\Proxy;

use Plume\Tests\RealIntegration\RealIntegrationTestCase;
use Plume\Proxy\SiteRegistration;

/**
 * @since 1.8.0
 */
class RealProxyFeatureTest extends RealIntegrationTestCase {

	/** @since 1.8.0 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::skip_without_proxy_token();
	}

	/** @since 1.8.0 */
	public function setUp(): void {
		parent::setUp();

		// Inject the real CI site token so ProxyClient sends valid Bearer auth.
		update_option( SiteRegistration::OPTION_TOKEN, getenv( 'PLUME_CI_SITE_TOKEN' ) ?: '' );

		$proxy_url = getenv( 'PLUME_PROXY_URL' );
		if ( false !== $proxy_url && '' !== $proxy_url ) {
			update_option( 'plume_proxy_url', rtrim( $proxy_url, '/' ) );
		}

		// Use trial tier — all features enabled, routes via proxy.
		$this->activate_trial_tier( self::$editor_user_id );
	}

	/**
	 * Chat via proxy (trial tier) returns real AI response.
	 *
	 * @since 1.8.0
	 */
	public function test_proxy_chat_returns_real_response(): void {
		wp_set_current_user( self::$editor_user_id );

		$create  = $this->rest_do( 'POST', '/plume/v1/conversations', [ 'title' => 'Proxy Chat Test' ] );
		$conv_id = $create->get_data()['id'];

		$response = $this->rest_do(
			'POST',
			"/plume/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'Reply with only the word "pong".',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$data = $response->get_data();
		$this->assertSame(
			200,
			$response->get_status(),
			sprintf( 'Expected 200 from proxy, got %d. Body: %s', $response->get_status(), wp_json_encode( $data ) )
		);
		$this->assertNotEmpty( $data['content'] ?? '' );
	}

	/**
	 * Generator via proxy (trial tier) creates a real draft post.
	 *
	 * @since 1.8.0
	 */
	public function test_proxy_generator_creates_real_draft_post(): void {
		wp_set_current_user( self::$editor_user_id );

		$response = $this->rest_do(
			'POST',
			'/plume/v1/generate',
			[
				'title'  => 'Automated testing benefits',
				'tone'   => 'professional',
				'length' => 'short',
			]
		);

		$this->assertNotSame( 403, $response->get_status(), 'Proxy trial tier must not block generator.' );
		$this->assertSame(
			201,
			$response->get_status(),
			sprintf( 'Generator must return 201 via proxy, got %d.', $response->get_status() )
		);
		$this->assertNotEmpty( $response->get_data()['post_id'] ?? 0, 'Generator must return a post_id.' );
	}

	/**
	 * SEO generator via proxy (trial tier) returns real meta content.
	 *
	 * @since 1.8.0
	 */
	public function test_proxy_seo_returns_real_meta(): void {
		wp_set_current_user( self::$editor_user_id );

		$post_id = self::factory()->post->create(
			[
				'post_title'   => 'Proxy SEO test post',
				'post_content' => 'Content for SEO meta generation via the Plume proxy.',
				'post_status'  => 'draft',
				'post_author'  => self::$editor_user_id,
			]
		);

		$response = $this->rest_do(
			'POST',
			'/plume/v1/seo/generate',
			[ 'post_id' => $post_id ]
		);

		$this->assertNotSame( 403, $response->get_status(), 'Proxy trial tier must not block SEO.' );
		$this->assertSame(
			200,
			$response->get_status(),
			sprintf( 'SEO must return 200 via proxy, got %d.', $response->get_status() )
		);

		$data        = $response->get_data();
		$has_content = ! empty( $data['meta_title'] ?? '' )
			|| ! empty( $data['og_description'] ?? '' )
			|| ! empty( $data['excerpt'] ?? '' );
		$this->assertTrue( $has_content, 'Proxy SEO generate must return at least one non-empty SEO field.' );
	}
}

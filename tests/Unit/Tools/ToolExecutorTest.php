<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Tools\ToolExecutor;
use Plume\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Minimal WP_Query stub for the test environment.
 * The real class is not available outside a WordPress bootstrap.
 */
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = [];
		public function __construct( array $args ) {}
	}
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

class ToolExecutorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_executor( array $allowed_post_types = [ 'post', 'page' ] ): ToolExecutor {
		$registry = $this->createMock( ToolRegistry::class );
		$registry->method( 'allowed_post_types' )->willReturn( $allowed_post_types );
		return new ToolExecutor( $registry );
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	public function test_execute_unknown_tool_returns_error(): void {
		$executor = $this->make_executor();
		$result   = $executor->execute( 'nonexistent_tool', [], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Unknown tool', $result['error'] );
		$this->assertStringContainsString( 'nonexistent_tool', $result['error'] );
	}

	// -------------------------------------------------------------------------
	// get_recent_posts
	// -------------------------------------------------------------------------

	public function test_get_recent_posts_requires_edit_posts_cap(): void {
		Functions\when( 'user_can' )->justReturn( false );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'get_recent_posts', [], 99 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'permissions', strtolower( $result['error'] ) );
	}

	public function test_get_recent_posts_rejects_disallowed_post_type(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );

		// Registry allows only 'post', but we pass 'product'.
		$executor = $this->make_executor( [ 'post' ] );
		$result   = $executor->execute( 'get_recent_posts', [ 'post_type' => 'product' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not permitted', $result['error'] );
	}

	// -------------------------------------------------------------------------
	// get_post_content
	// -------------------------------------------------------------------------

	public function test_get_post_content_blocks_private_post_for_other_user(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );

		// Build a minimal post object mimicking WP_Post.
		$post               = new \stdClass();
		$post->ID           = 42;
		$post->post_title   = 'Secret Draft';
		$post->post_content = 'Private content.';
		$post->post_excerpt = '';
		$post->post_status  = 'draft';
		$post->post_author  = '10'; // Author is user 10.
		$post->post_date    = '2026-01-01 12:00:00';

		Functions\when( 'get_post' )->justReturn( $post );

		// user_can: return false for any capability check (not author, not editor).
		Functions\when( 'user_can' )->justReturn( false );

		$executor = $this->make_executor();
		// User 99 is neither the author (10) nor has edit_others_posts.
		$result = $executor->execute( 'get_post_content', [ 'post_id' => 42 ], 99 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'authorised', strtolower( $result['error'] ) );
	}

	// -------------------------------------------------------------------------
	// generate_seo_meta
	// -------------------------------------------------------------------------

	public function test_generate_seo_meta_returns_error_for_invalid_post_id(): void {
		Functions\when( 'absint' )->justReturn( 0 );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'generate_seo_meta', [ 'post_id' => 0 ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'post_id', $result['error'] );
	}

	public function test_generate_seo_meta_returns_error_when_post_not_found(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'generate_seo_meta', [ 'post_id' => 5 ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not found', strtolower( $result['error'] ) );
	}

	public function test_generate_seo_meta_returns_error_without_edit_post_cap(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );

		$post               = new \stdClass();
		$post->ID           = 5;
		$post->post_title   = 'Test Post';
		$post->post_content = 'Content';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'generate_seo_meta', [ 'post_id' => 5 ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'permissions', strtolower( $result['error'] ) );
	}

	/**
	 * Free-tier users are no longer short-circuited to a manual-suggestion
	 * snippet — every tier reaches SeoModule::generate_for_post(); the Worker's
	 * credit ledger is the only enforcement point now. ToolExecutor has no DI
	 * seam for SeoModule, so generate_for_post() runs for real and fails on its
	 * own unmocked dependencies (e.g. ProviderFactory) — the test asserts the
	 * tier-gate's distinguishing shape (`seo_access`/manual-suggestion note) is
	 * gone, not that the call fully succeeds end-to-end.
	 */
	public function test_generate_seo_meta_reaches_generator_for_free_tier(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );

		$post               = new \stdClass();
		$post->ID           = 5;
		$post->post_title   = 'Great Post';
		$post->post_content = 'The post body content here.';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'user_can' )->justReturn( true );
		// Free tier (site option default) — must no longer short-circuit.
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => $s );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'generate_seo_meta', [ 'post_id' => 5 ], 1 );

		$this->assertArrayNotHasKey( 'seo_access', $result, 'The tier gate must be gone — every tier reaches the generator.' );
		if ( isset( $result['error'] ) ) {
			$this->assertStringNotContainsString( 'Pro plan', $result['error'] );
		}
	}

	/**
	 * A simulated zero-quota free-tier user must still reach
	 * SeoModule::generate_for_post() — the local WordPress-side quota
	 * pre-check was deleted; the Worker's KV ledger is the only enforcer.
	 */
	public function test_generate_seo_meta_reaches_generator_when_local_usage_meta_is_exhausted(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );

		$post               = new \stdClass();
		$post->ID           = 5;
		$post->post_title   = 'Great Post';
		$post->post_content = 'The post body content here.';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => $s );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$month_key = 'plume_usage_' . gmdate( 'Y_m' );
		// pro_managed is site-level now; usage meta still per-user. Usage is set
		// far above any historical limit to simulate "exhausted" — must no longer matter.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_managed' : $default
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key, bool $single ) use ( $month_key ): mixed {
				if ( $month_key === $key ) {
					return '3000000';
				}
				return '';
			}
		);

		$executor = $this->make_executor();
		$result   = $executor->execute( 'generate_seo_meta', [ 'post_id' => 5 ], 1 );

		if ( isset( $result['error'] ) ) {
			$this->assertStringNotContainsString( 'limit', strtolower( $result['error'] ), 'The local quota pre-check must be gone — only the Worker enforces credits now.' );
		}
	}

	// -------------------------------------------------------------------------
	// plan_update
	// -------------------------------------------------------------------------

	public function test_plan_update_returns_error_when_changes_missing(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => $v );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'plan_update', [ 'post_id' => 5, 'new_content' => 'Content' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'changes', strtolower( $result['error'] ) );
	}

	public function test_plan_update_returns_error_when_new_content_missing(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'plan_update', [ 'post_id' => 5, 'changes' => 'Made changes' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'new_content', strtolower( $result['error'] ) );
	}

	public function test_plan_update_stores_plan_with_new_content_and_new_title(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'abcd1234-5678-90ab-cdef-000000000000' );
		Functions\when( 'set_transient' )->justReturn( true );

		$executor = $this->make_executor();
		$result   = $executor->execute(
			'plan_update',
			[
				'post_id'     => 5,
				'changes'     => 'Made the intro punchier',
				'new_content' => 'Full updated post body',
				'new_title'   => 'Improved Title',
			],
			1
		);

		$this->assertArrayHasKey( 'new_content', $result );
		$this->assertSame( 'Full updated post body', $result['new_content'] );
		$this->assertArrayHasKey( 'new_title', $result );
		$this->assertSame( 'Improved Title', $result['new_title'] );
		$this->assertSame( 'pending_approval', $result['status'] );
	}

	public function test_plan_update_ignores_analysis_field_in_stored_plan(): void {
		// `analysis` is conversational-only — it must never be written into the
		// persisted plan transient consumed by PlansRestController/PlanCard.
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'abcd1234-5678-90ab-cdef-000000000000' );
		Functions\when( 'set_transient' )->justReturn( true );

		$executor = $this->make_executor();
		$result   = $executor->execute(
			'plan_update',
			[
				'post_id'     => 5,
				'analysis'    => 'This intro is weak and the CTA is buried; tightening both.',
				'changes'     => 'Made the intro punchier',
				'new_content' => 'Full updated post body',
			],
			1
		);

		$this->assertArrayNotHasKey( 'analysis', $result );
		$this->assertSame( 'pending_approval', $result['status'] );
	}

	public function test_plan_post_ignores_analysis_field_in_stored_plan(): void {
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_textarea_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'abcd1234-5678-90ab-cdef-000000000000' );
		Functions\when( 'set_transient' )->justReturn( true );

		$executor = $this->make_executor();
		$result   = $executor->execute(
			'plan_post',
			[
				'title'    => 'Widgets',
				'analysis' => 'The user asked for a launch post; drafting an announcement.',
				'content'  => 'Full body.',
			],
			1
		);

		$this->assertArrayNotHasKey( 'analysis', $result );
		$this->assertSame( 'pending_approval', $result['status'] );
	}
}

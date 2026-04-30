<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Tools\ToolExecutor;
use WP_AI_Mind\Tools\ToolRegistry;
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

class ToolExecutorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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
	// create_post
	// -------------------------------------------------------------------------

	public function test_create_post_blocked_when_write_tools_disabled(): void {
		Functions\when( 'get_option' )
			->alias( static function ( string $key, $default = false ) {
				if ( 'wp_ai_mind_enable_write_tools' === $key ) {
					return false;
				}
				return $default;
			} );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'create_post', [ 'title' => 'Test' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'disabled', strtolower( $result['error'] ) );
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

	public function test_generate_seo_meta_returns_post_snippet_for_free_tier(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );

		$post               = new \stdClass();
		$post->ID           = 5;
		$post->post_title   = 'Great Post';
		$post->post_content = 'The post body content here.';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'user_can' )->justReturn( true );
		// Return 'free' tier → NJ_Tier_Manager::user_can('seo') = false.
		Functions\when( 'get_user_meta' )->justReturn( 'free' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => $s );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'generate_seo_meta', [ 'post_id' => 5 ], 1 );

		$this->assertArrayHasKey( 'seo_access', $result );
		$this->assertFalse( $result['seo_access'] );
		$this->assertArrayHasKey( 'post_content_snippet', $result );
	}

	public function test_generate_seo_meta_returns_error_when_usage_limit_exceeded(): void {
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );

		$post               = new \stdClass();
		$post->ID           = 5;
		$post->post_title   = 'Great Post';
		$post->post_content = 'The post body content here.';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );
		// Return 'pro_managed' for the tier key and a huge usage count for the month key.
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key, bool $single ) use ( $month_key ): mixed {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'pro_managed';
				}
				if ( $month_key === $key ) {
					return '3000000'; // Exceeds the 2 000 000 pro_managed limit.
				}
				return '';
			}
		);

		$executor = $this->make_executor();
		$result   = $executor->execute( 'generate_seo_meta', [ 'post_id' => 5 ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'limit', strtolower( $result['error'] ) );
	}

	// -------------------------------------------------------------------------
	// update_post
	// -------------------------------------------------------------------------

	public function test_update_post_blocked_without_edit_post_cap(): void {
		Functions\when( 'get_option' )
			->alias( static function ( string $key, $default = false ) {
				if ( 'wp_ai_mind_enable_write_tools' === $key ) {
					return true;
				}
				return $default;
			} );

		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );

		// user_can returns false for edit_post check.
		Functions\when( 'user_can' )->justReturn( false );

		$executor = $this->make_executor();
		$result   = $executor->execute( 'update_post', [ 'post_id' => 5, 'title' => 'New title' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'permissions', strtolower( $result['error'] ) );
	}
}

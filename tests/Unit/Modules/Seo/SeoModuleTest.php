<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Modules\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Modules\Seo\SeoModule;
use PHPUnit\Framework\TestCase;

class SeoModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── handle_generate error paths ────────────────────────────────────────────

	public function test_handle_generate_returns_404_for_invalid_post_id(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'post_id', 999 );

		$response = SeoModule::handle_generate( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_handle_generate_returns_403_when_user_cannot_edit_post(): void {
		$post               = new \stdClass();
		$post->ID           = 1;
		$post->post_title   = 'Test Post';
		$post->post_excerpt = '';
		$post->post_content = 'Content here.';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'post_id', 1 );

		$response = SeoModule::handle_generate( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 403, $response->get_status() );
	}

	// ── handle_apply happy path ────────────────────────────────────────────────

	public function test_handle_apply_saves_meta_fields_and_returns_updated_list(): void {
		$post               = new \stdClass();
		$post->ID           = 1;
		$post->post_title   = 'Test Post';
		$post->post_excerpt = '';
		$post->post_content = 'Content here.';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'current_user_can' )->alias( fn( ...$args ) => true );
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 42 );
		Functions\when( 'sanitize_text_field' )->alias( fn( $s ) => $s );
		Functions\when( 'sanitize_textarea_field' )->alias( fn( $s ) => $s );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'post_id', 1 );
		$request->set_param( 'meta_title', 'My SEO Title' );
		$request->set_param( 'og_description', 'An open graph description.' );
		$request->set_param( 'excerpt', 'A short excerpt.' );
		$request->set_param( 'alt_text', 'Descriptive alt text.' );

		$response = SeoModule::handle_apply( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'meta_title', $response->data['updated'] );
		$this->assertContains( 'og_description', $response->data['updated'] );
		$this->assertContains( 'excerpt', $response->data['updated'] );
		$this->assertContains( 'alt_text', $response->data['updated'] );
	}

	public function test_handle_apply_skips_empty_params(): void {
		$post               = new \stdClass();
		$post->ID           = 1;
		$post->post_title   = 'Test Post';
		$post->post_excerpt = '';
		$post->post_content = '';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'current_user_can' )->alias( fn( ...$args ) => true );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'post_id', 1 );
		// No meta_title, og_description, excerpt, or alt_text params set.

		$response = SeoModule::handle_apply( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->data['updated'] );
	}

	// ── permission_callback ────────────────────────────────────────────────────

	public function test_permission_callback_returns_false_when_usage_limit_exceeded(): void {
		$captured_args = [];

		Functions\when( 'register_rest_route' )->alias(
			function( $namespace, $route, $args ) use ( &$captured_args ) {
				$captured_args[ $route ] = $args;
			}
		);

		SeoModule::register_routes();

		$this->assertArrayHasKey( '/seo/generate', $captured_args );
		$permission_callback = $captured_args['/seo/generate']['permission_callback'];

		// User has permission but is over the free monthly limit.
		$month_key = 'wp_ai_mind_usage_' . gmdate( 'Y_m' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_user_meta' )->alias(
			function( $user_id, $key, $single ) use ( $month_key ) {
				if ( 'wp_ai_mind_tier' === $key ) {
					return 'free';
				}
				if ( $month_key === $key ) {
					return '60000'; // over 50k free limit
				}
				return '';
			}
		);

		$result = $permission_callback();

		$this->assertFalse( (bool) $result );
	}

	// ── register_seo_status_field ──────────────────────────────────────────────

	public function test_register_seo_status_field_registers_field_for_default_post_types(): void {
		// Brain Monkey uses Patchwork to patch functions, but once a class is loaded by the
		// autoloader (which happens during prior tests in the suite), apply_filters() calls
		// from that class can no longer be intercepted at the bytecode level. We therefore
		// test the observable side-effect: register_rest_field() must be called for each
		// post type returned by the filter (default: 'post' and 'page').
		$registered_for = [];

		Functions\when( 'register_rest_field' )->alias(
			function ( $post_type ) use ( &$registered_for ) {
				$registered_for[] = $post_type;
			}
		);

		SeoModule::register_seo_status_field();

		$this->assertContains( 'post', $registered_for );
		$this->assertContains( 'page', $registered_for );
	}
}

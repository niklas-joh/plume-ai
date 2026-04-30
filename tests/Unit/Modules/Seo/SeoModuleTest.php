<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Modules\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\Modules\Seo\SeoModule;
use WP_AI_Mind\Tests\Helpers\WpdbStubFactory;
use PHPUnit\Framework\TestCase;

class SeoModuleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		// Restore a valid $wpdb baseline so log_usage() does not crash in later test classes.
		global $wpdb;
		$wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional test stub.
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── generate_for_post ─────────────────────────────────────────────────────

	public function test_generate_for_post_returns_error_when_post_not_found(): void {
		Functions\when( 'get_post' )->justReturn( null );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( '__' )->alias( fn( $s ) => $s );

		$result = SeoModule::generate_for_post( 999, 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_generate_for_post_returns_error_on_provider_failure(): void {
		$post               = new \stdClass();
		$post->ID           = 1;
		$post->post_title   = 'Test';
		$post->post_excerpt = '';
		$post->post_content = 'Content';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => $s );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( 'wp_ai_mind_default_provider' === $key ) {
					return 'claude';
				}
				if ( 'wp_ai_mind_provider_settings' === $key ) {
					return [];
				}
				return $default;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 500 ],
			'body'     => json_encode( [ 'error' => [ 'message' => 'Server error' ] ] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		putenv( 'CLAUDE_API_KEY=sk-ant-test' );
		$result = SeoModule::generate_for_post( 1, 1 );
		putenv( 'CLAUDE_API_KEY=' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'provider_error', $result->get_error_code() );
	}

	public function test_generate_for_post_returns_error_on_invalid_json_response(): void {
		// Stub $wpdb so AbstractProvider::maybe_log() -> log_usage() doesn't crash.
		global $wpdb;
		$wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional test stub.

		$post               = new \stdClass();
		$post->ID           = 1;
		$post->post_title   = 'Test';
		$post->post_excerpt = '';
		$post->post_content = 'Content';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => $s );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( 'wp_ai_mind_default_provider' === $key ) {
					return 'claude';
				}
				if ( 'wp_ai_mind_provider_settings' === $key ) {
					return [];
				}
				return $default;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		// The AI returns a non-JSON string inside the content field.
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => [ [ 'type' => 'text', 'text' => 'not valid json at all' ] ],
				'model'   => 'claude-sonnet-4-6',
				'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		putenv( 'CLAUDE_API_KEY=sk-ant-test' );
		$result = SeoModule::generate_for_post( 1, 1 );
		putenv( 'CLAUDE_API_KEY=' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_json', $result->get_error_code() );
	}

	public function test_generate_for_post_returns_sanitised_array_on_success(): void {
		// Stub $wpdb so AbstractProvider::maybe_log() -> log_usage() doesn't crash.
		global $wpdb;
		$wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional test stub.

		$post               = new \stdClass();
		$post->ID           = 1;
		$post->post_title   = 'Great Post';
		$post->post_excerpt = '';
		$post->post_content = 'Some content here.';

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_strip_all_tags' )->alias( fn( $s ) => $s );
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = null ) {
				if ( 'wp_ai_mind_default_provider' === $key ) {
					return 'claude';
				}
				if ( 'wp_ai_mind_provider_settings' === $key ) {
					return [];
				}
				return $default;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->justReturn( 'pro_byok' );
		Functions\when( 'sanitize_key' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );
		Functions\when( 'sanitize_textarea_field' )->alias( fn( $v ) => $v );
		Functions\when( 'wp_json_encode' )->alias( fn( $v ) => json_encode( $v ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$seo_json = json_encode( [
			'meta_title'     => 'SEO Title',
			'og_description' => 'OG Description',
			'excerpt'        => 'Short excerpt.',
			'alt_text'       => 'Alt text for image',
		] );
		Functions\when( 'wp_remote_post' )->justReturn( [
			'response' => [ 'code' => 200 ],
			'body'     => json_encode( [
				'content' => [ [ 'type' => 'text', 'text' => $seo_json ] ],
				'model'   => 'claude-sonnet-4-6',
				'usage'   => [ 'input_tokens' => 100, 'output_tokens' => 50 ],
			] ),
		] );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( $r ) => $r['body'] );

		putenv( 'CLAUDE_API_KEY=sk-ant-test' );
		$result = SeoModule::generate_for_post( 1, 1 );
		putenv( 'CLAUDE_API_KEY=' );

		$this->assertIsArray( $result );
		$this->assertSame( 'SEO Title', $result['meta_title'] );
		$this->assertSame( 'OG Description', $result['og_description'] );
		$this->assertSame( 'Short excerpt.', $result['excerpt'] );
		$this->assertSame( 'Alt text for image', $result['alt_text'] );
		$this->assertSame( 150, $result['tokens_used'] );
	}

	// ── apply_for_post ────────────────────────────────────────────────────────

	public function test_apply_for_post_applies_all_fields_and_returns_updated_list(): void {
		Functions\when( 'wp_update_post' )->justReturn( 1 );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 42 );

		$fields = [
			'meta_title'     => 'SEO Title',
			'og_description' => 'OG Description',
			'excerpt'        => 'Short excerpt.',
			'alt_text'       => 'Featured image alt text',
		];

		$result = SeoModule::apply_for_post( 1, $fields );

		$this->assertSame( 1, $result['post_id'] );
		$this->assertContains( 'meta_title', $result['updated'] );
		$this->assertContains( 'og_description', $result['updated'] );
		$this->assertContains( 'excerpt', $result['updated'] );
		$this->assertContains( 'alt_text', $result['updated'] );
	}

	public function test_apply_for_post_skips_null_and_empty_fields(): void {
		$fields = [
			'meta_title'     => null,
			'og_description' => '',
			'excerpt'        => null,
			'alt_text'       => '',
		];

		$result = SeoModule::apply_for_post( 1, $fields );

		$this->assertSame( 1, $result['post_id'] );
		$this->assertSame( [], $result['updated'] );
	}

	// ── handle_generate error paths ────────────────────────────────────────────

	public function test_handle_generate_returns_404_for_invalid_post_id(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
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

		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'user_can' )->justReturn( false );
		// is_wp_error must recognise the WP_Error returned by generate_for_post.
		Functions\when( 'is_wp_error' )->alias( fn( $v ) => $v instanceof \WP_Error );
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

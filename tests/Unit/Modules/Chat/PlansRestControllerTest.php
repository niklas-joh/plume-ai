<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Chat\PlansRestController;
use Plume\Tools\PostWriter;
use PHPUnit\Framework\TestCase;

class PlansRestControllerTest extends TestCase {

	private PostWriter $post_writer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->post_writer = $this->createMock( PostWriter::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── check_permission ─────────────────────────────────────────────────────

	public function test_check_permission_returns_403_when_no_capability(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'current_user_can' )->justReturn( false );

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_permission_returns_true_when_authorised(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_permission();

		$this->assertTrue( $result );
	}

	// ── execute_plan: missing transient ──────────────────────────────────────

	public function test_execute_plan_returns_404_when_transient_missing(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );

		$controller = new PlansRestController( $this->post_writer );
		$request    = $this->make_request( 'abc12345' );

		$response = $controller->execute_plan( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	// ── execute_plan: writer error — transient must NOT be deleted ────────────

	public function test_execute_plan_returns_422_and_preserves_transient_on_writer_error(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 2 );
		Functions\when( 'get_transient' )->justReturn( [
			'id'          => 'abc12345',
			'plan_type'   => 'create',
			'title'       => 'My Post',
			'outline'     => 'An outline',
			'post_status' => 'draft',
			'post_type'   => 'post',
		] );
		Functions\expect( 'delete_transient' )->never();

		$this->post_writer->method( 'create' )->willReturn( [ 'error' => 'Write tools are disabled.' ] );

		$controller = new PlansRestController( $this->post_writer );
		$response   = $controller->execute_plan( $this->make_request( 'abc12345' ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 422, $response->get_error_data()['status'] );
	}

	// ── execute_plan: create happy path ──────────────────────────────────────

	public function test_execute_plan_creates_post_and_deletes_transient(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'get_transient' )->justReturn( [
			'id'          => 'abc12345',
			'plan_type'   => 'create',
			'title'       => 'New Post',
			'outline'     => 'Content outline',
			'post_status' => 'draft',
			'post_type'   => 'post',
		] );
		Functions\expect( 'delete_transient' )->once()->with( 'plume_plan_3_abc12345' );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.com/wp-admin/post.php?post=99' );

		$this->post_writer
			->expects( $this->once() )
			->method( 'create' )
			->with(
				$this->callback( fn( $args ) => 'New Post' === ( $args['title'] ?? '' ) ),
				3
			)
			->willReturn( [ 'post_id' => 99 ] );

		$controller = new PlansRestController( $this->post_writer );
		$response   = $controller->execute_plan( $this->make_request( 'abc12345' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 99, $response->data['post_id'] );
	}

	// ── execute_plan: update happy path ──────────────────────────────────────

	public function test_execute_plan_updates_post_and_deletes_transient(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 4 );
		Functions\when( 'get_transient' )->justReturn( [
			'id'          => 'def67890',
			'plan_type'   => 'update',
			'post_id'     => 42,
			'changes'     => 'Make intro snappier',
			'new_content' => 'The updated post body goes here.',
		] );
		Functions\expect( 'delete_transient' )->once()->with( 'plume_plan_4_def67890' );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.com/wp-admin/post.php?post=42' );

		$this->post_writer
			->expects( $this->once() )
			->method( 'update' )
			->with(
				$this->callback(
					fn( $args ) => 42 === ( $args['post_id'] ?? 0 )
						&& 'The updated post body goes here.' === ( $args['content'] ?? '' )
				),
				4
			)
			->willReturn( [ 'post_id' => 42 ] );

		$controller = new PlansRestController( $this->post_writer );
		$response   = $controller->execute_plan( $this->make_request( 'def67890' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 42, $response->data['post_id'] );
	}

	// ── execute_plan: stored full content becomes the post body ──────────────

	public function test_execute_plan_uses_stored_content_over_outline(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 6 );
		Functions\when( 'get_transient' )->justReturn( [
			'id'          => 'bbb22222',
			'plan_type'   => 'create',
			'title'       => 'Full Post',
			'outline'     => 'Short summary for the approval card.',
			'content'     => 'The complete article body, many paragraphs long.',
			'post_status' => 'draft',
			'post_type'   => 'post',
		] );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_edit_post_link' )->justReturn( '' );

		$this->post_writer
			->expects( $this->once() )
			->method( 'create' )
			->with(
				$this->callback(
					fn( $args ) => 'The complete article body, many paragraphs long.' === ( $args['content'] ?? '' )
				),
				6
			)
			->willReturn( [ 'post_id' => 7 ] );

		$controller = new PlansRestController( $this->post_writer );
		$controller->execute_plan( $this->make_request( 'bbb22222' ) );
	}

	public function test_execute_plan_falls_back_to_outline_for_legacy_plans(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_transient' )->justReturn( [
			'id'          => 'ccc33333',
			'plan_type'   => 'create',
			'title'       => 'Legacy Plan',
			'outline'     => 'Outline only — stored before content became required.',
			'post_status' => 'draft',
			'post_type'   => 'post',
		] );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_edit_post_link' )->justReturn( '' );

		$this->post_writer
			->expects( $this->once() )
			->method( 'create' )
			->with(
				$this->callback(
					fn( $args ) => 'Outline only — stored before content became required.' === ( $args['content'] ?? '' )
				),
				7
			)
			->willReturn( [ 'post_id' => 8 ] );

		$controller = new PlansRestController( $this->post_writer );
		$controller->execute_plan( $this->make_request( 'ccc33333' ) );
	}

	// ── execute_plan: request-body overrides are merged ───────────────────────

	public function test_execute_plan_merges_title_override_from_request(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'get_transient' )->justReturn( [
			'id'          => 'aaa11111',
			'plan_type'   => 'create',
			'title'       => 'Original title',
			'outline'     => 'Original outline',
			'post_status' => 'draft',
			'post_type'   => 'post',
		] );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_edit_post_link' )->justReturn( '' );

		$this->post_writer
			->expects( $this->once() )
			->method( 'create' )
			->with(
				$this->callback( fn( $args ) => 'Edited title' === ( $args['title'] ?? '' ) ),
				5
			)
			->willReturn( [ 'post_id' => 1 ] );

		$controller = new PlansRestController( $this->post_writer );
		$request    = $this->make_request( 'aaa11111' );
		$request->set_body_params( [ 'title' => 'Edited title' ] );

		$controller->execute_plan( $request );
	}

	// ── dismiss_plan ────────────────────────────────────────────────────────────

	public function test_dismiss_plan_deletes_the_transient_and_returns_204(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\expect( 'delete_transient' )->once()->andReturn( true );

		$controller = new PlansRestController( $this->post_writer );
		$request    = $this->make_request( 'ddd44444' );

		$response = $controller->dismiss_plan( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 204, $response->get_status() );
	}

	public function test_dismiss_plan_is_idempotent_when_transient_already_gone(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'delete_transient' )->justReturn( false );

		$controller = new PlansRestController( $this->post_writer );
		$request    = $this->make_request( 'unknown1' );

		$response = $controller->dismiss_plan( $request );

		$this->assertSame( 204, $response->get_status() );
	}

	// ── check_execute_permission ──────────────────────────────────────────────

	/**
	 * Stub current_user_can() so only the listed capabilities are granted.
	 *
	 * @param string[] $capabilities Capabilities the test user holds.
	 */
	private function grant_capabilities( array $capabilities ): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability ): bool => in_array( $capability, $capabilities, true )
		);
	}

	private function stub_post_types(): void {
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $post_type ): ?object => in_array( $post_type, [ 'post', 'page' ], true )
				? (object) [
					'cap' => (object) [
						'create_posts'  => 'post' === $post_type ? 'edit_posts' : 'edit_pages',
						'publish_posts' => 'post' === $post_type ? 'publish_posts' : 'publish_pages',
					],
				]
				: null
		);
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( (int) $v ) );
		Functions\when( '__' )->alias( static fn( string $text ) => $text );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
	}

	public function test_check_execute_permission_allows_an_editor(): void {
		$this->stub_post_types();
		$this->grant_capabilities( [ 'edit_posts', 'publish_posts' ] );
		Functions\when( 'get_transient' )->justReturn( [
			'plan_type'   => 'create',
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );

		$controller = new PlansRestController( $this->post_writer );

		$this->assertTrue( $controller->check_execute_permission( $this->make_request( 'abc12345' ) ) );
	}

	public function test_check_execute_permission_refuses_publish_without_publish_capability(): void {
		$this->stub_post_types();
		// A Contributor holds edit_posts but not publish_posts.
		$this->grant_capabilities( [ 'edit_posts' ] );
		Functions\when( 'get_transient' )->justReturn( [
			'plan_type'   => 'create',
			'post_type'   => 'post',
			'post_status' => 'publish',
		] );

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_execute_permission( $this->make_request( 'abc12345' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_execute_permission_refuses_a_publish_override_on_a_draft_plan(): void {
		$this->stub_post_types();
		$this->grant_capabilities( [ 'edit_posts' ] );
		Functions\when( 'get_transient' )->justReturn( [
			'plan_type'   => 'create',
			'post_type'   => 'post',
			'post_status' => 'draft',
		] );

		$request = $this->make_request( 'abc12345' );
		$request->set_param( 'status', 'publish' );

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_execute_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
	}

	public function test_check_execute_permission_refuses_a_page_plan_without_page_capability(): void {
		$this->stub_post_types();
		// edit_posts is the 'post' capability — it must not unlock pages.
		$this->grant_capabilities( [ 'edit_posts', 'publish_posts' ] );
		Functions\when( 'get_transient' )->justReturn( [
			'plan_type'   => 'create',
			'post_type'   => 'page',
			'post_status' => 'draft',
		] );

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_execute_permission( $this->make_request( 'abc12345' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_execute_permission_checks_the_target_post_on_update_plans(): void {
		$this->stub_post_types();
		Functions\when( 'get_post' )->alias(
			static fn( int $id ): object => (object) [ 'ID' => $id, 'post_type' => 'post' ]
		);
		Functions\when( 'get_transient' )->justReturn( [
			'plan_type'   => 'update',
			'post_id'     => 55,
			'post_type'   => 'post',
			'post_status' => '',
		] );

		$checked = [];
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability, ...$args ) use ( &$checked ): bool {
				$checked[] = [ $capability, $args[0] ?? null ];
				return 'edit_post' !== $capability;
			}
		);

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_execute_permission( $this->make_request( 'abc12345' ) );

		$this->assertContains( [ 'edit_post', 55 ], $checked );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_execute_permission_refuses_a_publish_override_on_an_update_plan(): void {
		$this->stub_post_types();
		Functions\when( 'get_post' )->alias(
			static fn( int $id ): object => (object) [ 'ID' => $id, 'post_type' => 'post' ]
		);
		Functions\when( 'get_transient' )->justReturn( [
			'plan_type'   => 'update',
			'post_id'     => 55,
			'post_type'   => 'post',
			'post_status' => '',
		] );
		// May edit the target post, but may not make it public.
		$this->grant_capabilities( [ 'edit_posts', 'edit_post' ] );

		$request = $this->make_request( 'abc12345' );
		$request->set_param( 'status', 'publish' );

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_execute_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_execute_permission_defers_to_the_handler_when_the_plan_is_missing(): void {
		$this->stub_post_types();
		$this->grant_capabilities( [ 'edit_posts' ] );
		Functions\when( 'get_transient' )->justReturn( false );

		$controller = new PlansRestController( $this->post_writer );

		$this->assertTrue( $controller->check_execute_permission( $this->make_request( 'gone1234' ) ) );
	}

	public function test_check_execute_permission_refuses_without_edit_posts(): void {
		$this->stub_post_types();
		$this->grant_capabilities( [] );
		Functions\expect( 'get_transient' )->never();

		$controller = new PlansRestController( $this->post_writer );
		$result     = $controller->check_execute_permission( $this->make_request( 'abc12345' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// ── helper ────────────────────────────────────────────────────────────────

	private function make_request( string $plan_id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST' );
		$request->set_url_params( [ 'id' => $plan_id ] );
		return $request;
	}
}

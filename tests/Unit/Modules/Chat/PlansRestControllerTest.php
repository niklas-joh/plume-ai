<?php
namespace Stilus\Tests\Unit\Modules\Chat;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Stilus\Modules\Chat\PlansRestController;
use Stilus\Tools\ToolExecutor;
use PHPUnit\Framework\TestCase;

class PlansRestControllerTest extends TestCase {

	private ToolExecutor $executor;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->executor = $this->createMock( ToolExecutor::class );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── check_permission ─────────────────────────────────────────────────────

	public function test_check_permission_returns_403_when_no_capability(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'current_user_can' )->justReturn( false );

		$controller = new PlansRestController( $this->executor );
		$result     = $controller->check_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_check_permission_returns_true_when_authorised(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$controller = new PlansRestController( $this->executor );
		$result     = $controller->check_permission();

		$this->assertTrue( $result );
	}

	// ── execute_plan: missing transient ──────────────────────────────────────

	public function test_execute_plan_returns_404_when_transient_missing(): void {
		Functions\when( '__' )->alias( fn( $s ) => $s );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );

		$controller = new PlansRestController( $this->executor );
		$request    = $this->make_request( 'abc12345' );

		$response = $controller->execute_plan( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 404, $response->get_error_data()['status'] );
	}

	// ── execute_plan: executor error — transient must NOT be deleted ─────────

	public function test_execute_plan_returns_422_and_preserves_transient_on_executor_error(): void {
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

		$this->executor->method( 'execute' )->willReturn( [ 'error' => 'Write tools are disabled.' ] );

		$controller = new PlansRestController( $this->executor );
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
		Functions\expect( 'delete_transient' )->once()->with( 'stilus_plan_3_abc12345' );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.com/wp-admin/post.php?post=99' );

		$this->executor
			->expects( $this->once() )
			->method( 'execute' )
			->with( 'create_post', $this->anything(), 3 )
			->willReturn( [ 'post_id' => 99 ] );

		$controller = new PlansRestController( $this->executor );
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
		Functions\expect( 'delete_transient' )->once()->with( 'stilus_plan_4_def67890' );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.com/wp-admin/post.php?post=42' );

		$this->executor
			->expects( $this->once() )
			->method( 'execute' )
			->with(
				'update_post',
				$this->callback(
					fn( $args ) => 42 === ( $args['post_id'] ?? 0 )
						&& 'The updated post body goes here.' === ( $args['content'] ?? '' )
				),
				4
			)
			->willReturn( [ 'post_id' => 42 ] );

		$controller = new PlansRestController( $this->executor );
		$response   = $controller->execute_plan( $this->make_request( 'def67890' ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 42, $response->data['post_id'] );
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

		$this->executor
			->expects( $this->once() )
			->method( 'execute' )
			->with(
				'create_post',
				$this->callback( fn( $args ) => 'Edited title' === ( $args['title'] ?? '' ) ),
				5
			)
			->willReturn( [ 'post_id' => 1 ] );

		$controller = new PlansRestController( $this->executor );
		$request    = $this->make_request( 'aaa11111' );
		$request->set_body_params( [ 'title' => 'Edited title' ] );

		$controller->execute_plan( $request );
	}

	// ── helper ────────────────────────────────────────────────────────────────

	private function make_request( string $plan_id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST' );
		$request->set_url_params( [ 'id' => $plan_id ] );
		return $request;
	}
}

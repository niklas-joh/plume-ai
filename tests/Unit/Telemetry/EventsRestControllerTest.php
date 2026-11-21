<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Telemetry;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Telemetry\EventsRestController;
use Plume\Telemetry\Telemetry;
use Plume\Tests\Helpers\TelemetryHarness;
use PHPUnit\Framework\TestCase;

class EventsRestControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Telemetry::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function registered_route(): array {
		$routes = [];
		Functions\when( 'register_rest_route' )->alias(
			function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[ $ns . $route ] = $args;
				return true;
			}
		);
		EventsRestController::register_routes();
		$this->assertArrayHasKey( 'plume/v1/telemetry/event', $routes );
		return $routes['plume/v1/telemetry/event'];
	}

	private function request( string $event, array $props = [] ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/plume/v1/telemetry/event' );
		$request->set_param( 'event', $event );
		$request->set_param( 'props', $props );
		return $request;
	}

	public function test_route_is_registered_as_post_with_a_permission_callback(): void {
		$route = $this->registered_route();
		$this->assertSame( 'POST', $route['methods'] );
		$this->assertSame( [ EventsRestController::class, 'check_permission' ], $route['permission_callback'] );
	}

	public function test_only_client_events_are_accepted_by_the_enum(): void {
		$route = $this->registered_route();
		$this->assertSame( [ 'onboarding_step', 'upgrade_click' ], $route['args']['event']['enum'] );
		$this->assertTrue( $route['args']['event']['required'] );
		$this->assertNotContains( 'ai_request', $route['args']['event']['enum'] );
		$this->assertNotContains( 'heartbeat', $route['args']['event']['enum'] );
	}

	public function test_props_schema(): void {
		$props = $this->registered_route()['args']['props'];
		$this->assertSame( 'object', $props['type'] );
		$this->assertSame( [ 'string', 'integer' ], $props['additionalProperties']['type'] );
		$this->assertSame( 5, $props['maxProperties'] );
	}

	public function test_contributor_may_send_upgrade_click_but_not_onboarding_step(): void {
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => 'edit_posts' === $cap );

		$this->assertTrue( EventsRestController::check_permission( $this->request( 'upgrade_click' ) ) );
		$error = EventsRestController::check_permission( $this->request( 'onboarding_step' ) );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 403, $error->get_error_data()['status'] );
	}

	public function test_admin_may_send_both(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( EventsRestController::check_permission( $this->request( 'upgrade_click' ) ) );
		$this->assertTrue( EventsRestController::check_permission( $this->request( 'onboarding_step' ) ) );
	}

	public function test_server_only_events_are_forbidden(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertInstanceOf( \WP_Error::class, EventsRestController::check_permission( $this->request( 'ai_request' ) ) );
	}

	public function test_response_is_202_while_inactive_and_nothing_is_queued(): void {
		$h        = TelemetryHarness::install();
		$response = EventsRestController::handle( $this->request( 'upgrade_click', [ 'placement' => 'onboarding' ] ) );
		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->data );
		Telemetry::persist();
		$this->assertSame( [], $h->queue() );
	}

	public function test_event_is_queued_while_active(): void {
		$h = TelemetryHarness::install();
		$h->consent_yes();
		$response = EventsRestController::handle(
			$this->request( 'onboarding_step', [ 'step' => 'providers', 'action' => 'viewed', 'extra' => 'x' ] )
		);
		$this->assertSame( 202, $response->get_status() );
		$queue = $h->flush();
		$this->assertSame( 'onboarding_step', $queue[0]['name'] );
		$this->assertSame( 'providers', $queue[0]['step'] );
		$this->assertArrayNotHasKey( 'extra', $queue[0] );
		$this->assertSame( [], $h->posts );
	}
}

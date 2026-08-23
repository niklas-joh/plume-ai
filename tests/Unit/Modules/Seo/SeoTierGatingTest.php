<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Modules\Seo\SeoModule;
use PHPUnit\Framework\TestCase;

/**
 * Tier-gating unit tests for the SEO module REST routes.
 *
 * Both /seo/generate and /seo/apply permission_callbacks no longer check tier
 * or quota — credit enforcement happens entirely on the Worker side. They now
 * collapse to a single current_user_can('edit_post', $post_id) check against the
 * post the request targets, identical across every tier.
 */
class SeoTierGatingTest extends TestCase {

	/** @var array<string, array<string, mixed>> */
	private array $captured_routes = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_option' )->justReturn( 'free' );
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( (int) $v ) );
		Functions\when( '__' )->alias( static fn( string $text ) => $text );
		Functions\when( 'get_post' )->alias(
			static fn( int $id ): ?object => 404 === $id ? null : (object) [ 'ID' => $id, 'post_type' => 'post' ]
		);

		// Capture the registered routes so we can invoke permission_callback directly.
		$this->captured_routes = [];
		Functions\when( 'register_rest_route' )->alias(
			function ( string $namespace, string $route, array $args ): void {
				$this->captured_routes[ $route ] = $args;
			}
		);

		SeoModule::register_routes();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a request targeting a post, as both routes require a post_id.
	 */
	private function make_request( int $post_id = 7 ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/seo' );
		$request->set_param( 'post_id', $post_id );

		return $request;
	}

	public static function gated_routes(): array {
		return [
			'/seo/generate' => [ '/seo/generate' ],
			'/seo/apply'    => [ '/seo/apply' ],
		];
	}

	/**
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_returns_200_for_free_tier_user( string $route ): void {
		$this->assertArrayHasKey( $route, $this->captured_routes );
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( (bool) $permission_callback( $this->make_request() ) );
	}

	/**
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_permission_callback_ignores_tier_entirely( string $route ): void {
		$this->assertArrayHasKey( $route, $this->captured_routes );
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( true );

		foreach ( [ 'free', 'pro_managed', 'pro_byok' ] as $tier ) {
			Functions\when( 'get_option' )->justReturn( $tier );
			$this->assertTrue( (bool) $permission_callback( $this->make_request() ), "permission_callback() must return true for tier '{$tier}'." );
		}
	}

	/**
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_returns_403_without_edit_post_capability( string $route ): void {
		$this->assertArrayHasKey( $route, $this->captured_routes );
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		Functions\when( 'current_user_can' )->justReturn( false );

		$result = $permission_callback( $this->make_request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * The capability must be resolved against the requested post, not the generic
	 * edit_posts capability: a contributor holds edit_posts for their own drafts but
	 * must not be able to run SEO generation against someone else's post.
	 *
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_checks_capability_against_the_requested_post( string $route ): void {
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		$checked = [];
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability, ...$args ) use ( &$checked ): bool {
				$checked[] = [ $capability, $args[0] ?? null ];
				return true;
			}
		);

		$permission_callback( $this->make_request( 55 ) );

		$this->assertSame( [ [ 'edit_post', 55 ] ], $checked );
	}

	/**
	 * @dataProvider gated_routes
	 */
	public function test_seo_route_defers_to_the_handler_for_a_missing_post( string $route ): void {
		$permission_callback = $this->captured_routes[ $route ]['permission_callback'];

		Functions\expect( 'current_user_can' )->never();

		$this->assertTrue( $permission_callback( $this->make_request( 404 ) ) );
	}
}

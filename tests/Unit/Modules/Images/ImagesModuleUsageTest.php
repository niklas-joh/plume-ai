<?php
/**
 * Behavioural tests: image generation must not write managed-credit usage.
 *
 * @package Plume\Tests\Unit\Modules\Images
 */

declare( strict_types=1 );

namespace Plume\Tests\Unit\Modules\Images;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Plume\Modules\Images\ImagesModule;

/**
 * Images are generated with the user's own API key and never reach the managed
 * proxy, so ImagesModule::handle_generate() must never write to the credit meter
 * (UsageTracker::log_usage writes via $wpdb->query and add_user_meta).
 *
 * The real OpenAI provider runs end to end with WordPress and HTTP functions
 * stubbed, so no production seam is needed.
 *
 * @since NEXT_VERSION
 */
class ImagesModuleUsageTest extends TestCase {

	/** @var string[] Empty wp-admin include stubs created for this test, removed in tearDown. */
	private array $created_files = [];

	/** @var string[] Directories created for this test, removed in tearDown. */
	private array $created_dirs = [];

	/** @var mixed Original $wpdb global, restored in tearDown. */
	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;

		// AbstractProvider::save_image_to_media_library() require_once's these core files.
		// They do not exist in the unit-test tree, so provide empty placeholders.
		$dir = ABSPATH . 'wp-admin/includes';
		foreach ( [ ABSPATH . 'wp-admin', $dir ] as $d ) {
			if ( ! is_dir( $d ) ) {
				mkdir( $d ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture.
				$this->created_dirs[] = $d;
			}
		}
		foreach ( [ 'media', 'file', 'image' ] as $name ) {
			$file = "{$dir}/{$name}.php";
			if ( ! file_exists( $file ) ) {
				file_put_contents( $file, "<?php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				$this->created_files[] = $file;
			}
		}

		// Any write to the credit meter would go through one of these two calls.
		$wpdb = Mockery::mock( 'stdClass' );
		$wpdb->shouldNotReceive( 'query' );
		$GLOBALS['wpdb'] = $wpdb;
		Functions\expect( 'add_user_meta' )->never();

		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => 'plume_image_provider' === $name ? 'openai' : $default
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $v ): bool => $v instanceof \WP_Error );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\stubs(
			[
				'get_current_user_id'      => 7,
				'__'                       => null,
				'esc_url_raw'              => null,
				'sanitize_file_name'       => null,
				'sanitize_textarea_field'  => null,
				'wp_delete_file'           => null,
				'update_post_meta'         => true,
				'wp_get_attachment_image_src' => static fn( int $id ) => [ "https://example.test/{$id}.png" ],
			]
		);
	}

	protected function tearDown(): void {
		foreach ( $this->created_files as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture.
		}
		foreach ( array_reverse( $this->created_dirs ) as $d ) {
			rmdir( $d ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test fixture.
		}

		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}

		Mockery::close();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a request for $count images.
	 *
	 * @param int $count Number of images requested.
	 * @return \WP_REST_Request
	 */
	private function request( int $count ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/plume/v1/images/generate' );
		$request->set_body_params(
			[
				'prompt'       => 'a lighthouse at dusk',
				'aspect_ratio' => '1:1',
				'count'        => $count,
			]
		);
		return $request;
	}

	/**
	 * Stub the HTTP and media layers.
	 *
	 * @param bool[] $outcomes Per-image success flag, in call order.
	 */
	private function stub_generation( array $outcomes ): void {
		$http_calls = 0;
		Functions\when( 'wp_remote_post' )->alias(
			static function () use ( &$http_calls, $outcomes ) {
				$ok = $outcomes[ $http_calls++ ] ?? false;
				return $ok
					? [ 'body' => '{"data":[{"url":"https://example.test/img.png"}]}' ]
					: new \WP_Error( 'http_request_failed', 'boom' );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $r ): string => $r['body'] );
		Functions\when( 'download_url' )->justReturn( '/tmp/plume-test.png' );

		$attachment_id = 100;
		Functions\when( 'media_handle_sideload' )->alias(
			static function () use ( &$attachment_id ): int {
				return ++$attachment_id;
			}
		);
	}

	/**
	 * A fully successful batch returns 201 and writes no usage.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_success_writes_no_usage(): void {
		$this->stub_generation( [ true, true, true ] );

		$response = ImagesModule::handle_generate( $this->request( 3 ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 3, $response->data['count'] );
		// The never() expectations on $wpdb->query and add_user_meta are verified in tearDown.
	}

	/**
	 * When every image fails the route returns 500 and writes no usage.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_all_fail_returns_500_and_writes_no_usage(): void {
		$this->stub_generation( [ false, false, false ] );

		$response = ImagesModule::handle_generate( $this->request( 3 ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertCount( 3, $response->data['details'] );
	}

	/**
	 * A partial failure returns 207 and writes no usage.
	 *
	 * @since NEXT_VERSION
	 */
	public function test_partial_failure_returns_207_and_writes_no_usage(): void {
		$this->stub_generation( [ true, false, true ] );

		$response = ImagesModule::handle_generate( $this->request( 3 ) );

		$this->assertSame( 207, $response->get_status() );
		$this->assertSame( 2, $response->data['count'] );
		$this->assertCount( 1, $response->data['errors'] );
	}
}

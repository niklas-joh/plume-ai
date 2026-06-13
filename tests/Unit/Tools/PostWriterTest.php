<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Tools\PostWriter;
use Plume\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

class PostWriterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_writer( array $allowed_post_types = [ 'post', 'page' ] ): PostWriter {
		$registry = $this->createMock( ToolRegistry::class );
		$registry->method( 'allowed_post_types' )->willReturn( $allowed_post_types );
		return new PostWriter( $registry );
	}

	// -------------------------------------------------------------------------
	// create()
	// -------------------------------------------------------------------------

	public function test_create_returns_error_when_write_tools_disabled(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = null ) =>
			'plume_enable_write_tools' === $key ? false : $default
		);

		$result = $this->make_writer()->create( [ 'title' => 'Test' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'disabled', strtolower( $result['error'] ) );
	}

	public function test_create_returns_error_when_post_type_not_allowed(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );

		$result = $this->make_writer()->create( [ 'title' => 'Test', 'post_type' => 'product' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'not permitted', $result['error'] );
	}

	public function test_create_returns_error_when_insufficient_permissions(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );

		$result = $this->make_writer()->create( [ 'title' => 'Test' ], 99 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'permissions', strtolower( $result['error'] ) );
	}

	public function test_create_returns_error_when_title_empty(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->justReturn( '' );

		$result = $this->make_writer()->create( [ 'title' => '' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'title', strtolower( $result['error'] ) );
	}

	public function test_create_inserts_post_and_returns_data(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_insert_post' )->justReturn( 42 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.com/edit/42' );

		$result = $this->make_writer()->create( [ 'title' => 'My Post', 'post_type' => 'post' ], 1 );

		$this->assertSame( 42, $result['post_id'] );
		$this->assertSame( 'My Post', $result['title'] );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_create_applies_meta_fields(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_insert_post' )->justReturn( 42 );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.com/edit/42' );

		$updated_meta = [];
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $post_id, string $key, string $value ) use ( &$updated_meta ): void {
				$updated_meta[ $key ] = $value;
			}
		);

		$result = $this->make_writer()->create(
			[ 'title' => 'Product', 'post_type' => 'post', 'meta_fields' => [ '_price' => '9.99', '_sku' => 'TEST-1' ] ],
			1
		);

		$this->assertSame( 42, $result['post_id'] );
		$this->assertSame( '9.99', $updated_meta['_price'] );
		$this->assertSame( 'TEST-1', $updated_meta['_sku'] );
	}

	// -------------------------------------------------------------------------
	// update()
	// -------------------------------------------------------------------------

	public function test_update_returns_error_when_write_tools_disabled(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = null ) =>
			'plume_enable_write_tools' === $key ? false : $default
		);

		$result = $this->make_writer()->update( [ 'post_id' => 5, 'title' => 'New' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'disabled', strtolower( $result['error'] ) );
	}

	public function test_update_returns_error_when_post_id_zero(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'absint' )->justReturn( 0 );

		$result = $this->make_writer()->update( [ 'post_id' => 0, 'title' => 'New' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'post_id', strtolower( $result['error'] ) );
	}

	public function test_update_returns_error_when_insufficient_permissions(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'user_can' )->justReturn( false );

		$result = $this->make_writer()->update( [ 'post_id' => 5, 'title' => 'New' ], 99 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'permissions', strtolower( $result['error'] ) );
	}

	public function test_update_returns_error_when_no_fields_provided(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'user_can' )->justReturn( true );

		$result = $this->make_writer()->update( [ 'post_id' => 5 ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'No fields', $result['error'] );
	}

	public function test_update_applies_meta_fields(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_update_post' )->justReturn( 5 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$updated_meta = [];
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $post_id, string $key, string $value ) use ( &$updated_meta ): void {
				$updated_meta[ $key ] = $value;
			}
		);

		$result = $this->make_writer()->update(
			[ 'post_id' => 5, 'title' => 'New Title', 'meta_fields' => [ '_price' => '19.99' ] ],
			1
		);

		$this->assertTrue( $result['updated'] );
		$this->assertSame( '19.99', $updated_meta['_price'] );
	}

	// -------------------------------------------------------------------------
	// WP_Error paths
	// -------------------------------------------------------------------------

	public function test_create_returns_error_when_wp_insert_post_fails(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_insert_post' )->justReturn(
			new \WP_Error( 'db_insert_error', 'Could not insert post into the database.' )
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );

		$result = $this->make_writer()->create( [ 'title' => 'Doomed Post' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'Could not insert post into the database.', $result['error'] );
	}

	public function test_update_returns_error_when_wp_update_post_fails(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_update_post' )->justReturn(
			new \WP_Error( 'db_update_error', 'Could not update post in the database.' )
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\expect( 'update_post_meta' )->never();

		$result = $this->make_writer()->update( [ 'post_id' => 5, 'title' => 'New Title' ], 1 );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertSame( 'Could not update post in the database.', $result['error'] );
	}

	// -------------------------------------------------------------------------
	// Markdown normalisation
	// -------------------------------------------------------------------------

	public function test_create_normalises_markdown_content(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => $v );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => $v );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'has_blocks' )->alias( static fn( $c ) => str_contains( (string) $c, '<!-- wp:' ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_edit_post_link' )->justReturn( 'http://example.test/edit' );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$captured = null;
		Functions\when( 'wp_insert_post' )->alias(
			static function ( array $data ) use ( &$captured ) {
				$captured = $data;
				return 123;
			}
		);

		$result = $this->make_writer()->create(
			[
				'title'   => 'T',
				'content' => "## Heading\n\nBody text.",
			],
			1
		);

		$this->assertSame( 123, $result['post_id'] );
		$this->assertNotNull( $captured );
		$this->assertStringContainsString( '<!-- wp:heading -->', $captured['post_content'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $captured['post_content'] );
	}

	public function test_update_normalises_markdown_content(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) =>
			'plume_enable_write_tools' === $key ? true : $default
		);
		Functions\when( 'absint' )->alias( static fn( $v ) => (int) abs( $v ) );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'wp_kses_post' )->alias( static fn( $v ) => $v );
		Functions\when( 'has_blocks' )->alias( static fn( $c ) => str_contains( (string) $c, '<!-- wp:' ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$captured = null;
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $data ) use ( &$captured ) {
				$captured = $data;
				return 42;
			}
		);

		$result = $this->make_writer()->update( [ 'post_id' => 42, 'content' => '*emphasis* text' ], 1 );

		$this->assertTrue( $result['updated'] );
		$this->assertNotNull( $captured );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $captured['post_content'] );
		$this->assertStringContainsString( '<em>emphasis</em>', $captured['post_content'] );
	}
}

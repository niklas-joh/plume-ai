<?php
declare( strict_types=1 );

namespace Stilus\Tests\Unit\Modules\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Stilus\Modules\Seo\SeoModule;
use PHPUnit\Framework\TestCase;

class SeoStatusFieldTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_all_empty_when_no_meta_set(): void {
		$post_id = 42;

		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$result = SeoModule::get_seo_status( [ 'id' => $post_id, 'excerpt' => [ 'raw' => '' ] ] );

		$this->assertSame( 'empty', $result['meta_title'] );
		$this->assertSame( 'empty', $result['og_description'] );
		$this->assertSame( 'empty', $result['excerpt'] );
		$this->assertSame( 'empty', $result['alt_text'] );
	}

	public function test_yoast_meta_title_detected_as_filled(): void {
		Functions\expect( 'get_post_meta' )
			->with( 42, '_yoast_wpseo_title', true )
			->andReturn( 'My Yoast Title' );
		Functions\expect( 'get_post_meta' )
			->with( 42, '_yoast_wpseo_metadesc', true )
			->andReturn( '' );
		Functions\expect( 'get_post_meta' )
			->with( 42, 'rank_math_description', true )
			->andReturn( '' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$result = SeoModule::get_seo_status( [ 'id' => 42, 'excerpt' => [ 'raw' => '' ] ] );

		$this->assertSame( 'filled', $result['meta_title'] );
	}

	public function test_rank_math_title_detected_as_filled_when_yoast_empty(): void {
		Functions\when( 'get_post_meta' )
			->alias( function( $id, $key, $single ) {
				if ( $key === 'rank_math_title' ) return 'My RankMath Title';
				return '';
			} );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$result = SeoModule::get_seo_status( [ 'id' => 42, 'excerpt' => [ 'raw' => '' ] ] );

		$this->assertSame( 'filled', $result['meta_title'] );
	}

	public function test_excerpt_detected_as_filled(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$result = SeoModule::get_seo_status( [ 'id' => 42, 'excerpt' => [ 'raw' => 'A nice summary.' ] ] );

		$this->assertSame( 'filled', $result['excerpt'] );
	}

	public function test_alt_text_filled_when_featured_image_has_alt(): void {
		Functions\when( 'get_post_meta' )
			->alias( function( $id, $key, $single ) {
				if ( $key === '_wp_attachment_image_alt' ) return 'A descriptive alt text';
				return '';
			} );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 99 );

		$result = SeoModule::get_seo_status( [ 'id' => 42, 'excerpt' => [ 'raw' => '' ] ] );

		$this->assertSame( 'filled', $result['alt_text'] );
	}

	public function test_alt_text_empty_when_no_featured_image(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$result = SeoModule::get_seo_status( [ 'id' => 42, 'excerpt' => [ 'raw' => '' ] ] );

		$this->assertSame( 'empty', $result['alt_text'] );
	}
}

<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Content;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Content\ContentNormaliser;

class ContentNormaliserTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Mirror core's has_blocks() detection logic.
		Functions\when( 'has_blocks' )->alias(
			static fn( $content ) => is_string( $content ) && str_contains( $content, '<!-- wp:' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_content_returns_empty_string(): void {
		$this->assertSame( '', ( new ContentNormaliser() )->normalise( '' ) );
		$this->assertSame( '', ( new ContentNormaliser() )->normalise( "  \n " ) );
	}

	public function test_existing_block_markup_is_returned_unchanged(): void {
		$blocks = "<!-- wp:paragraph -->\n<p>Done already.</p>\n<!-- /wp:paragraph -->";
		$this->assertSame( $blocks, ( new ContentNormaliser() )->normalise( $blocks ) );
	}

	public function test_markdown_heading_and_paragraph_become_blocks(): void {
		$out = ( new ContentNormaliser() )->normalise( "## Hello\n\nA **bold** start." );
		$this->assertStringContainsString( '<!-- wp:heading -->', $out );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Hello</h2>', $out );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $out );
		$this->assertStringContainsString( '<strong>bold</strong>', $out );
	}

	public function test_markdown_list_becomes_list_block(): void {
		$out = ( new ContentNormaliser() )->normalise( "- one\n- two" );
		$this->assertStringContainsString( '<!-- wp:list -->', $out );
		$this->assertStringContainsString( '<li>one</li>', $out );
	}

	public function test_gfm_table_becomes_table_block(): void {
		$md  = "| A | B |\n|---|---|\n| 1 | 2 |";
		$out = ( new ContentNormaliser() )->normalise( $md );
		$this->assertStringContainsString( '<!-- wp:table -->', $out );
		$this->assertStringContainsString( '<figure class="wp-block-table">', $out );
	}

	public function test_plain_html_input_is_wrapped_into_blocks(): void {
		$out = ( new ContentNormaliser() )->normalise( '<p>Already HTML.</p>' );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $out );
		$this->assertStringContainsString( 'Already HTML.', $out );
	}

	public function test_code_fence_with_html_keeps_entities_escaped(): void {
		$out = ( new ContentNormaliser() )->normalise( "```\n<script>alert(1)</script>\n```" );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
		$this->assertStringNotContainsString( '<script>', $out );
	}

	public function test_output_filter_is_applied(): void {
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'plume_normalised_content', \Mockery::type( 'string' ), 'Hi.' )
			->andReturnUsing( static fn( $tag, $value ) => $value . '<!-- filtered -->' );

		$out = ( new ContentNormaliser() )->normalise( 'Hi.' );
		$this->assertStringContainsString( '<!-- filtered -->', $out );
	}
}

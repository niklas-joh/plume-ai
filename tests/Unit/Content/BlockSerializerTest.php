<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use Plume\Content\BlockSerializer;

class BlockSerializerTest extends TestCase {

	private BlockSerializer $serializer;

	protected function setUp(): void {
		parent::setUp();
		$this->serializer = new BlockSerializer();
	}

	public function test_paragraph_is_wrapped(): void {
		$out = $this->serializer->serialise( '<p>Hello world.</p>' );
		$this->assertStringContainsString( "<!-- wp:paragraph -->", $out );
		$this->assertStringContainsString( "<p>Hello world.</p>", $out );
		$this->assertStringContainsString( "<!-- /wp:paragraph -->", $out );
	}

	public function test_h2_heading_has_no_level_attribute(): void {
		$out = $this->serializer->serialise( '<h2>Section</h2>' );
		$this->assertStringContainsString( '<!-- wp:heading -->', $out );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Section</h2>', $out );
		$this->assertStringContainsString( '<!-- /wp:heading -->', $out );
	}

	public function test_h3_heading_carries_level_attribute(): void {
		$out = $this->serializer->serialise( '<h3>Sub</h3>' );
		$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $out );
		$this->assertStringContainsString( '<h3 class="wp-block-heading">Sub</h3>', $out );
	}

	public function test_unordered_list(): void {
		$out = $this->serializer->serialise( '<ul><li>One</li><li>Two</li></ul>' );
		$this->assertStringContainsString( '<!-- wp:list -->', $out );
		$this->assertStringContainsString( '<ul class="wp-block-list">', $out );
		$this->assertStringContainsString( '<!-- /wp:list -->', $out );
	}

	public function test_ordered_list_carries_ordered_attribute(): void {
		$out = $this->serializer->serialise( '<ol><li>One</li></ol>' );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $out );
		$this->assertStringContainsString( '<ol class="wp-block-list">', $out );
	}

	public function test_code_block(): void {
		$out = $this->serializer->serialise( "<pre><code>echo 'hi';</code></pre>" );
		$this->assertStringContainsString( '<!-- wp:code -->', $out );
		$this->assertStringContainsString( '<pre class="wp-block-code">', $out );
		$this->assertStringContainsString( '<code>', $out );
	}

	public function test_blockquote(): void {
		$out = $this->serializer->serialise( '<blockquote><p>Wise words.</p></blockquote>' );
		$this->assertStringContainsString( '<!-- wp:quote -->', $out );
		$this->assertStringContainsString( '<blockquote class="wp-block-quote">', $out );
	}

	public function test_horizontal_rule(): void {
		$out = $this->serializer->serialise( '<hr>' );
		$this->assertStringContainsString( '<!-- wp:separator -->', $out );
		$this->assertStringContainsString( 'wp-block-separator', $out );
	}

	public function test_table_is_wrapped_in_figure(): void {
		$out = $this->serializer->serialise( '<table><tr><td>x</td></tr></table>' );
		$this->assertStringContainsString( '<!-- wp:table -->', $out );
		$this->assertStringContainsString( '<figure class="wp-block-table">', $out );
		$this->assertStringContainsString( '<table>', $out );
	}

	public function test_unknown_element_falls_back_to_html_block(): void {
		$out = $this->serializer->serialise( '<aside>Note</aside>' );
		$this->assertStringContainsString( '<!-- wp:html -->', $out );
		$this->assertStringContainsString( 'Note', $out );
	}

	public function test_multiple_elements_produce_multiple_blocks(): void {
		$out = $this->serializer->serialise( '<h2>Title</h2><p>Body.</p>' );
		$this->assertSame( 1, substr_count( $out, '<!-- wp:heading -->' ) );
		$this->assertSame( 1, substr_count( $out, '<!-- wp:paragraph -->' ) );
		$this->assertLessThan( strpos( $out, '<!-- wp:paragraph -->' ), strpos( $out, '<!-- wp:heading -->' ) );
	}

	public function test_inline_markup_inside_paragraph_is_preserved(): void {
		$out = $this->serializer->serialise( '<p>Some <strong>bold</strong> and <a href="https://example.com">a link</a>.</p>' );
		$this->assertStringContainsString( '<strong>bold</strong>', $out );
		$this->assertStringContainsString( '<a href="https://example.com">a link</a>', $out );
	}

	public function test_unicode_content_survives(): void {
		$out = $this->serializer->serialise( '<p>Smörgåsbord — naïve café 日本語</p>' );
		$this->assertStringContainsString( 'Smörgåsbord', $out );
		$this->assertStringContainsString( 'naïve', $out );
		$this->assertStringContainsString( '日本語', $out );
	}

	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', $this->serializer->serialise( '' ) );
		$this->assertSame( '', $this->serializer->serialise( "  \n  " ) );
	}

	public function test_code_block_keeps_html_entities_escaped(): void {
		$out = $this->serializer->serialise( '<pre><code>&lt;?php echo "&lt;b&gt;bold&lt;/b&gt;"; ?&gt;</code></pre>' );
		$this->assertStringContainsString( '&lt;?php', $out );
		$this->assertStringContainsString( '&lt;b&gt;', $out );
		$this->assertStringNotContainsString( '<?php', $out );
		$this->assertStringNotContainsString( '<b>', $out );
	}

	public function test_ampersand_entity_stays_escaped_in_paragraph(): void {
		$out = $this->serializer->serialise( '<p>Fish &amp; chips &lt;tag&gt;</p>' );
		$this->assertStringContainsString( 'Fish &amp; chips', $out );
		$this->assertStringContainsString( '&lt;tag&gt;', $out );
	}
}

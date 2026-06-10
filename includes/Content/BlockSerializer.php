<?php
/**
 * Converts HTML fragments into Gutenberg block markup.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DOMDocument;
use DOMNode;
use DOMText;
use DOMElement;

/**
 * Maps a flat HTML fragment into Gutenberg block markup.
 *
 * Converts HTML produced by league/commonmark into serialised Gutenberg
 * comment delimiters (<!-- wp:xxx -->…<!-- /wp:xxx -->) so that content
 * can be stored as proper block markup in the WordPress post content field.
 * Pure PHP with no WordPress dependencies; safe to use in CLI or test contexts.
 *
 * @since 1.9.0
 */
class BlockSerializer {

	/**
	 * Serialises an HTML fragment into Gutenberg block markup.
	 *
	 * Returns an empty string for blank or whitespace-only input. Each
	 * top-level element in the fragment becomes exactly one block. Unknown
	 * elements fall back to the wp:html freeform block. Inline markup within
	 * elements is preserved verbatim.
	 *
	 * @since 1.9.0
	 * @param string $html Raw HTML fragment (e.g. output of league/commonmark).
	 * @return string Gutenberg block markup, blocks separated by double newlines.
	 */
	public function serialise( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		$dom = $this->parse_html( $html );

		$blocks = [];

		// With LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD, no <body> wrapper
		// is injected — top-level nodes are direct children of the document.
		foreach ( $dom->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			// Skip the injected <meta charset> and XML processing instructions.
			if ( XML_PI_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}
			if ( $node instanceof DOMElement && 'meta' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			// Skip whitespace-only text nodes at the top level.
			if ( $node instanceof DOMText && '' === trim( $node->nodeValue ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$block = $this->node_to_block( $node, $dom );
			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Parses an HTML fragment into a DOMDocument using UTF-8 encoding.
	 *
	 * A charset meta and XML encoding declaration are prepended so that
	 * DOMDocument treats the input as UTF-8 rather than Latin-1. libxml
	 * warnings for valid HTML5 input (e.g. void elements, unknown attributes)
	 * are suppressed because they are false positives.
	 *
	 * @since 1.9.0
	 * @param string $html Raw HTML fragment.
	 * @return DOMDocument Parsed document.
	 */
	private function parse_html( string $html ): DOMDocument {
		$dom                     = new DOMDocument( '1.0', 'UTF-8' );
		$dom->substituteEntities = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		// Force UTF-8 interpretation; LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		// keeps DOMDocument in fragment mode without injecting html/head/body wrappers
		// when the fragment already has a body context via loadHTML.
		$prefix = '<?xml encoding="utf-8"?><meta charset="utf-8">';

		libxml_use_internal_errors( true );
		$dom->loadHTML(
			$prefix . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		return $dom;
	}

	/**
	 * Converts a single DOM node into a Gutenberg block string.
	 *
	 * Text nodes at the top level are wrapped in wp:paragraph. Element nodes
	 * are dispatched via their tag name. Unknown elements use the wp:html
	 * fallback block.
	 *
	 * @since 1.9.0
	 * @param DOMNode     $node The top-level node to convert.
	 * @param DOMDocument $dom  The owning document (needed for saveHTML).
	 * @return string Gutenberg block markup, or empty string if nothing to emit.
	 */
	private function node_to_block( DOMNode $node, DOMDocument $dom ): string {
		// Bare text at top level becomes a paragraph.
		if ( $node instanceof DOMText ) {
			$text = trim( $node->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( '' === $text ) {
				return '';
			}
			return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
		}

		if ( ! ( $node instanceof DOMElement ) ) {
			return '';
		}

		$tag   = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$inner = $this->inner_html( $node, $dom );

		switch ( $tag ) {
			case 'p':
				return "<!-- wp:paragraph -->\n<p>{$inner}</p>\n<!-- /wp:paragraph -->";

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $this->heading_block( $tag, $inner );

			case 'ul':
				return "<!-- wp:list -->\n<ul class=\"wp-block-list\">{$inner}</ul>\n<!-- /wp:list -->";

			case 'ol':
				return "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">{$inner}</ol>\n<!-- /wp:list -->";

			case 'blockquote':
				return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">{$inner}</blockquote>\n<!-- /wp:quote -->";

			case 'pre':
				return $this->code_block( $inner );

			case 'hr':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'table':
				$table_html = $dom->saveHTML( $node );
				return "<!-- wp:table -->\n<figure class=\"wp-block-table\">{$table_html}</figure>\n<!-- /wp:table -->";

			default:
				$raw = trim( $dom->saveHTML( $node ) );
				if ( '' === $raw ) {
					return '';
				}
				return "<!-- wp:html -->\n{$raw}\n<!-- /wp:html -->";
		}
	}

	/**
	 * Builds a wp:heading block for h1–h6 elements.
	 *
	 * H2 uses the plain `<!-- wp:heading -->` comment because Gutenberg treats
	 * level 2 as the default and omits the attribute when saving. All other
	 * levels carry a `{"level":N}` JSON attribute.
	 *
	 * @since 1.9.0
	 * @param string $tag   Lowercase tag name, e.g. 'h2'.
	 * @param string $inner Inner HTML of the heading element.
	 * @return string Gutenberg heading block markup.
	 */
	private function heading_block( string $tag, string $inner ): string {
		$level = (int) substr( $tag, 1 );

		if ( 2 === $level ) {
			$open = '<!-- wp:heading -->';
		} else {
			$open = "<!-- wp:heading {\"level\":{$level}} -->";
		}

		return "{$open}\n<{$tag} class=\"wp-block-heading\">{$inner}</{$tag}>\n<!-- /wp:heading -->";
	}

	/**
	 * Builds a wp:code block from pre element inner HTML.
	 *
	 * If the inner content does not already begin with a `<code` tag, it is
	 * wrapped in `<code>…</code>` to satisfy Gutenberg's block structure.
	 *
	 * @since 1.9.0
	 * @param string $inner Inner HTML of the pre element.
	 * @return string Gutenberg code block markup.
	 */
	private function code_block( string $inner ): string {
		$content = str_starts_with( ltrim( $inner ), '<code' ) ? $inner : "<code>{$inner}</code>";
		return "<!-- wp:code -->\n<pre class=\"wp-block-code\">{$content}</pre>\n<!-- /wp:code -->";
	}

	/**
	 * Returns the serialised inner HTML of a DOM element.
	 *
	 * Concatenates the saveHTML output of every child node and decodes the
	 * numeric entities DOMDocument uses for multibyte characters back to raw
	 * UTF-8. Named entities (&lt;, &amp;, …) are deliberately left intact:
	 * commonmark escapes code-block content with them, and decoding would
	 * re-introduce live tags that wp_kses_post() then strips — silently
	 * losing any code snippet that documents HTML or PHP.
	 *
	 * @since 1.9.0
	 * @param DOMElement  $element The element whose children to serialise.
	 * @param DOMDocument $dom     The owning document.
	 * @return string Inner HTML with multibyte numeric entities decoded to UTF-8.
	 */
	private function inner_html( DOMElement $element, DOMDocument $dom ): string {
		$html = '';
		foreach ( $element->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$html .= $dom->saveHTML( $child );
		}

		// Decode only numeric entities for code points >= U+0080 (the range
		// DOMDocument entity-encodes); ASCII named entities must stay escaped.
		return mb_decode_numericentity( $html, [ 0x80, 0x10FFFF, 0, 0x1FFFFF ], 'UTF-8' );
	}
}

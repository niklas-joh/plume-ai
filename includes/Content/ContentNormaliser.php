<?php
/**
 * Normalises AI-generated content into Gutenberg block markup.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Converts AI-generated markdown (or plain HTML) into Gutenberg block markup.
 *
 * AI providers return post bodies as markdown regardless of prompting, so
 * every write path must normalise before saving (see issue #727). Content
 * that already contains block delimiters is passed through untouched, which
 * makes the operation idempotent. Sanitisation is NOT this class's responsibility —
 * callers keep their existing wp_kses_post() boundary.
 *
 * @since 1.9.0
 */
class ContentNormaliser {

	/**
	 * Normalise content into Gutenberg block markup.
	 *
	 * Returns empty string for blank input. Returns the input unchanged when
	 * Gutenberg block delimiters are already present (idempotent). Otherwise
	 * converts markdown to HTML via GFM converter, serialises to block markup,
	 * and applies the plume_normalised_content filter.
	 *
	 * @since 1.9.0
	 * @param string $content Raw content from the AI provider (markdown, HTML, or block markup).
	 * @return string Block markup; unchanged input when blocks are already present.
	 */
	public function normalise( string $content ): string {
		if ( '' === trim( $content ) ) {
			return '';
		}

		if ( \has_blocks( $content ) ) {
			return $content;
		}

		$converter = new GithubFlavoredMarkdownConverter(
			[
				// Raw HTML passes through; wp_kses_post() at the call site remains the security boundary.
				'html_input'         => 'allow',
				'allow_unsafe_links' => false,
			]
		);

		$html   = $converter->convert( $content )->getContent();
		$blocks = ( new BlockSerializer() )->serialise( $html );

		/**
		 * Filter the normalised block markup before it is saved.
		 *
		 * @since 1.9.0
		 * @param string $blocks  Generated Gutenberg block markup.
		 * @param string $content Original raw AI content.
		 */
		return \apply_filters( 'plume_normalised_content', $blocks, $content );
	}
}

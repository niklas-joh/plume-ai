import { marked } from 'marked';
import DOMPurify from 'dompurify';
import { htmlToText } from './htmlToText';

// Match MarkdownContent's marked config so the plan summary and the diff parse
// the same Markdown identically (line breaks, GFM tables).
marked.setOptions( { breaks: true, gfm: true } );

/**
 * Paragraph-level diff between two text strings.
 *
 * Normalises both sides to a common HTML representation before diffing:
 * the old text (WordPress block markup) has block comment delimiters stripped;
 * the new text (Markdown from the AI plan) is converted to HTML via `marked`
 * and sanitised with DOMPurify. Each top-level block element becomes one unit
 * in the LCS comparison.
 *
 * @param {string} oldText  Current post content (raw WordPress block markup).
 * @param {string} newText  Proposed post content from the AI plan (Markdown).
 * @return {Array<{id: string, unchanged: string[], removedText: string|null, addedText: string|null}>}
 */
export function computeDiff( oldText, newText ) {
	// Sanitise both sides: every block string flows untouched into DiffView's
	// dangerouslySetInnerHTML. The old (post-content) side can carry markup
	// authored by a lower-privileged contributor, and marked passes raw HTML in
	// the AI plan through unescaped — either could relay a stored XSS payload
	// into wp-admin without this.
	const oldHtml = DOMPurify.sanitize( stripBlockMarkup( oldText ) );
	const newHtml = DOMPurify.sanitize( marked.parse( newText ) );
	const oldBlocks = htmlToBlocks( oldHtml );
	const newBlocks = htmlToBlocks( newHtml );
	const ops = lcs( oldBlocks, newBlocks );
	return groupOps( ops );
}

// ---------------------------------------------------------------------------
// Normalisation helpers
// ---------------------------------------------------------------------------

/**
 * Strips WordPress block comment delimiters, leaving clean inner HTML.
 *
 * @param {string} raw  Raw WordPress post content.
 * @return {string}
 */
function stripBlockMarkup( raw ) {
	// `[^>]*?` stops at the first `>`, so a delimiter whose JSON attributes
	// contain a literal `>` (rare for core blocks) would not be fully stripped.
	return raw.replace( /<!--\s*\/?wp:[^>]*?-->/g, '' ).trim();
}

/**
 * Splits an HTML string into individual block-level elements.
 *
 * Uses the DOM when available (browser environment) so each `<p>`, `<h2>`,
 * `<ul>`, etc. becomes one diffable unit. Falls back to double-newline
 * splitting for server-side or test environments.
 *
 * When the DOM finds no child elements (e.g. plain-text content with stripped
 * block delimiters), falls back to the double-newline split so the LCS still
 * receives segments rather than treating the whole text as one block.
 *
 * @param {string} html  HTML string to split.
 * @return {string[]}    Array of `outerHTML` strings (or plain-text segments).
 */
function htmlToBlocks( html ) {
	if ( typeof document === 'undefined' ) {
		return html
			.split( /\n\n+/ )
			.map( ( p ) => p.trim() )
			.filter( Boolean );
	}
	const el = document.createElement( 'div' );
	el.innerHTML = html;
	const blocks = Array.from( el.children )
		.map( ( node ) => node.outerHTML )
		.filter( Boolean );
	// Plain-text content (e.g. WP content with block delimiters stripped but no
	// wrapping elements) has no element children — fall back to paragraph splitting
	// so the LCS receives meaningful segments rather than one large unchanged block.
	if ( blocks.length === 0 && html.trim() ) {
		return html
			.split( /\n\n+/ )
			.map( ( p ) => p.trim() )
			.filter( Boolean );
	}
	return blocks;
}

/**
 * Reduces an HTML block to a normalised tag+text key for LCS equality checks.
 *
 * Compares by tag name and text content, ignoring attributes (e.g. WP adds
 * class="wp-block-heading" that Markdown output lacks). `<p>` elements are
 * treated as equivalent to unwrapped plain-text segments so that old WP content
 * (stripped of block delimiters, no `<p>` wrapper) still matches the Markdown
 * output which always wraps paragraph text in `<p>` tags.
 *
 * @param {string} html  outerHTML of a single block element, or plain text.
 * @return {string}
 */
function normalizeForComparison( html ) {
	if ( typeof document !== 'undefined' ) {
		const text = htmlToText( html )
			.replace( /\s+/g, ' ' )
			.trim()
			.toLowerCase();
		// Tag-name extraction (not tag stripping) to keep the comparison
		// element-aware; htmlToBlocks yields one block per call so the leading
		// tag is the block's own element.
		const tagMatch = html.match( /^\s*<([a-z0-9-]+)/i );
		const tag = tagMatch ? tagMatch[ 1 ].toLowerCase() : '';
		// Paragraph elements (and unwrapped plain text) are semantically
		// equivalent — omit the tag prefix so pre-HTML WP content matches
		// marked's <p>-wrapped Markdown output.
		if ( ! tag || tag === 'p' ) {
			return text;
		}
		return `${ tag }:${ text }`;
	}
	// SSR/test fallback: normalise whitespace only. Regex tag-stripping patterns
	// (/<[^>]*>/g) are intentionally avoided — static analysis tools flag them as
	// incomplete multi-character sanitisation (CodeQL rule: js/incomplete-html-tag-sanitization).
	return html.replace( /\s+/g, ' ' ).trim().toLowerCase();
}

// ---------------------------------------------------------------------------
// Internal diff helpers
// ---------------------------------------------------------------------------

/**
 * Compute edit ops via LCS.
 * Returns an array of `{ type: 'equal'|'remove'|'add', text: string }`.
 *
 * Normalised keys are pre-computed once before the DP loop to avoid
 * O(m·n) repeated DOM-parse calls inside `normalizeForComparison`.
 *
 * @param {string[]} oldParas
 * @param {string[]} newParas
 * @return {Array<{type: string, text: string}>}
 */
function lcs( oldParas, newParas ) {
	const m = oldParas.length;
	const n = newParas.length;

	// Pre-compute normalised keys to avoid repeated DOM parsing per DP cell.
	const oldKeys = oldParas.map( normalizeForComparison );
	const newKeys = newParas.map( normalizeForComparison );

	// Build LCS table.
	const dp = Array.from( { length: m + 1 }, () =>
		new Array( n + 1 ).fill( 0 )
	);
	for ( let i = 1; i <= m; i++ ) {
		for ( let j = 1; j <= n; j++ ) {
			dp[ i ][ j ] =
				oldKeys[ i - 1 ] === newKeys[ j - 1 ]
					? dp[ i - 1 ][ j - 1 ] + 1
					: Math.max( dp[ i - 1 ][ j ], dp[ i ][ j - 1 ] );
		}
	}

	// Backtrack to extract edit sequence.
	const ops = [];
	let i = m;
	let j = n;
	while ( i > 0 || j > 0 ) {
		if ( i > 0 && j > 0 && oldKeys[ i - 1 ] === newKeys[ j - 1 ] ) {
			ops.unshift( { type: 'equal', text: oldParas[ i - 1 ] } );
			i--;
			j--;
		} else if (
			j > 0 &&
			( i === 0 || dp[ i ][ j - 1 ] >= dp[ i - 1 ][ j ] )
		) {
			ops.unshift( { type: 'add', text: newParas[ j - 1 ] } );
			j--;
		} else {
			ops.unshift( { type: 'remove', text: oldParas[ i - 1 ] } );
			i--;
		}
	}
	return ops;
}

/**
 * Group a flat ops list into DiffBlock objects.
 *
 * Each DiffBlock collects leading unchanged paragraphs plus one
 * remove+add pair (either may be null for pure insertions/deletions).
 * A trailing run of equal ops becomes a final unchanged-only block.
 *
 * The block counter is scoped per call so ids are stable within a single
 * diff but never imply continuity across separate `computeDiff` invocations.
 *
 * @param {Array<{type: string, text: string}>} ops
 * @return {Array<{id: string, unchanged: string[], removedText: string|null, addedText: string|null}>}
 */
function groupOps( ops ) {
	const blocks = [];
	let pendingUnchanged = [];
	let blockCounter = 0;

	let idx = 0;
	while ( idx < ops.length ) {
		const op = ops[ idx ];

		if ( op.type === 'equal' ) {
			pendingUnchanged.push( op.text );
			idx++;
			continue;
		}

		// Start a new diff block.
		const block = {
			id: `diff-${ ++blockCounter }`,
			unchanged: pendingUnchanged,
			removedText: null,
			addedText: null,
		};
		pendingUnchanged = [];

		if ( op.type === 'remove' ) {
			block.removedText = op.text;
			idx++;
			// Pair with an immediately following 'add' if present.
			if ( idx < ops.length && ops[ idx ].type === 'add' ) {
				block.addedText = ops[ idx ].text;
				idx++;
			}
		} else {
			// Pure insertion (no preceding remove).
			block.addedText = op.text;
			idx++;
		}

		blocks.push( block );
	}

	// Any trailing unchanged paragraphs form a final block.
	if ( pendingUnchanged.length > 0 ) {
		blocks.push( {
			id: `diff-${ ++blockCounter }`,
			unchanged: pendingUnchanged,
			removedText: null,
			addedText: null,
		} );
	}

	return blocks;
}

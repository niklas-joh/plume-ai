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
 * @return {Array<{id: string, unchanged: string[], removedText: string|null, addedText: string|null, inlineHtml: string|null}>}
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
// Word-level (intra-block) diff
// ---------------------------------------------------------------------------

// Below this similarity the two blocks are effectively unrelated (a genuine
// rewrite), so an inline word diff would be noise — fall back to before/after.
const INLINE_SIMILARITY_THRESHOLD = 0.4;

// Block tags whose text is prose enough to word-diff meaningfully. Lists,
// tables, quotes and preformatted blocks are left to the before/after layout.
const INLINE_DIFF_TAGS = [ 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ];

/**
 * Extract the leading block tag name from an outerHTML string.
 *
 * @param {string} html  outerHTML of a single block, or plain text.
 * @return {string}      Lowercased tag name, or '' for unwrapped text.
 */
function blockTag( html ) {
	const match = html.match( /^\s*<([a-z0-9-]+)/i );
	return match ? match[ 1 ].toLowerCase() : '';
}

/**
 * HTML-escape a plain-text token before it is wrapped in del/ins markup.
 *
 * @param {string} text
 * @return {string}
 */
function escapeHtml( text ) {
	return text
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}

/**
 * Build a word-level inline diff between a removed and an added block.
 *
 * Returns a single sanitised HTML string in which unchanged words render
 * plainly, deleted words are wrapped in `<del>` and inserted words in `<ins>`,
 * so a one-word edit reads as one struck word plus one new word rather than two
 * whole paragraphs. Returns `null` when an inline diff would not be meaningful:
 * non-prose blocks (lists, tables…), mismatched block tags, or a similarity
 * below the threshold (a genuine rewrite), leaving the before/after layout.
 *
 * @param {string} removedHtml  outerHTML of the removed block.
 * @param {string} addedHtml    outerHTML of the added block.
 * @return {string|null}
 */
function computeInlineDiff( removedHtml, addedHtml ) {
	const oldTag = blockTag( removedHtml );
	const newTag = blockTag( addedHtml );

	// Only inline-diff prose blocks, and only when both sides are the same kind
	// of block (a paragraph turning into a heading is a structural change).
	const tagOk = ( t ) =>
		t === '' || t === 'p' || INLINE_DIFF_TAGS.includes( t );
	if ( ! tagOk( oldTag ) || ! tagOk( newTag ) ) {
		return null;
	}
	const oldIsPara = oldTag === '' || oldTag === 'p';
	const newIsPara = newTag === '' || newTag === 'p';
	if ( ! oldIsPara && ! newIsPara && oldTag !== newTag ) {
		return null;
	}

	const oldTokens = htmlToText( removedHtml )
		.trim()
		.split( /\s+/ )
		.filter( Boolean );
	const newTokens = htmlToText( addedHtml )
		.trim()
		.split( /\s+/ )
		.filter( Boolean );
	if ( oldTokens.length === 0 || newTokens.length === 0 ) {
		return null;
	}

	const ops = lcsTokens( oldTokens, newTokens );
	const equalCount = ops.filter( ( op ) => op.type === 'equal' ).length;
	const similarity =
		( 2 * equalCount ) / ( oldTokens.length + newTokens.length );
	if ( similarity < INLINE_SIMILARITY_THRESHOLD ) {
		return null;
	}

	// Coalesce consecutive same-type ops so runs of changed words share one
	// del/ins wrapper rather than one per word.
	const parts = [];
	let run = null;
	const flush = () => {
		if ( ! run ) {
			return;
		}
		const text = escapeHtml( run.words.join( ' ' ) );
		if ( run.type === 'equal' ) {
			parts.push( text );
		} else if ( run.type === 'remove' ) {
			parts.push( `<del class="plume-diff-del">${ text }</del>` );
		} else {
			parts.push( `<ins class="plume-diff-ins">${ text }</ins>` );
		}
		run = null;
	};
	for ( const op of ops ) {
		if ( ! run || run.type !== op.type ) {
			flush();
			run = { type: op.type, words: [] };
		}
		run.words.push( op.text );
	}
	flush();

	const inner = parts.join( ' ' );
	const tag = newIsPara ? 'p' : newTag;
	const html = `<${ tag }>${ inner }</${ tag }>`;

	return DOMPurify.sanitize( html, {
		ALLOWED_TAGS: [ ...INLINE_DIFF_TAGS, 'del', 'ins' ],
		ALLOWED_ATTR: [ 'class' ],
	} );
}

/**
 * Word-level LCS over two token arrays.
 *
 * Mirrors `lcs()` but compares tokens directly (case-insensitive) and carries
 * the original token text through so casing is preserved in the output.
 *
 * @param {string[]} oldTokens
 * @param {string[]} newTokens
 * @return {Array<{type: string, text: string}>}
 */
function lcsTokens( oldTokens, newTokens ) {
	const m = oldTokens.length;
	const n = newTokens.length;
	const oldKeys = oldTokens.map( ( t ) => t.toLowerCase() );
	const newKeys = newTokens.map( ( t ) => t.toLowerCase() );

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

	const ops = [];
	let i = m;
	let j = n;
	while ( i > 0 || j > 0 ) {
		if ( i > 0 && j > 0 && oldKeys[ i - 1 ] === newKeys[ j - 1 ] ) {
			ops.unshift( { type: 'equal', text: oldTokens[ i - 1 ] } );
			i--;
			j--;
		} else if (
			j > 0 &&
			( i === 0 || dp[ i ][ j - 1 ] >= dp[ i - 1 ][ j ] )
		) {
			ops.unshift( { type: 'add', text: newTokens[ j - 1 ] } );
			j--;
		} else {
			ops.unshift( { type: 'remove', text: oldTokens[ i - 1 ] } );
			i--;
		}
	}
	return ops;
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
 * Each change region (a maximal run of non-equal ops between two unchanged
 * anchors) is split into its removed and added paragraphs, then paired
 * positionally — removed paragraph N with added paragraph N — so a lightly
 * edited paragraph pairs with its counterpart and gets a word-level inline
 * diff, instead of a whole run of removals followed by a whole run of
 * additions (which is how the block-level LCS emits a region with no identical
 * paragraphs to anchor on). Leftover removals/additions become pure
 * deletions/insertions. A trailing run of equal ops becomes a final
 * unchanged-only block.
 *
 * The block counter is scoped per call so ids are stable within a single
 * diff but never imply continuity across separate `computeDiff` invocations.
 *
 * @param {Array<{type: string, text: string}>} ops
 * @return {Array<{id: string, unchanged: string[], removedText: string|null, addedText: string|null, inlineHtml: string|null}>}
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

		// Gather the whole change region (removes and adds keep document order
		// within their own list) up to the next unchanged anchor.
		const removes = [];
		const adds = [];
		while ( idx < ops.length && ops[ idx ].type !== 'equal' ) {
			if ( ops[ idx ].type === 'remove' ) {
				removes.push( ops[ idx ].text );
			} else {
				adds.push( ops[ idx ].text );
			}
			idx++;
		}

		// Pair removed↔added paragraphs by position; the first block of the
		// region carries the leading unchanged context, the rest carry none.
		const pairCount = Math.max( removes.length, adds.length );
		for ( let k = 0; k < pairCount; k++ ) {
			const removedText = k < removes.length ? removes[ k ] : null;
			const addedText = k < adds.length ? adds[ k ] : null;
			blocks.push( {
				id: `diff-${ ++blockCounter }`,
				unchanged: k === 0 ? pendingUnchanged : [],
				removedText,
				addedText,
				// A modified (not wholly replaced) paragraph gets a word-level
				// inline diff so a small edit doesn't read as two whole paragraphs.
				inlineHtml:
					removedText && addedText
						? computeInlineDiff( removedText, addedText )
						: null,
			} );
		}
		pendingUnchanged = [];
	}

	// Any trailing unchanged paragraphs form a final block.
	if ( pendingUnchanged.length > 0 ) {
		blocks.push( {
			id: `diff-${ ++blockCounter }`,
			unchanged: pendingUnchanged,
			removedText: null,
			addedText: null,
			inlineHtml: null,
		} );
	}

	return blocks;
}

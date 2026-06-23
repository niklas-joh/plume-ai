import { marked } from 'marked';

/**
 * Paragraph-level diff between two text strings.
 *
 * Normalises both sides to a common HTML representation before diffing:
 * the old text (WordPress block markup) has block comment delimiters stripped;
 * the new text (Markdown from the AI plan) is converted to HTML via `marked`.
 * Each top-level block element becomes one unit in the LCS comparison.
 *
 * @param {string} oldText  Current post content (raw WordPress block markup).
 * @param {string} newText  Proposed post content from the AI plan (Markdown).
 * @return {Array<{id: string, unchanged: string[], removedText: string|null, addedText: string|null}>}
 */
export function computeDiff( oldText, newText ) {
	const oldHtml = stripBlockMarkup( oldText );
	const newHtml = marked.parse( newText );
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
	return raw.replace( /<!--\s*\/?wp:[^>]*?-->/g, '' ).trim();
}

/**
 * Splits an HTML string into individual block-level elements.
 *
 * Uses the DOM when available (browser environment) so each `<p>`, `<h2>`,
 * `<ul>`, etc. becomes one diffable unit. Falls back to double-newline
 * splitting for server-side or test environments.
 *
 * @param {string} html  HTML string to split.
 * @return {string[]}    Array of `outerHTML` strings for each block element.
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
	return Array.from( el.children )
		.map( ( node ) => node.outerHTML )
		.filter( Boolean );
}

/**
 * Reduces an HTML block to a normalised tag+text key for LCS equality checks.
 *
 * Compares by tag name and text content, ignoring attributes (e.g. WP adds
 * class="wp-block-heading" that Markdown output lacks). Two blocks are
 * considered equal when they represent the same semantic element with the
 * same text.
 *
 * @param {string} html  outerHTML of a single block element.
 * @return {string}
 */
function normalizeForComparison( html ) {
	if ( typeof document !== 'undefined' ) {
		const div = document.createElement( 'div' );
		div.innerHTML = html;
		const el = div.firstElementChild;
		if ( el ) {
			const tag = el.tagName.toLowerCase();
			const text = el.textContent
				.replace( /\s+/g, ' ' )
				.trim()
				.toLowerCase();
			return `${ tag }:${ text }`;
		}
		return div.textContent.replace( /\s+/g, ' ' ).trim().toLowerCase();
	}
	return html
		.replace( /<[^>]*>/g, '' )
		.replace( /\s+/g, ' ' )
		.trim()
		.toLowerCase();
}

// ---------------------------------------------------------------------------
// Internal diff helpers
// ---------------------------------------------------------------------------

/**
 * Compute edit ops via LCS.
 * Returns an array of `{ type: 'equal'|'remove'|'add', text: string }`.
 *
 * @param {string[]} oldParas
 * @param {string[]} newParas
 * @return {Array<{type: string, text: string}>}
 */
function lcs( oldParas, newParas ) {
	const m = oldParas.length;
	const n = newParas.length;

	// Build LCS table.
	const dp = Array.from( { length: m + 1 }, () =>
		new Array( n + 1 ).fill( 0 )
	);
	for ( let i = 1; i <= m; i++ ) {
		for ( let j = 1; j <= n; j++ ) {
			dp[ i ][ j ] =
				normalizeForComparison( oldParas[ i - 1 ] ) ===
				normalizeForComparison( newParas[ j - 1 ] )
					? dp[ i - 1 ][ j - 1 ] + 1
					: Math.max( dp[ i - 1 ][ j ], dp[ i ][ j - 1 ] );
		}
	}

	// Backtrack to extract edit sequence.
	const ops = [];
	let i = m;
	let j = n;
	while ( i > 0 || j > 0 ) {
		if (
			i > 0 &&
			j > 0 &&
			normalizeForComparison( oldParas[ i - 1 ] ) ===
				normalizeForComparison( newParas[ j - 1 ] )
		) {
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

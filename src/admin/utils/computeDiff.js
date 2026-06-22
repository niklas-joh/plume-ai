/**
 * Paragraph-level diff between two text strings.
 *
 * Splits both texts on double-newline boundaries (matching WordPress block
 * editor paragraph separators), runs an LCS algorithm, then groups consecutive
 * removed+added pairs into DiffBlock objects suitable for the ReviewDrawer.
 *
 * @param {string} oldText  Current post content (raw, may contain HTML).
 * @param {string} newText  Proposed post content from the AI plan.
 * @return {Array<{id: string, unchanged: string[], removedText: string|null, addedText: string|null}>}
 */
export function computeDiff( oldText, newText ) {
	const oldParas = splitParagraphs( oldText );
	const newParas = splitParagraphs( newText );

	const ops = lcs( oldParas, newParas );
	return groupOps( ops );
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

function splitParagraphs( text ) {
	return text
		.split( /\n\n+/ )
		.map( ( p ) => p.trim() )
		.filter( Boolean );
}

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
				oldParas[ i - 1 ] === newParas[ j - 1 ]
					? dp[ i - 1 ][ j - 1 ] + 1
					: Math.max( dp[ i - 1 ][ j ], dp[ i ][ j - 1 ] );
		}
	}

	// Backtrack to extract edit sequence.
	const ops = [];
	let i = m;
	let j = n;
	while ( i > 0 || j > 0 ) {
		if ( i > 0 && j > 0 && oldParas[ i - 1 ] === newParas[ j - 1 ] ) {
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

let blockCounter = 0;

/**
 * Group a flat ops list into DiffBlock objects.
 *
 * Each DiffBlock collects leading unchanged paragraphs plus one
 * remove+add pair (either may be null for pure insertions/deletions).
 * A trailing run of equal ops becomes a final unchanged-only block.
 *
 * @param {Array<{type: string, text: string}>} ops
 * @return {Array<{id: string, unchanged: string[], removedText: string|null, addedText: string|null}>}
 */
function groupOps( ops ) {
	const blocks = [];
	let pendingUnchanged = [];

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

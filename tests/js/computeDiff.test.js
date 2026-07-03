/**
 * Unit tests for the paragraph-level diff utility.
 *
 * computeDiff is pure and deterministic, but the internal block ids are only
 * used as React keys and are not part of the public contract — every
 * assertion below targets block *shape and content*, never the `id` string.
 *
 * Output format notes:
 * - `unchanged` and `removedText` carry the *old-side* representation (plain
 *   text or raw WP block HTML), because the LCS equal/remove ops use oldParas.
 * - `addedText` carries the *new-side* representation, which is always HTML
 *   produced by marked.parse() — plain text like "B" becomes "<p>B</p>".
 *
 * @see src/admin/utils/computeDiff.js
 */
import { computeDiff } from '../../src/admin/utils/computeDiff';

/**
 * Count blocks that represent an actual change (a removal and/or an addition).
 *
 * @param {Array} blocks computeDiff output.
 * @return {number} Number of changed blocks.
 */
function changedBlocks( blocks ) {
	return blocks.filter( ( b ) => b.removedText || b.addedText );
}

describe( 'computeDiff', () => {
	it( 'returns a single unchanged block for identical input', () => {
		const blocks = computeDiff( 'A\n\nB', 'A\n\nB' );

		expect( blocks ).toHaveLength( 1 );
		// Old-side plain text is preserved as-is in unchanged segments.
		expect( blocks[ 0 ].unchanged ).toEqual( [ 'A', 'B' ] );
		expect( blocks[ 0 ].removedText ).toBeNull();
		expect( blocks[ 0 ].addedText ).toBeNull();
		expect( changedBlocks( blocks ) ).toHaveLength( 0 );
	} );

	it( 'reports a pure insertion as addedText only', () => {
		const blocks = computeDiff( 'A', 'A\n\nB' );

		const changes = changedBlocks( blocks );
		expect( changes ).toHaveLength( 1 );
		// marked wraps new Markdown paragraphs in <p> tags.
		expect( changes[ 0 ].addedText ).toBe( '<p>B</p>' );
		expect( changes[ 0 ].removedText ).toBeNull();
		// The shared leading paragraph is carried as unchanged context.
		expect( changes[ 0 ].unchanged ).toEqual( [ 'A' ] );
	} );

	it( 'reports a pure deletion as removedText only', () => {
		const blocks = computeDiff( 'A\n\nB', 'A' );

		const changes = changedBlocks( blocks );
		expect( changes ).toHaveLength( 1 );
		// Removed text comes from the old side — plain text is preserved.
		expect( changes[ 0 ].removedText ).toBe( 'B' );
		expect( changes[ 0 ].addedText ).toBeNull();
		expect( changes[ 0 ].unchanged ).toEqual( [ 'A' ] );
	} );

	it( 'groups a replacement into a single remove+add block', () => {
		const blocks = computeDiff( 'A', 'B' );

		expect( blocks ).toHaveLength( 1 );
		expect( blocks[ 0 ].removedText ).toBe( 'A' );
		// New-side text is HTML-wrapped by marked.
		expect( blocks[ 0 ].addedText ).toBe( '<p>B</p>' );
		expect( blocks[ 0 ].unchanged ).toEqual( [] );
	} );

	it( 'keeps leading and trailing unchanged paragraphs grouped correctly', () => {
		const blocks = computeDiff(
			'Intro\n\nOld middle\n\nOutro',
			'Intro\n\nNew middle\n\nOutro'
		);

		// First block: leading "Intro" context + the middle replacement.
		expect( blocks[ 0 ].unchanged ).toEqual( [ 'Intro' ] );
		expect( blocks[ 0 ].removedText ).toBe( 'Old middle' );
		// New middle comes from the new side — marked wraps it in <p>.
		expect( blocks[ 0 ].addedText ).toBe( '<p>New middle</p>' );

		// Final block: trailing "Outro" carried as an unchanged-only block.
		const last = blocks[ blocks.length - 1 ];
		expect( last.unchanged ).toEqual( [ 'Outro' ] );
		expect( last.removedText ).toBeNull();
		expect( last.addedText ).toBeNull();

		expect( changedBlocks( blocks ) ).toHaveLength( 1 );
	} );

	it( 'treats empty oldText as all additions', () => {
		const blocks = computeDiff( '', 'A\n\nB' );

		const changes = changedBlocks( blocks );
		expect( changes ).toHaveLength( 2 );
		// All additions come from the new side — marked wraps them in <p>.
		expect( changes.map( ( b ) => b.addedText ) ).toEqual( [
			'<p>A</p>',
			'<p>B</p>',
		] );
		changes.forEach( ( b ) => expect( b.removedText ).toBeNull() );
	} );

	it( 'treats empty newText as all removals', () => {
		const blocks = computeDiff( 'A\n\nB', '' );

		const changes = changedBlocks( blocks );
		expect( changes ).toHaveLength( 2 );
		expect( changes.map( ( b ) => b.removedText ) ).toEqual( [ 'A', 'B' ] );
		changes.forEach( ( b ) => expect( b.addedText ).toBeNull() );
	} );

	it( 'returns an empty array when both inputs are empty', () => {
		expect( computeDiff( '', '' ) ).toEqual( [] );
	} );

	it( 'filters whitespace-only paragraphs before diffing', () => {
		// The blank middle paragraph is trimmed away, so the two sides are
		// identical at the paragraph level — no change is reported.
		const blocks = computeDiff( 'A\n\n   \n\nB', 'A\n\nB' );

		expect( changedBlocks( blocks ) ).toHaveLength( 0 );
		expect( blocks[ 0 ].unchanged ).toEqual( [ 'A', 'B' ] );
	} );

	describe( 'inline (word-level) diff', () => {
		/**
		 * Count non-overlapping matches of a pattern in a string.
		 *
		 * @param {string} str
		 * @param {RegExp} re Must be a global regex.
		 * @return {number}
		 */
		function countMatches( str, re ) {
			return ( str.match( re ) ?? [] ).length;
		}

		it( 'wraps only the changed word in del/ins for a small edit', () => {
			const blocks = computeDiff(
				'The quick brown fox jumps',
				'The quick red fox jumps'
			);
			const change = changedBlocks( blocks )[ 0 ];

			expect( change.inlineHtml ).not.toBeNull();
			// Exactly one deletion and one insertion, around the single changed word.
			expect(
				countMatches( change.inlineHtml, /<del\b[^>]*>/g )
			).toBe( 1 );
			expect(
				countMatches( change.inlineHtml, /<ins\b[^>]*>/g )
			).toBe( 1 );
			expect( change.inlineHtml ).toMatch(
				/<del[^>]*>brown<\/del>/
			);
			expect( change.inlineHtml ).toMatch( /<ins[^>]*>red<\/ins>/ );
			// Unchanged words stay outside any del/ins wrapper.
			expect( change.inlineHtml ).toMatch( /The quick /);
			expect( change.inlineHtml ).toMatch( / fox jumps/ );
		} );

		it( 'produces no inline diff for a genuine rewrite (low similarity)', () => {
			const blocks = computeDiff(
				'The quick brown fox',
				'Completely different sentence entirely'
			);
			const change = changedBlocks( blocks )[ 0 ];

			// Falls back to the before/after block layout.
			expect( change.inlineHtml ).toBeNull();
			expect( change.removedText ).toBe( 'The quick brown fox' );
			expect( change.addedText ).toBe(
				'<p>Completely different sentence entirely</p>'
			);
		} );

		it( 'inline-diffs a heading, preserving the heading tag', () => {
			const blocks = computeDiff(
				'<h2>Our Old Heading Here</h2>',
				'## Our New Heading Here'
			);
			const change = changedBlocks( blocks )[ 0 ];

			expect( change.inlineHtml ).not.toBeNull();
			expect( change.inlineHtml ).toMatch( /^<h2>/ );
			expect( change.inlineHtml ).toMatch( /<del[^>]*>Old<\/del>/ );
			expect( change.inlineHtml ).toMatch( /<ins[^>]*>New<\/ins>/ );
		} );

		it( 'pairs consecutive lightly-edited paragraphs and inline-diffs each', () => {
			// Two adjacent paragraphs both change, with no identical paragraph
			// between them — the block LCS emits all removals then all additions,
			// so groupOps must pair them positionally for the inline diff to fire.
			const oldText =
				'We can scan many candidate profiles quickly today.\n\n' +
				'You will learn the basics of fairness audits now.';
			const newText =
				'We can scan many candidate profiles rapidly today.\n\n' +
				'You will learn the basics of fairness audits soon.';

			const changes = changedBlocks( computeDiff( oldText, newText ) );

			expect( changes ).toHaveLength( 2 );
			changes.forEach( ( c ) => {
				expect( c.inlineHtml ).not.toBeNull();
				expect( c.inlineHtml ).toMatch( /<del/ );
				expect( c.inlineHtml ).toMatch( /<ins/ );
			} );
			// Each paragraph paired with its own counterpart, not the other one.
			expect( changes[ 0 ].inlineHtml ).toMatch(
				/<del[^>]*>quickly<\/del>/
			);
			expect( changes[ 0 ].inlineHtml ).toMatch(
				/<ins[^>]*>rapidly<\/ins>/
			);
			// Tokens split on whitespace, so trailing punctuation rides along.
			expect( changes[ 1 ].inlineHtml ).toMatch(
				/<del[^>]*>now\.<\/del>/
			);
			expect( changes[ 1 ].inlineHtml ).toMatch(
				/<ins[^>]*>soon\.<\/ins>/
			);
		} );

		it( 'does not inline-diff list blocks (falls back to before/after)', () => {
			const blocks = computeDiff(
				'<ul><li>one item</li><li>two item</li></ul>',
				'- one item\n- three item'
			);
			const change = changedBlocks( blocks )[ 0 ];

			expect( change.inlineHtml ).toBeNull();
			expect( change.removedText ).toContain( '<ul>' );
			expect( change.addedText ).toContain( '<ul>' );
		} );
	} );
} );

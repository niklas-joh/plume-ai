/**
 * Unit tests for the paragraph-level diff utility.
 *
 * computeDiff is pure and deterministic, but the internal block ids are only
 * used as React keys and are not part of the public contract — every
 * assertion below targets block *shape and content*, never the `id` string.
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
		expect( blocks[ 0 ].unchanged ).toEqual( [ 'A', 'B' ] );
		expect( blocks[ 0 ].removedText ).toBeNull();
		expect( blocks[ 0 ].addedText ).toBeNull();
		expect( changedBlocks( blocks ) ).toHaveLength( 0 );
	} );

	it( 'reports a pure insertion as addedText only', () => {
		const blocks = computeDiff( 'A', 'A\n\nB' );

		const changes = changedBlocks( blocks );
		expect( changes ).toHaveLength( 1 );
		expect( changes[ 0 ].addedText ).toBe( 'B' );
		expect( changes[ 0 ].removedText ).toBeNull();
		// The shared leading paragraph is carried as unchanged context.
		expect( changes[ 0 ].unchanged ).toEqual( [ 'A' ] );
	} );

	it( 'reports a pure deletion as removedText only', () => {
		const blocks = computeDiff( 'A\n\nB', 'A' );

		const changes = changedBlocks( blocks );
		expect( changes ).toHaveLength( 1 );
		expect( changes[ 0 ].removedText ).toBe( 'B' );
		expect( changes[ 0 ].addedText ).toBeNull();
		expect( changes[ 0 ].unchanged ).toEqual( [ 'A' ] );
	} );

	it( 'groups a replacement into a single remove+add block', () => {
		const blocks = computeDiff( 'A', 'B' );

		expect( blocks ).toHaveLength( 1 );
		expect( blocks[ 0 ].removedText ).toBe( 'A' );
		expect( blocks[ 0 ].addedText ).toBe( 'B' );
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
		expect( blocks[ 0 ].addedText ).toBe( 'New middle' );

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
		expect( changes.map( ( b ) => b.addedText ) ).toEqual( [ 'A', 'B' ] );
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
} );

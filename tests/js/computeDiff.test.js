/**
 * Unit tests for the paragraph-level LCS diff utility.
 *
 * @see src/admin/utils/computeDiff.js
 */
import { computeDiff } from '../../src/admin/utils/computeDiff';

describe( 'computeDiff', () => {
	it( 'returns a single unchanged block for identical text', () => {
		const text = 'Hello world.\n\nSecond paragraph.';
		const blocks = computeDiff( text, text );

		expect( blocks ).toHaveLength( 1 );
		expect( blocks[ 0 ].unchanged ).toEqual( [
			'Hello world.',
			'Second paragraph.',
		] );
		expect( blocks[ 0 ].removedText ).toBeNull();
		expect( blocks[ 0 ].addedText ).toBeNull();
	} );

	it( 'returns a remove+add pair for a single changed paragraph', () => {
		const oldText = 'First paragraph.\n\nSecond paragraph.';
		const newText = 'First paragraph.\n\nRevised second paragraph.';
		const blocks = computeDiff( oldText, newText );

		const changed = blocks.find( ( b ) => b.removedText || b.addedText );
		expect( changed ).toBeDefined();
		expect( changed.removedText ).toBe( 'Second paragraph.' );
		expect( changed.addedText ).toBe( 'Revised second paragraph.' );
	} );

	it( 'returns unchanged leading paragraphs attached to the changed block', () => {
		const oldText = 'Intro.\n\nOld body.';
		const newText = 'Intro.\n\nNew body.';
		const blocks = computeDiff( oldText, newText );

		const changedBlock = blocks.find( ( b ) => b.removedText );
		expect( changedBlock.unchanged ).toContain( 'Intro.' );
	} );

	it( 'handles pure insertion (no preceding removal)', () => {
		const oldText = 'Only paragraph.';
		const newText = 'Only paragraph.\n\nBrand new paragraph.';
		const blocks = computeDiff( oldText, newText );

		const inserted = blocks.find( ( b ) => b.addedText && ! b.removedText );
		expect( inserted ).toBeDefined();
		expect( inserted.addedText ).toBe( 'Brand new paragraph.' );
	} );

	it( 'handles pure deletion (no following addition)', () => {
		const oldText = 'Keep this.\n\nDelete this.';
		const newText = 'Keep this.';
		const blocks = computeDiff( oldText, newText );

		const deleted = blocks.find( ( b ) => b.removedText && ! b.addedText );
		expect( deleted ).toBeDefined();
		expect( deleted.removedText ).toBe( 'Delete this.' );
	} );

	it( 'handles empty old text (everything is an insertion)', () => {
		const blocks = computeDiff( '', 'New content.' );
		const inserted = blocks.find( ( b ) => b.addedText );
		expect( inserted ).toBeDefined();
		expect( inserted.addedText ).toBe( 'New content.' );
	} );

	it( 'handles empty new text (everything is a deletion)', () => {
		const blocks = computeDiff( 'Old content.', '' );
		const deleted = blocks.find( ( b ) => b.removedText );
		expect( deleted ).toBeDefined();
		expect( deleted.removedText ).toBe( 'Old content.' );
	} );

	it( 'handles both texts empty', () => {
		const blocks = computeDiff( '', '' );
		expect( blocks ).toHaveLength( 0 );
	} );

	it( 'assigns unique ids to all blocks', () => {
		const oldText = 'A.\n\nB.\n\nC.';
		const newText = 'A.\n\nX.\n\nC.';
		const blocks = computeDiff( oldText, newText );
		const ids = blocks.map( ( b ) => b.id );
		expect( new Set( ids ).size ).toBe( ids.length );
	} );

	it( 'preserves unchanged trailing paragraphs in a final block', () => {
		const oldText = 'Old intro.\n\nTrailing.';
		const newText = 'New intro.\n\nTrailing.';
		const blocks = computeDiff( oldText, newText );

		const trailing = blocks.find(
			( b ) => b.unchanged.includes( 'Trailing.' ) && ! b.removedText
		);
		expect( trailing ).toBeDefined();
	} );
} );

import {
	buildRevisionMessage,
	parseRevisionMessage,
} from '../../src/admin/components/Chat/revisionMessage';

describe( 'revisionMessage', () => {
	it( 'pins the wire format', () => {
		expect(
			buildRevisionMessage(
				[
					{ selectedText: 'Hello', text: 'Shorter' },
					{ text: 'More casual' },
				],
				' Keep it light '
			)
		).toBe(
			'Please revise your proposed changes.\n\nInline feedback:\n- On "Hello": Shorter\n- More casual\n\nOverall: Keep it light'
		);
	} );

	it( 'round-trips comments and note', () => {
		const msg = buildRevisionMessage(
			[
				{ selectedText: 'Hello', text: 'Shorter' },
				{ text: 'More casual' },
			],
			'Note'
		);
		expect( parseRevisionMessage( msg ) ).toEqual( {
			comments: [
				{ anchor: 'Hello', text: 'Shorter' },
				{ anchor: null, text: 'More casual' },
			],
			note: 'Note',
		} );
	} );

	it( 'handles comments only', () => {
		const msg = buildRevisionMessage( [ { selectedText: 'A', text: 'b' } ], '' );
		expect( parseRevisionMessage( msg ) ).toEqual( {
			comments: [ { anchor: 'A', text: 'b' } ],
			note: null,
		} );
	} );

	it( 'handles note only', () => {
		expect( parseRevisionMessage( buildRevisionMessage( [], 'Just this' ) ) ).toEqual( {
			comments: [],
			note: 'Just this',
		} );
	} );

	it( 'keeps multi-line comment text', () => {
		const msg = buildRevisionMessage(
			[ { selectedText: 'A', text: 'line1\nline2' } ],
			''
		);
		expect( parseRevisionMessage( msg ).comments ).toEqual( [
			{ anchor: 'A', text: 'line1\nline2' },
		] );
	} );

	it( 'ends the anchor at the first \": \" (known limitation)', () => {
		const msg = buildRevisionMessage(
			[ { selectedText: 'say "hi": now', text: 'x' } ],
			''
		);
		expect( parseRevisionMessage( msg ).comments[ 0 ].anchor ).toBe(
			'say "hi'
		);
	} );

	it( 'mis-attributes a comment ending in a note line (known limitation)', () => {
		const msg = buildRevisionMessage(
			[ { selectedText: 'A', text: 'real comment\n\nOverall: inner line' } ],
			''
		);
		// The inner "\n\nOverall: " inside the comment text is wrongly treated
		// as the overall note delimiter, splitting the comment lossily.
		expect( parseRevisionMessage( msg ) ).toEqual( {
			comments: [ { anchor: 'A', text: 'real comment' } ],
			note: 'inner line',
		} );
	} );

	it( 'parses a stored (trimmed) string', () => {
		const stored =
			'Please revise your proposed changes.\n\nInline feedback:\n- On "x": y\n\nOverall: z';
		expect( parseRevisionMessage( stored ).note ).toBe( 'z' );
	} );

	it.each( [
		'Just a normal message',
		'Please revise your proposed changes.',
		'Please revise your proposed changes.\n\nSomething else',
		'Please revise your proposed changes.\n\nInline feedback:\nnone',
		'',
		undefined,
	] )( 'returns null for %p', ( input ) => {
		expect( parseRevisionMessage( input ) ).toBeNull();
	} );
} );

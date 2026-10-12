/**
 * Unit tests for MessageBubble's structured revision-request rendering.
 *
 * @see src/admin/components/Chat/MessageBubble.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import MessageBubble from '../../src/admin/components/Chat/MessageBubble';
import { buildRevisionMessage } from '../../src/admin/components/Chat/revisionMessage';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'MessageBubble revision request', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	function render( content ) {
		act( () => {
			root.render( <MessageBubble message={ { role: 'user', content } } /> );
		} );
	}

	it( 'shows each comment under its quoted anchor, plus the note', () => {
		render(
			buildRevisionMessage(
				[ { selectedText: 'Opening line', text: 'Make it punchier' } ],
				'Keep it short'
			)
		);
		const item = container.querySelector( 'ul > li' );
		expect( item.querySelector( 'blockquote' ).textContent ).toBe(
			'Opening line'
		);
		expect( item.textContent ).toContain( 'Make it punchier' );
		expect( container.textContent ).toContain( 'Keep it short' );
	} );

	it( 'renders markup in comments as text, not HTML', () => {
		render(
			buildRevisionMessage( [ { selectedText: 'a', text: '<b>x</b>' } ], '' )
		);
		expect( container.querySelector( 'b' ) ).toBeNull();
		expect( container.textContent ).toContain( '<b>x</b>' );
	} );

	it( 'leaves ordinary user messages as plain text', () => {
		render( 'Hello there' );
		expect( container.querySelector( 'ul' ) ).toBeNull();
		expect( container.querySelector( 'p' ).textContent ).toBe(
			'Hello there'
		);
	} );
} );

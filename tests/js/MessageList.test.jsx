/**
 * Unit tests for MessageList's waiting state.
 *
 * @see src/admin/components/Chat/MessageList.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import MessageList from '../../src/admin/components/Chat/MessageList';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'MessageList — waiting for a reply', () => {
	let container;
	let root;

	beforeAll( () => {
		window.HTMLElement.prototype.scrollIntoView = jest.fn();
	} );

	beforeEach( () => {
		jest.useFakeTimers();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		jest.useRealTimers();
	} );

	const status = () => container.querySelector( '[role="status"]' );

	it( 'keeps one status region mounted, empty while idle', () => {
		act( () => {
			root.render( <MessageList messages={ [] } isLoading={ false } /> );
		} );

		// Screen readers only announce changes to a live region that already
		// exists, so it must be there before the first message is sent.
		expect( status() ).not.toBeNull();
		expect( status().getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( status().textContent ).toBe( '' );
	} );

	it( 'says what is happening, and more as the wait gets longer', () => {
		act( () => {
			root.render( <MessageList messages={ [] } isLoading={ true } /> );
		} );
		expect( status().textContent ).toBe( 'Thinking…' );

		act( () => {
			jest.advanceTimersByTime( 8000 );
		} );
		expect( status().textContent ).toBe( 'Still working on it…' );

		act( () => {
			jest.advanceTimersByTime( 17000 );
		} );
		expect( status().textContent ).toContain( 'up to a minute' );
	} );

	it( 'starts from the first message again for the next reply', () => {
		act( () => {
			root.render( <MessageList messages={ [] } isLoading={ true } /> );
		} );
		act( () => {
			jest.advanceTimersByTime( 30000 );
		} );
		act( () => {
			root.render( <MessageList messages={ [] } isLoading={ false } /> );
		} );
		expect( status().textContent ).toBe( '' );

		act( () => {
			root.render( <MessageList messages={ [] } isLoading={ true } /> );
		} );
		expect( status().textContent ).toBe( 'Thinking…' );
	} );
} );

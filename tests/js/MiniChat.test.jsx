/**
 * Unit tests for MiniChat — the chat panel in the block editor sidebar.
 *
 * @see src/editor/components/MiniChat.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import MiniChat from '../../src/editor/components/MiniChat';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

jest.mock( 'dompurify', () => ( {
	sanitize: ( html ) => html,
} ) );

/**
 * Types a message into the sidebar chat and sends it.
 *
 * @param {HTMLElement} container Rendered component root.
 * @param {string}      text      Message to send.
 */
async function send( container, text ) {
	const input = container.querySelector( '.plume-editor-mini-chat__input' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	await act( async () => {
		setter.call( input, text );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	await act( async () => {
		container.querySelector( '.plume-editor-mini-chat__send' ).click();
	} );
}

describe( 'MiniChat', () => {
	let container;
	let root;

	beforeAll( () => {
		window.HTMLElement.prototype.scrollIntoView = jest.fn();
	} );

	beforeEach( () => {
		apiFetch.mockReset();
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

	it( 'shows the reply', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			path === '/plume/v1/conversations'
				? Promise.resolve( { id: 3 } )
				: Promise.resolve( { content: 'Here is a tighter intro.' } )
		);
		await act( async () => {
			root.render( <MiniChat postId={ 12 } /> );
		} );

		await send( container, 'Tighten the intro' );

		expect( container.textContent ).toContain( 'Here is a tighter intro.' );
	} );

	it( 'shows why a message failed instead of going silent', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			path === '/plume/v1/conversations'
				? Promise.resolve( { id: 3 } )
				: Promise.reject( {
						message: 'Connecting this site. Please try again.',
				  } )
		);
		await act( async () => {
			root.render( <MiniChat postId={ 12 } /> );
		} );

		await send( container, 'Tighten the intro' );

		const error = container.querySelector(
			'.plume-editor-mini-chat__bubble--error'
		);
		expect( error ).not.toBeNull();
		expect( error.textContent ).toContain(
			'Connecting this site. Please try again.'
		);
	} );

	it( 'shows the credits notice when credits run out', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			path === '/plume/v1/conversations'
				? Promise.resolve( { id: 3 } )
				: Promise.reject( {
						code: 'rate_limit_exceeded',
						message: 'Monthly usage limit reached.',
				  } )
		);
		await act( async () => {
			root.render( <MiniChat postId={ 12 } /> );
		} );

		await send( container, 'Tighten the intro' );

		expect(
			container.querySelector( '.plume-out-of-credits-banner' )
		).not.toBeNull();
	} );
} );

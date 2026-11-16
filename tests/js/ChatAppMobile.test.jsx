/**
 * Unit tests for the chat screen's phone layout (panels as drawers).
 *
 * @see src/admin/components/Chat/ChatApp.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import ChatApp from '../../src/admin/components/Chat/ChatApp';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

jest.mock(
	'lucide-react',
	() => new Proxy( {}, { get: () => () => <span /> } )
);

jest.mock( '../../src/admin/utils/storage', () => ( {
	storageGet: jest.fn( () => null ),
	storageSet: jest.fn(),
} ) );

/**
 * Make matchMedia report the phone layout (or not).
 *
 * @param {boolean} isPhone Whether the phone media query should match.
 */
function setPhone( isPhone ) {
	window.matchMedia = jest.fn( () => ( {
		matches: isPhone,
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
	} ) );
}

describe( 'ChatApp on a phone', () => {
	let container;
	let root;

	beforeAll( () => {
		window.HTMLElement.prototype.scrollIntoView = jest.fn();
		global.window.plumeData = { defaultProvider: 'claude' };
	} );

	beforeEach( () => {
		const apiFetch = require( '@wordpress/api-fetch' );
		apiFetch.mockImplementation( ( { path } ) =>
			path === '/plume/v1/conversations'
				? Promise.resolve( [ { id: 3, title: 'Spring ideas' } ] )
				: Promise.resolve( [] )
		);
		setPhone( true );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		delete window.matchMedia;
	} );

	const toggle = ( label ) =>
		container.querySelector(
			`.plume-mobile-bar button[aria-label="${ label }"]`
		);
	const shell = () => container.querySelector( '.plume-shell' );
	const main = () => container.querySelector( '.plume-main' );

	it( 'opens the conversation list as a drawer and moves focus into it', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );
		const open = toggle( 'Conversations' );
		expect( open.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( open.getAttribute( 'aria-controls' ) ).toBe(
			container.querySelector( '.plume-sidebar' ).id
		);

		await act( async () => {
			open.click();
		} );

		expect( shell().classList ).toContain(
			'plume-shell--show-conversations'
		);
		expect( open.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect(
			container
				.querySelector( '.plume-sidebar' )
				.contains( document.activeElement )
		).toBe( true );
		// The rest of the screen cannot be reached while the drawer is open.
		expect( main().hasAttribute( 'inert' ) ).toBe( true );
	} );

	it( 'closes on Escape and returns focus to the button that opened it', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );
		await act( async () => {
			toggle( 'Conversations' ).click();
		} );

		await act( async () => {
			document.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } )
			);
		} );

		expect( shell().classList ).not.toContain(
			'plume-shell--show-conversations'
		);
		expect( main().hasAttribute( 'inert' ) ).toBe( false );
		expect( document.activeElement ).toBe( toggle( 'Conversations' ) );
	} );

	it( 'closes when the backdrop is tapped', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );
		await act( async () => {
			toggle( 'Model and quick actions' ).click();
		} );
		expect( shell().classList ).toContain( 'plume-shell--show-tools' );

		await act( async () => {
			container.querySelector( '.plume-drawer-backdrop' ).click();
		} );

		expect( shell().classList ).not.toContain( 'plume-shell--show-tools' );
	} );

	it( 'closes the tools drawer when a quick action fires', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );
		const count = container.querySelectorAll(
			'.plume-quick-action'
		).length;
		expect( count ).toBeGreaterThan( 0 );

		// Every action, so both paths are covered: sending straight away, and
		// asking for a post to be attached first.
		for ( let i = 0; i < count; i++ ) {
			await act( async () => {
				toggle( 'Model and quick actions' ).click();
			} );
			expect( shell().classList ).toContain( 'plume-shell--show-tools' );

			await act( async () => {
				container
					.querySelectorAll( '.plume-quick-action' )
					[ i ].click();
			} );

			expect( shell().classList ).not.toContain(
				'plume-shell--show-tools'
			);
		}
	} );

	it( 'closes the list once a conversation is chosen', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );
		await act( async () => {
			toggle( 'Conversations' ).click();
		} );

		await act( async () => {
			container.querySelector( '.plume-conv-item__body' ).click();
		} );

		expect( shell().classList ).not.toContain(
			'plume-shell--show-conversations'
		);
	} );

	it( 'ignores the stored desktop collapse state', async () => {
		const storage = require( '../../src/admin/utils/storage' );
		storage.storageGet.mockImplementation( ( key ) =>
			key === 'plume-sidebar-collapsed' ? '1' : null
		);
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		expect( shell().classList ).not.toContain(
			'plume-shell--sidebar-collapsed'
		);
		storage.storageGet.mockImplementation( () => null );
	} );

	it( 'shows no phone bar or inert content on a desktop', async () => {
		setPhone( false );
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		expect( container.querySelector( '.plume-mobile-bar' ) ).toBeNull();
		expect( main().hasAttribute( 'inert' ) ).toBe( false );
	} );
} );

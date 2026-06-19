/**
 * Unit tests for the Composer component — attachment-pill branch.
 *
 * @see src/admin/components/Chat/Composer.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import Composer from '../../src/admin/components/Chat/Composer';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

// ContextPicker is only rendered when showPicker is true (attachment button
// clicked). These tests never trigger that action, so a no-op stub suffices.
jest.mock( '../../src/admin/components/Chat/ContextPicker', () => () => null );

describe( 'Composer — attachment pill', () => {
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

	it( 'renders an <a> link when attachedPost.edit_link is set', async () => {
		const attachedPost = {
			id: 1,
			title: 'Hello World',
			edit_link: 'https://example.com/wp-admin/post.php?post=1&action=edit',
		};

		await act( async () => {
			root.render(
				<Composer
					onSend={ jest.fn() }
					isLoading={ false }
					attachedPost={ attachedPost }
					onAttach={ jest.fn() }
					onDetach={ jest.fn() }
				/>
			);
		} );

		const link = container.querySelector( '.plume-composer__attachment-link' );
		expect( link ).not.toBeNull();
		expect( link.tagName.toLowerCase() ).toBe( 'a' );
		expect( link.getAttribute( 'href' ) ).toBe( attachedPost.edit_link );
		// Title is rendered as pill text; the <a> is an empty cover element.
		const pill = container.querySelector( '.plume-composer__attachment-pill' );
		expect( pill.textContent ).toContain( 'Hello World' );
	} );

	it( 'renders plain text when attachedPost.edit_link is empty', async () => {
		const attachedPost = {
			id: 2,
			title: 'No Link Post',
			edit_link: '',
		};

		await act( async () => {
			root.render(
				<Composer
					onSend={ jest.fn() }
					isLoading={ false }
					attachedPost={ attachedPost }
					onAttach={ jest.fn() }
					onDetach={ jest.fn() }
				/>
			);
		} );

		const link = container.querySelector( '.plume-composer__attachment-link' );
		expect( link ).toBeNull();

		const pill = container.querySelector( '.plume-composer__attachment-pill' );
		expect( pill ).not.toBeNull();
		expect( pill.textContent ).toContain( 'No Link Post' );
	} );
} );

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

describe( 'Composer — typing and sending', () => {
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

	const textarea = () => container.querySelector( '.plume-composer__input' );

	async function type( text ) {
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		).set;
		await act( async () => {
			setter.call( textarea(), text );
			textarea().dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
	}

	function pressEnter( init = {} ) {
		const event = new KeyboardEvent( 'keydown', {
			key: 'Enter',
			bubbles: true,
			cancelable: true,
			...init,
		} );
		Object.entries( init ).forEach( ( [ key, value ] ) => {
			if ( ! ( key in event ) || event[ key ] !== value ) {
				Object.defineProperty( event, key, { value } );
			}
		} );
		textarea().dispatchEvent( event );
	}

	it( 'puts the cursor in the message box when it appears', async () => {
		await act( async () => {
			root.render( <Composer onSend={ jest.fn() } isLoading={ false } /> );
		} );

		expect( document.activeElement ).toBe( textarea() );
	} );

	it( 'lets the user keep typing while a reply is on its way', async () => {
		await act( async () => {
			root.render( <Composer onSend={ jest.fn() } isLoading={ true } /> );
		} );

		expect( textarea().disabled ).toBe( false );
	} );

	it( 'does not send while a reply is on its way', async () => {
		const onSend = jest.fn();
		await act( async () => {
			root.render( <Composer onSend={ onSend } isLoading={ true } /> );
		} );
		await type( 'Next question' );

		await act( async () => {
			pressEnter();
		} );

		expect( onSend ).not.toHaveBeenCalled();
		expect( textarea().value ).toBe( 'Next question' );
	} );

	it( 'does not send when Enter confirms an input-method composition', async () => {
		const onSend = jest.fn();
		await act( async () => {
			root.render( <Composer onSend={ onSend } isLoading={ false } /> );
		} );
		await type( 'にほんご' );

		await act( async () => {
			pressEnter( { isComposing: true } );
		} );
		await act( async () => {
			pressEnter( { keyCode: 229 } );
		} );

		expect( onSend ).not.toHaveBeenCalled();
	} );

	it( 'sends on Enter and keeps the cursor in the box', async () => {
		const onSend = jest.fn();
		await act( async () => {
			root.render( <Composer onSend={ onSend } isLoading={ false } /> );
		} );
		await type( 'Hello' );

		await act( async () => {
			pressEnter();
		} );

		expect( onSend ).toHaveBeenCalledWith( 'Hello' );
		expect( textarea().value ).toBe( '' );
		expect( document.activeElement ).toBe( textarea() );
	} );

	it( 'grows with the text it holds', async () => {
		await act( async () => {
			root.render( <Composer onSend={ jest.fn() } isLoading={ false } /> );
		} );
		// jsdom does no layout; give the box a measurable content height.
		Object.defineProperty( textarea(), 'scrollHeight', {
			configurable: true,
			get: () => 96,
		} );

		await type( 'line 1\nline 2\nline 3\nline 4' );

		expect( textarea().style.height ).toBe( '96px' );
	} );
} );


/**
 * Unit tests for ImagesWorkArea's out-of-credits handling.
 *
 * @see src/images/ImagesWorkArea.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import ImagesWorkArea from '../../src/images/ImagesWorkArea';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

jest.mock( 'dompurify', () => ( {
	sanitize: ( html ) => html,
} ) );

const POST = {
	id: 5,
	type: 'post',
	title: { rendered: 'Test Post' },
	_links: { self: [ { href: 'http://example.test/wp-json/wp/v2/posts/5' } ] },
};

beforeAll( () => {
	global.window.plumeData = {
		nonce: 'test-nonce',
		restUrl: 'http://example.test/wp-json/plume/v1',
		websiteUrl: 'https://wpaimind.com',
	};
} );

afterAll( () => {
	delete global.window.plumeData;
} );

describe( 'ImagesWorkArea — out-of-credits handling', () => {
	let container;
	let root;

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

	it( 'renders OutOfCreditsNotice when generation fails with a rate_limit_exceeded error', async () => {
		apiFetch.mockRejectedValue( {
			code: 'rate_limit_exceeded',
			message: 'Monthly usage limit reached.',
		} );

		await act( async () => {
			root.render(
				<ImagesWorkArea
					post={ POST }
					onClose={ jest.fn() }
					onUpdate={ jest.fn() }
				/>
			);
		} );

		const textarea = container.querySelector( '.plume-prompt-input' );
		const nativeTextareaSetter = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		).set;
		await act( async () => {
			nativeTextareaSetter.call( textarea, 'A sunset over mountains' );
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		const generateButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Generate' ) );

		await act( async () => {
			generateButton.click();
		} );

		expect(
			container.querySelector( '.plume-out-of-credits-banner' )
		).not.toBeNull();
		expect( container.textContent ).toContain(
			'You’ve used all your credits for this billing period.'
		);
	} );

	it( 'renders the generic error message for a non-credits failure', async () => {
		apiFetch.mockRejectedValue( { message: 'Network error.' } );

		await act( async () => {
			root.render(
				<ImagesWorkArea
					post={ POST }
					onClose={ jest.fn() }
					onUpdate={ jest.fn() }
				/>
			);
		} );

		const textarea = container.querySelector( '.plume-prompt-input' );
		const nativeTextareaSetter = Object.getOwnPropertyDescriptor(
			window.HTMLTextAreaElement.prototype,
			'value'
		).set;
		await act( async () => {
			nativeTextareaSetter.call( textarea, 'A sunset over mountains' );
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );

		const generateButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Generate' ) );

		await act( async () => {
			generateButton.click();
		} );

		expect(
			container.querySelector( '.plume-out-of-credits-banner' )
		).toBeNull();
		expect( container.querySelector( '.plume-work-error' ).textContent ).toBe(
			'Network error.'
		);
	} );
} );

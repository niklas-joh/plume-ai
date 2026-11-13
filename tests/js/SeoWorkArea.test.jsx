/**
 * Unit tests for SeoWorkArea's out-of-credits handling.
 *
 * @see src/seo/SeoWorkArea.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import SeoWorkArea from '../../src/seo/SeoWorkArea';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

jest.mock( 'dompurify', () => ( {
	sanitize: ( html ) => html,
} ) );

const POST = {
	id: 7,
	title: { rendered: 'Test Post' },
	plume_seo_status: null,
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

describe( 'SeoWorkArea — out-of-credits handling', () => {
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
				<SeoWorkArea
					post={ POST }
					onClose={ jest.fn() }
					onUpdate={ jest.fn() }
				/>
			);
		} );

		const generateButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Generate SEO' ) );

		await act( async () => {
			generateButton.click();
		} );

		expect(
			container.querySelector( '.plume-out-of-credits-banner' )
		).not.toBeNull();
	} );

	it( 'renders the generic error message for a non-credits failure', async () => {
		apiFetch.mockRejectedValue( { message: 'Network error.' } );

		await act( async () => {
			root.render(
				<SeoWorkArea
					post={ POST }
					onClose={ jest.fn() }
					onUpdate={ jest.fn() }
				/>
			);
		} );

		const generateButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Generate SEO' ) );

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

	it( 'shows the server text sent in the `error` field', async () => {
		// The envelope /plume/v1/seo/generate really sends on failure.
		apiFetch.mockRejectedValue( { error: 'Post not found.' } );

		await act( async () => {
			root.render(
				<SeoWorkArea
					post={ POST }
					onClose={ jest.fn() }
					onUpdate={ jest.fn() }
				/>
			);
		} );

		const generateButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent.includes( 'Generate SEO' ) );

		await act( async () => {
			generateButton.click();
		} );

		expect( container.querySelector( '.plume-work-error' ).textContent ).toBe(
			'Post not found.'
		);
	} );
} );

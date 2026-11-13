/**
 * Unit tests for ImagesApp — the AI Images admin screen root component.
 *
 * @see src/images/ImagesApp.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import ImagesApp from '../../src/images/ImagesApp';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

jest.mock( '../../src/shared/PostListTable', () => () => (
	<div data-testid="post-list-table" />
) );

beforeAll( () => {
	global.window.plumeData = {
		isPaid: false,
		websiteUrl: 'https://wpaimind.com',
	};
} );

afterAll( () => {
	delete global.window.plumeData;
} );

describe( 'ImagesApp', () => {
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

	it( 'renders the page header unconditionally regardless of isPaid', async () => {
		await act( async () => {
			root.render( <ImagesApp /> );
		} );

		expect( container.querySelector( '.plume-page-header' ) ).not.toBeNull();
		expect( container.querySelector( '[data-testid="post-list-table"]' ) ).not.toBeNull();
	} );

	it( 'does not render a PRO badge in the header', async () => {
		await act( async () => {
			root.render( <ImagesApp /> );
		} );

		expect( container.querySelector( '.plume-pro-badge' ) ).toBeNull();
		expect( container.textContent ).not.toContain( 'PRO' );
	} );

	it( 'does not render the locked plume-pro-gate placeholder', async () => {
		await act( async () => {
			root.render( <ImagesApp /> );
		} );

		expect( container.querySelector( '.plume-pro-gate' ) ).toBeNull();
	} );

	describe( 'missing image key notice', () => {
		const originalData = global.window.plumeData;

		afterEach( () => {
			global.window.plumeData = originalData;
		} );

		it( 'explains that images need an own key and links to Settings', async () => {
			global.window.plumeData = {
				imageProvider: { label: 'Gemini', hasKey: false },
				settingsUrl: 'https://example.test/wp-admin/admin.php?page=plume-settings',
			};

			await act( async () => {
				root.render( <ImagesApp /> );
			} );

			const notice = container.querySelector( '[role="alert"]' );
			expect( notice ).not.toBeNull();
			expect( notice.textContent ).toContain( 'your own API key' );
			expect( notice.querySelector( 'a' ).getAttribute( 'href' ) ).toBe(
				'https://example.test/wp-admin/admin.php?page=plume-settings'
			);
		} );

		it( 'tells users without access to Settings to ask an administrator', async () => {
			global.window.plumeData = {
				imageProvider: { label: 'Gemini', hasKey: false },
				settingsUrl: '',
			};

			await act( async () => {
				root.render( <ImagesApp /> );
			} );

			const notice = container.querySelector( '[role="alert"]' );
			expect( notice.querySelector( 'a' ) ).toBeNull();
			expect( notice.textContent ).toContain( 'Ask a site administrator' );
		} );

		it( 'shows no notice when the key is configured', async () => {
			global.window.plumeData = {
				imageProvider: { label: 'Gemini', hasKey: true },
			};

			await act( async () => {
				root.render( <ImagesApp /> );
			} );

			expect( container.querySelector( '[role="alert"]' ) ).toBeNull();
		} );
	} );
} );


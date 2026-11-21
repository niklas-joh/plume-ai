/**
 * Unit tests for SeoApp — the AI SEO admin screen root component.
 *
 * @see src/seo/SeoApp.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import SeoApp from '../../src/seo/SeoApp';

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

describe( 'SeoApp', () => {
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
			root.render( <SeoApp /> );
		} );

		expect( container.querySelector( '.plume-page-header' ) ).not.toBeNull();
		expect( container.querySelector( '[data-testid="post-list-table"]' ) ).not.toBeNull();
	} );

	it( 'renders one h1 with the screen name', async () => {
		await act( async () => {
			root.render( <SeoApp /> );
		} );

		const headings = container.querySelectorAll( 'h1' );
		expect( headings ).toHaveLength( 1 );
		expect( headings[ 0 ].textContent ).toBe( 'SEO' );
	} );

	it( 'does not render a PRO badge in the header', async () => {
		await act( async () => {
			root.render( <SeoApp /> );
		} );

		expect( container.querySelector( '.plume-pro-badge' ) ).toBeNull();
		expect( container.textContent ).not.toContain( 'PRO' );
	} );

	it( 'does not render the locked plume-pro-gate placeholder', async () => {
		await act( async () => {
			root.render( <SeoApp /> );
		} );

		expect( container.querySelector( '.plume-pro-gate' ) ).toBeNull();
	} );
} );

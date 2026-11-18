/**
 * Unit tests for DashboardApp — the Plume dashboard root component.
 *
 * @see src/admin/dashboard/DashboardApp.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import DashboardApp from '../../src/admin/dashboard/DashboardApp';

jest.mock(
	'lucide-react',
	() => new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'DashboardApp', () => {
	let container;
	let root;

	beforeEach( () => {
		window.plumeDashboard = {
			onboardingSeen: true,
			version: '1.2.3',
			urls: {},
			resourceUrls: {},
		};
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		delete window.plumeDashboard;
	} );

	it( 'renders the shared page header with exactly one h1', async () => {
		await act( async () => {
			root.render( <DashboardApp /> );
		} );

		const headings = container.querySelectorAll( 'h1' );
		expect( headings ).toHaveLength( 1 );
		expect( headings[ 0 ].textContent ).toBe( 'Dashboard' );
		expect( container.querySelector( '.plume-logo' ) ).not.toBeNull();
	} );

	it( 'keeps the version badge in the header actions', async () => {
		await act( async () => {
			root.render( <DashboardApp /> );
		} );

		const badge = container.querySelector(
			'.plume-page-header__actions .plume-dash-version'
		);
		expect( badge ).not.toBeNull();
		expect( badge.textContent ).toBe( 'v1.2.3' );
	} );
} );

/**
 * Unit tests for the Usage screen.
 *
 * @see src/usage/components/UsageDashboard.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import UsageDashboard from '../../src/usage/components/UsageDashboard';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'UsageDashboard', () => {
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

	it( 'does not claim the limit is reached while credits remain', async () => {
		// The shape /plume/v1/usage really sends: snake_case `can_use`.
		apiFetch.mockResolvedValue( {
			tier: 'free',
			used: 10,
			limit: 100,
			remaining: 90,
			can_use: true,
		} );

		await act( async () => {
			root.render( <UsageDashboard /> );
		} );

		expect( container.textContent ).toContain( '10%' );
		expect( container.textContent ).not.toContain(
			'Monthly limit reached'
		);
	} );

	it( 'says the limit is reached when the server reports it', async () => {
		apiFetch.mockResolvedValue( {
			tier: 'free',
			used: 100,
			limit: 100,
			remaining: 0,
			can_use: false,
		} );

		await act( async () => {
			root.render( <UsageDashboard /> );
		} );

		expect( container.textContent ).toContain( 'Monthly limit reached' );
	} );
} );

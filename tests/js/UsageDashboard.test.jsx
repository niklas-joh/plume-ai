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

jest.mock(
	'lucide-react',
	() => new Proxy( {}, { get: () => () => <span /> } )
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

	it( 'shows the plan name, never the tier slug', async () => {
		apiFetch.mockResolvedValue( {
			tier: 'pro_managed',
			tier_label: 'Pro',
			used: 10,
			limit: 100,
			remaining: 90,
			can_use: true,
		} );

		await act( async () => {
			root.render( <UsageDashboard /> );
		} );

		expect(
			container.querySelector( '.plume-usage__period' ).textContent
		).toBe( 'Pro' );
		expect( container.textContent ).not.toContain( 'pro_managed' );
		expect( container.textContent ).not.toContain( 'Tier:' );
	} );

	it( 'renders one h1, no inline styles, and an accessible quota bar', async () => {
		apiFetch.mockResolvedValue( {
			tier_label: 'Pro',
			used: 40,
			limit: 100,
			remaining: 60,
			can_use: true,
		} );

		await act( async () => {
			root.render( <UsageDashboard /> );
		} );

		expect( container.querySelectorAll( 'h1' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '[style]' ) ).toHaveLength( 0 );
		const bar = container.querySelector( 'progress' );
		expect( bar.getAttribute( 'aria-label' ) ).toBe(
			'Monthly credit usage'
		);
		expect( bar.getAttribute( 'value' ) ).toBe( '40' );
		expect( container.textContent ).toContain( '40 of 100 credits used' );
	} );

	it( 'keeps the header on the error state', async () => {
		apiFetch.mockRejectedValue( { message: 'offline' } );

		await act( async () => {
			root.render( <UsageDashboard /> );
		} );

		expect( container.querySelectorAll( 'h1' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '[style]' ) ).toHaveLength( 0 );
	} );
} );

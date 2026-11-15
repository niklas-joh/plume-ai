/**
 * Unit tests for the Dashboard usage widget's plan label.
 *
 * @see src/admin/dashboard/UsageWidget.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import UsageWidget from '../../src/admin/dashboard/UsageWidget';

describe( 'UsageWidget', () => {
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

	function render( usage ) {
		act( () => {
			root.render( <UsageWidget usage={ usage } /> );
		} );
	}

	it( 'shows the plan name and never the tier slug', () => {
		render( {
			tier: 'pro_managed',
			tier_label: 'Pro Managed',
			used: 20,
			limit: 100,
			remaining: 80,
			can_use: true,
		} );

		expect( container.textContent ).toContain( 'Pro Managed' );
		expect( container.textContent ).not.toContain( 'pro_managed' );
		expect( container.textContent ).not.toContain( 'Tier:' );
	} );

	it( 'shows nothing for the plan when no label was sent', () => {
		render( {
			tier: 'free',
			used: 20,
			limit: 100,
			remaining: 80,
			can_use: true,
		} );

		expect(
			container.querySelector( '.plume-usage-widget__tier-badge' )
		).toBeNull();
		expect( container.textContent ).not.toMatch( /\bfree\b/ );
	} );

	it( 'renders nothing without usage data', () => {
		render( null );
		expect( container.innerHTML ).toBe( '' );
	} );
} );

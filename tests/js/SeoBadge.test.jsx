/**
 * Unit tests for SeoBadge and its getSeoStatus helper.
 *
 * @see src/seo/SeoBadge.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import SeoBadge, { getSeoStatus } from '../../src/seo/SeoBadge';

describe( 'getSeoStatus', () => {
	it( 'returns "missing" when wpaim_seo_status is falsy', () => {
		expect( getSeoStatus( { wpaim_seo_status: null } ) ).toBe( 'missing' );
		expect( getSeoStatus( { wpaim_seo_status: undefined } ) ).toBe( 'missing' );
		expect( getSeoStatus( {} ) ).toBe( 'missing' );
	} );

	it( 'returns "missing" when no fields are filled', () => {
		const post = {
			wpaim_seo_status: { meta_title: 'empty', og_description: 'empty' },
		};
		expect( getSeoStatus( post ) ).toBe( 'missing' );
	} );

	it( 'returns "complete" when all fields are filled', () => {
		const post = {
			wpaim_seo_status: { meta_title: 'filled', og_description: 'filled' },
		};
		expect( getSeoStatus( post ) ).toBe( 'complete' );
	} );

	it( 'returns "partial" when some but not all fields are filled', () => {
		const post = {
			wpaim_seo_status: { meta_title: 'filled', og_description: 'empty' },
		};
		expect( getSeoStatus( post ) ).toBe( 'partial' );
	} );
} );

describe( 'SeoBadge', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		// React 18 createRoot — avoids the deprecated ReactDOM.render warning
		// that @wordpress/jest-console treats as a test failure.
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'renders without throwing', () => {
		act( () => {
			root.render( <SeoBadge status="complete" /> );
		} );
		// SeoBadge renders a <span> with class wpaim-badge--{status} (SeoBadge.jsx line 38).
		expect( container.querySelector( 'span.wpaim-badge' ) ).not.toBeNull();
	} );

	it( 'applies the correct modifier class for each status', () => {
		const statuses = [ 'complete', 'partial', 'missing' ];

		statuses.forEach( ( status ) => {
			act( () => {
				root.render( <SeoBadge status={ status } /> );
			} );

			const badge = container.querySelector( `span.wpaim-badge--${ status }` );
			expect( badge ).not.toBeNull();
		} );
	} );

	it( 'renders the human-readable label text', () => {
		act( () => {
			root.render( <SeoBadge status="complete" /> );
		} );
		// STATUS_LABELS maps 'complete' → 'Complete' (SeoBadge.jsx line 1).
		expect( container.querySelector( '.wpaim-badge' ).textContent ).toBe( 'Complete' );
	} );

	it( 'renders the status value as text when status is not in STATUS_LABELS', () => {
		act( () => {
			root.render( <SeoBadge status="unknown-status" /> );
		} );
		// Fallback: `STATUS_LABELS[status] ?? status` (SeoBadge.jsx line 39).
		expect( container.querySelector( '.wpaim-badge' ).textContent ).toBe( 'unknown-status' );
	} );
} );

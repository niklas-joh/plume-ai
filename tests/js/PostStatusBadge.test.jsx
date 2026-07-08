/**
 * Unit tests for PostStatusBadge.
 *
 * @see src/shared/PostStatusBadge.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import PostStatusBadge from '../../src/shared/PostStatusBadge';

describe( 'PostStatusBadge', () => {
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

	it( 'renders without throwing', () => {
		act( () => {
			root.render( <PostStatusBadge status="publish" /> );
		} );
		expect( container.querySelector( 'span.plume-badge' ) ).not.toBeNull();
	} );

	it( 'applies the correct modifier class and label for each known status', () => {
		const cases = [
			{ status: 'publish', variant: 'complete', label: 'Published' },
			{ status: 'pending', variant: 'partial', label: 'Pending' },
			{ status: 'future', variant: 'partial', label: 'Scheduled' },
			{ status: 'draft', variant: 'muted', label: 'Draft' },
			{ status: 'private', variant: 'muted', label: 'Private' },
		];

		cases.forEach( ( { status, variant, label } ) => {
			act( () => {
				root.render( <PostStatusBadge status={ status } /> );
			} );

			const badge = container.querySelector( `span.plume-badge--${ variant }` );
			expect( badge ).not.toBeNull();
			expect( badge.textContent ).toBe( label );
		} );
	} );

	it( 'falls back to a muted badge with the raw status text for an unknown status', () => {
		act( () => {
			root.render( <PostStatusBadge status="unknown-status" /> );
		} );
		const badge = container.querySelector( 'span.plume-badge--muted' );
		expect( badge ).not.toBeNull();
		expect( badge.textContent ).toBe( 'unknown-status' );
	} );
} );

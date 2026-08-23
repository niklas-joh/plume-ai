/**
 * Unit tests for PlanCard's status options.
 *
 * The publish option must only be offered to users the server would actually
 * let publish — a Contributor holds edit_posts but not publish_posts, so
 * offering them "Published" would only produce a 403 on confirmation.
 *
 * @see src/admin/components/Chat/PlanCard.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import PlanCard from '../../src/admin/components/Chat/PlanCard';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

const plan = {
	id: 'abc12345',
	plan_type: 'create',
	title: 'Widgets',
	outline: 'An outline',
	content: 'Full body.',
	post_status: 'draft',
	post_type: 'post',
};

describe( 'PlanCard status options', () => {
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
		delete window.plumeData;
	} );

	/**
	 * Render the card and open its edit form, where the status select lives.
	 *
	 * @param {Object} planProps Plan object to render.
	 * @return {string[]} The values of the rendered status options.
	 */
	function statusOptions( planProps = plan ) {
		act( () => {
			root.render( <PlanCard plan={ planProps } onDismiss={ () => {} } /> );
		} );

		const editButton = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( button ) => /edit/i.test( button.textContent ) );

		act( () => {
			editButton.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		} );

		return Array.from(
			container.querySelectorAll( '#plume-plan-edit-status option' )
		).map( ( option ) => option.value );
	}

	it( 'offers every status when the user may publish', () => {
		window.plumeData = { publishCaps: { post: true, page: true } };

		expect( statusOptions() ).toEqual( [ 'draft', 'publish', 'pending' ] );
	} );

	it( 'hides publish when the user may not publish that post type', () => {
		window.plumeData = { publishCaps: { post: false, page: true } };

		expect( statusOptions() ).toEqual( [ 'draft', 'pending' ] );
	} );

	it( 'resolves the capability against the plan post type', () => {
		window.plumeData = { publishCaps: { post: true, page: false } };

		expect( statusOptions( { ...plan, post_type: 'page' } ) ).toEqual( [
			'draft',
			'pending',
		] );
	} );

	it( 'offers publish when no capability data is localised', () => {
		expect( statusOptions() ).toEqual( [ 'draft', 'publish', 'pending' ] );
	} );
} );

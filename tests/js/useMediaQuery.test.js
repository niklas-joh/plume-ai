/**
 * Unit tests for the useMediaQuery hook.
 *
 * @see src/admin/utils/useMediaQuery.js
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import {
	useMediaQuery,
	PHONE_LAYOUT_QUERY,
} from '../../src/admin/utils/useMediaQuery';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

/**
 * Build a controllable window.matchMedia stub.
 *
 * Captures the registered 'change' handlers so a test can flip `matches` and
 * fire them, and records add/remove so listener cleanup can be asserted.
 *
 * @param {boolean} initialMatches Whether the query matches to begin with.
 * @return {Function} A matchMedia mock with an attached `.mql` and `.emitChange`.
 */
function createMatchMedia( initialMatches ) {
	const listeners = new Set();
	const mql = {
		matches: initialMatches,
		addEventListener: jest.fn( ( _event, cb ) => listeners.add( cb ) ),
		removeEventListener: jest.fn( ( _event, cb ) => listeners.delete( cb ) ),
	};
	const api = jest.fn( () => mql );
	api.mql = mql;
	api.emitChange = ( next ) => {
		mql.matches = next;
		listeners.forEach( ( cb ) => cb() );
	};
	return api;
}

/**
 * A component that renders the hook's current result as text.
 *
 * @param {Object} props
 * @param {string} props.query The media query to watch.
 * @return {ReactElement}
 */
function Probe( { query } ) {
	const matches = useMediaQuery( query );
	return React.createElement(
		'span',
		{ 'data-testid': 'result' },
		String( matches )
	);
}

describe( 'useMediaQuery', () => {
	let cleanups = [];

	/**
	 * Render a Probe for the given query and return helpers for reading its
	 * value and tearing it down.
	 *
	 * @param {string} query The media query to watch.
	 * @return {{ result: Function, unmount: Function }}
	 */
	function render( query ) {
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		act( () => {
			root.render( React.createElement( Probe, { query } ) );
		} );
		let done = false;
		const unmount = () => {
			if ( done ) {
				return;
			}
			done = true;
			act( () => {
				root.unmount();
			} );
			container.remove();
		};
		cleanups.push( unmount );
		return {
			result: () =>
				container.querySelector( '[data-testid="result"]' ).textContent,
			unmount,
		};
	}

	afterEach( () => {
		cleanups.forEach( ( fn ) => fn() );
		cleanups = [];
		delete window.matchMedia;
	} );

	it( 'reports the initial match', () => {
		window.matchMedia = createMatchMedia( true );
		const { result } = render( PHONE_LAYOUT_QUERY );
		expect( result() ).toBe( 'true' );
	} );

	it( 'updates when the registered change handler fires', () => {
		const mm = createMatchMedia( false );
		window.matchMedia = mm;
		const { result } = render( PHONE_LAYOUT_QUERY );
		expect( result() ).toBe( 'false' );

		act( () => {
			mm.emitChange( true );
		} );

		expect( result() ).toBe( 'true' );
	} );

	it( 'removes its listener on unmount', () => {
		const mm = createMatchMedia( true );
		window.matchMedia = mm;
		const { unmount } = render( PHONE_LAYOUT_QUERY );
		expect( mm.mql.addEventListener ).toHaveBeenCalledWith(
			'change',
			expect.any( Function )
		);

		unmount();

		expect( mm.mql.removeEventListener ).toHaveBeenCalledWith(
			'change',
			expect.any( Function )
		);
	} );

	it( 'falls back to false when matchMedia is unavailable', () => {
		delete window.matchMedia;
		const { result } = render( PHONE_LAYOUT_QUERY );
		expect( result() ).toBe( 'false' );
	} );
} );

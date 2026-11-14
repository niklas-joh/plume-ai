import { createRoot } from 'react-dom/client';
import { act } from 'react';
import { useMediaQuery } from '../../src/admin/utils/useMediaQuery';

function Probe( { query } ) {
	return useMediaQuery( query ) ? 'match' : 'no-match';
}

describe( 'useMediaQuery', () => {
	let container;
	let root;
	const originalMatchMedia = window.matchMedia;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => root.unmount() );
		container.remove();
		window.matchMedia = originalMatchMedia;
	} );

	function fakeMatchMedia( initial ) {
		const list = {
			matches: initial,
			listeners: new Set(),
			addEventListener: jest.fn( ( type, fn ) =>
				list.listeners.add( fn )
			),
			removeEventListener: jest.fn( ( type, fn ) =>
				list.listeners.delete( fn )
			),
		};
		window.matchMedia = jest.fn( () => list );
		return list;
	}

	it( 'follows the query as it starts and stops matching', () => {
		const list = fakeMatchMedia( false );
		act( () => root.render( <Probe query="(max-width: 782px)" /> ) );
		expect( container.textContent ).toBe( 'no-match' );

		list.matches = true;
		act( () => list.listeners.forEach( ( fn ) => fn() ) );
		expect( container.textContent ).toBe( 'match' );

		list.matches = false;
		act( () => list.listeners.forEach( ( fn ) => fn() ) );
		expect( container.textContent ).toBe( 'no-match' );
	} );

	it( 'stops listening when unmounted', () => {
		const list = fakeMatchMedia( true );
		act( () => root.render( <Probe query="(max-width: 782px)" /> ) );
		expect( list.listeners.size ).toBe( 1 );

		act( () => root.render( null ) );
		expect( list.listeners.size ).toBe( 0 );
	} );

	it( 'reports no match where matchMedia is unavailable', () => {
		window.matchMedia = undefined;
		act( () => root.render( <Probe query="(max-width: 782px)" /> ) );
		expect( container.textContent ).toBe( 'no-match' );
	} );
} );

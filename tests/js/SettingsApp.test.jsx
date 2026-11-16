/**
 * Unit tests for the Settings screen shell.
 *
 * @see src/admin/settings/SettingsApp.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import SettingsApp from '../../src/admin/settings/SettingsApp';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

// The tabs have their own tests; here only the shell's load handling matters.
jest.mock( '../../src/admin/settings/ProvidersTab', () => () => (
	<div data-testid="providers-tab" />
) );

describe( 'SettingsApp — loading settings', () => {
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

	it( 'says the settings could not be loaded instead of loading forever', async () => {
		apiFetch.mockRejectedValue( { message: 'offline' } );

		await act( async () => {
			root.render( <SettingsApp /> );
		} );

		expect( container.textContent ).toContain(
			'Your settings could not be loaded.'
		);
		expect( container.textContent ).not.toContain( 'Loading settings…' );
	} );

	it( 'loads the settings when the user tries again', async () => {
		apiFetch.mockRejectedValueOnce( { message: 'offline' } );
		apiFetch.mockResolvedValue( { api_keys: {} } );

		await act( async () => {
			root.render( <SettingsApp /> );
		} );

		const retry = Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent.includes( 'Try again' )
		);
		await act( async () => {
			retry.click();
		} );

		expect(
			container.querySelector( '[data-testid="providers-tab"]' )
		).not.toBeNull();
	} );
} );

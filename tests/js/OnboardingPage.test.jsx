/**
 * Unit tests for the onboarding wizard's usage-sharing consent and events.
 *
 * @see src/admin/dashboard/OnboardingPage.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import OnboardingPage from '../../src/admin/dashboard/OnboardingPage';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const REST = 'http://example.test/wp-json/plume/v1';
const URLS = {
	upgrade: 'https://wpaimind.com/pricing?utm_source=plugin&utm_medium=dashboard',
	chat: 'http://example.test/wp-admin/admin.php?page=plume-chat',
};

/**
 * Bodies posted with window.fetch, keyed by path suffix.
 *
 * @return {Array<{url: string, body: Object}>} Calls in order.
 */
function fetchCalls() {
	return window.fetch.mock.calls.map( ( [ url, opts ] ) => ( {
		url,
		body: JSON.parse( opts.body ),
	} ) );
}

/**
 * Flush pending promises inside act().
 */
async function flush() {
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	} );
}

describe( 'OnboardingPage usage sharing', () => {
	let container;
	let root;
	let consoleError;

	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( null );
		window.fetch = jest.fn( () =>
			Promise.resolve( {
				json: () => Promise.resolve( { success: true, registered: true } ),
			} )
		);
		// jsdom cannot navigate; silence its "Not implemented" notice for reload().
		consoleError = jest
			.spyOn( console, 'error' )
			.mockImplementation( ( ...args ) => {
				if ( ! String( args[ 0 ] ).includes( 'Not implemented' ) ) {
					// eslint-disable-next-line no-console
					console.warn( ...args );
				}
			} );
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		delete window.fetch;
		consoleError.mockRestore();
	} );

	async function render( props = {} ) {
		await act( async () => {
			root.render(
				<OnboardingPage
					nonce="n"
					restUrl={ REST }
					urls={ URLS }
					telemetryConsent="unset"
					telemetryAvailable
					{ ...props }
				/>
			);
		} );
	}

	const button = ( text ) =>
		Array.from( container.querySelectorAll( 'button' ) ).find(
			( b ) => b.textContent === text
		);

	it( 'shows the consent card when unset and available', async () => {
		await render();
		expect( container.querySelector( '.plume-ob-consent' ) ).not.toBeNull();
	} );

	it.each( [ 'yes', 'no' ] )(
		'hides the consent card once the choice is %s',
		async ( consent ) => {
			await render( { telemetryConsent: consent } );
			expect( container.querySelector( '.plume-ob-consent' ) ).toBeNull();
		}
	);

	it( 'hides the consent card when usage sharing is unavailable', async () => {
		await render( { telemetryAvailable: false } );
		expect( container.querySelector( '.plume-ob-consent' ) ).toBeNull();
	} );

	it( 'styles the two choices alike and pre-selects neither', async () => {
		await render();
		const yes = button( 'Share anonymous usage' );
		const no = button( 'No thanks' );
		expect( yes.className ).toBe( 'plume-dash-btn' );
		expect( no.className ).toBe( yes.className );
		expect( yes.getAttribute( 'aria-pressed' ) ).toBeNull();
		expect( container.querySelector( '.plume-dash-btn--primary' ).textContent ).toContain(
			'Get started'
		);
	} );

	it( 'saves yes through /settings with the onboarding source', async () => {
		await render();
		await act( async () => {
			button( 'Share anonymous usage' ).click();
		} );
		expect( fetchCalls() ).toEqual( [
			{
				url: `${ REST }/settings`,
				body: {
					telemetry_consent: 'yes',
					telemetry_source: 'onboarding',
				},
			},
		] );
	} );

	it( 'announces the choice in a status region that receives focus', async () => {
		await render();
		await act( async () => {
			button( 'No thanks' ).click();
		} );
		const status = container.querySelector( '[role="status"]' );
		expect( status.textContent ).toBe(
			'Thanks. You can change this in Settings → Features.'
		);
		expect( document.activeElement ).toBe( status );
		expect( button( 'Share anonymous usage' ) ).toBeUndefined();
	} );

	it( 'sends no step events before consent', async () => {
		await render();
		await act( async () => {
			button( 'Get started →' ).click();
		} );
		await flush();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'sends step events after consent yes', async () => {
		await render();
		await act( async () => {
			button( 'Share anonymous usage' ).click();
		} );
		await act( async () => {
			button( 'Get started →' ).click();
		} );
		await flush();
		const events = apiFetch.mock.calls.map( ( [ req ] ) => req.data );
		expect( events ).toEqual( [
			{
				event: 'onboarding_step',
				props: { step: 'welcome', action: 'completed' },
			},
			{
				event: 'onboarding_step',
				props: { step: 'done', action: 'viewed' },
			},
		] );
	} );

	it( 'sends the managed route with the onboarding request', async () => {
		await render();
		await act( async () => {
			button( 'Get started →' ).click();
		} );
		await flush();
		expect( fetchCalls() ).toContainEqual( {
			url: `${ REST }/onboarding`,
			body: { seen: true, route: 'managed' },
		} );
	} );

	it( 'sends the skipped route when setup is skipped', async () => {
		await render();
		await act( async () => {
			button( 'Skip setup' ).click();
		} );
		await flush();
		expect( fetchCalls() ).toContainEqual( {
			url: `${ REST }/onboarding`,
			body: { seen: true, route: 'skipped' },
		} );
	} );

	it( 'sends the own_key route and the providers step events', async () => {
		await render( { telemetryConsent: 'yes' } );
		await act( async () => {
			container.querySelectorAll( '.plume-ob-choice' )[ 1 ].click();
		} );
		await act( async () => {
			button( 'Get started →' ).click();
		} );
		await flush();
		await act( async () => {
			button( 'Finish setup →' ).click();
		} );
		await flush();

		expect( fetchCalls() ).toContainEqual(
			expect.objectContaining( {
				url: `${ REST }/onboarding`,
				body: expect.objectContaining( { seen: true, route: 'own_key' } ),
			} )
		);
		const steps = apiFetch.mock.calls.map( ( [ req ] ) => req.data.props );
		expect( steps ).toEqual( [
			{ step: 'welcome', action: 'completed' },
			{ step: 'providers', action: 'viewed' },
			{ step: 'providers', action: 'completed' },
			{ step: 'done', action: 'viewed' },
		] );
	} );

	it( 'tags the Pro link and records the upgrade route and click', async () => {
		await render();
		await act( async () => {
			container.querySelectorAll( '.plume-ob-choice' )[ 2 ].click();
		} );
		const link = container.querySelector( 'a.plume-dash-btn--primary' );
		const href = new URL( link.getAttribute( 'href' ) );
		expect( href.searchParams.get( 'utm_source' ) ).toBe( 'plugin' );
		expect( href.searchParams.get( 'utm_medium' ) ).toBe( 'onboarding' );

		await act( async () => {
			link.click();
		} );
		await flush();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			event: 'upgrade_click',
			props: {
				placement: 'onboarding',
				screen: 'dashboard',
				plan: 'pricing',
			},
		} );
		expect( fetchCalls() ).toContainEqual( {
			url: `${ REST }/onboarding`,
			body: { seen: true, route: 'upgrade' },
		} );
	} );
} );

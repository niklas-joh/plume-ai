/**
 * Unit tests for the shared OutOfCreditsNotice banner.
 *
 * @see src/shared/OutOfCreditsNotice.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import OutOfCreditsNotice from '../../src/shared/OutOfCreditsNotice';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'OutOfCreditsNotice', () => {
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

	it( 'renders the out-of-credits message', async () => {
		await act( async () => {
			root.render(
				<OutOfCreditsNotice websiteUrl="https://wpaimind.com" />
			);
		} );

		expect( container.textContent ).toContain(
			'You’ve used all your credits for this billing period.'
		);
	} );

	it( 'links to the pricing page using the supplied websiteUrl', async () => {
		await act( async () => {
			root.render(
				<OutOfCreditsNotice websiteUrl="https://example.test" />
			);
		} );

		const link = container.querySelector( 'a' );
		expect( link ).not.toBeNull();
		expect( link.getAttribute( 'href' ) ).toBe(
			'https://example.test/pricing'
		);
		expect( link.textContent ).toContain( 'Get more credits' );
	} );

	it( 'does not mention upgrading to Pro', async () => {
		await act( async () => {
			root.render(
				<OutOfCreditsNotice websiteUrl="https://wpaimind.com" />
			);
		} );

		expect( container.textContent ).not.toContain( 'Upgrade to Pro' );
	} );

	it( 'offers the free own-key route when a settings URL is supplied', async () => {
		await act( async () => {
			root.render(
				<OutOfCreditsNotice
					websiteUrl="https://wpaimind.com"
					settingsUrl="https://example.test/wp-admin/admin.php?page=plume-settings"
				/>
			);
		} );

		const links = Array.from( container.querySelectorAll( 'a' ) );
		const ownKey = links.find( ( a ) =>
			a.textContent.includes( 'own API key' )
		);
		expect( ownKey ).toBeDefined();
		expect( ownKey.getAttribute( 'href' ) ).toBe(
			'https://example.test/wp-admin/admin.php?page=plume-settings'
		);
	} );

	it( 'omits the own-key link when the user cannot open Settings', async () => {
		await act( async () => {
			root.render(
				<OutOfCreditsNotice
					websiteUrl="https://wpaimind.com"
					settingsUrl=""
				/>
			);
		} );

		expect( container.querySelectorAll( 'a' ).length ).toBe( 1 );
	} );
} );

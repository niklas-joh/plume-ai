/**
 * Unit tests for the shared usage-event helpers.
 *
 * @see src/shared/telemetry.js
 */
import apiFetch from '@wordpress/api-fetch';
import {
	recordClientEvent,
	recordUpgradeClick,
	withUtm,
} from '../../src/shared/telemetry';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'withUtm', () => {
	it( 'tags wpaimind.com links', () => {
		expect(
			withUtm( 'https://wpaimind.com/pricing', 'chat', 'out_of_credits' )
		).toBe(
			'https://wpaimind.com/pricing?utm_source=plugin&utm_medium=chat&utm_content=out_of_credits'
		);
	} );

	it( 'leaves out utm_content when none is given', () => {
		expect( withUtm( 'https://wpaimind.com/docs', 'dashboard' ) ).toBe(
			'https://wpaimind.com/docs?utm_source=plugin&utm_medium=dashboard'
		);
	} );

	it( 'overwrites existing UTM values and keeps other parameters', () => {
		const tagged = new URL(
			withUtm(
				'https://wpaimind.com/pricing?ref=a&utm_source=x&utm_medium=dashboard&utm_content=old',
				'onboarding'
			)
		);
		expect( tagged.searchParams.get( 'ref' ) ).toBe( 'a' );
		expect( tagged.searchParams.get( 'utm_source' ) ).toBe( 'plugin' );
		expect( tagged.searchParams.get( 'utm_medium' ) ).toBe( 'onboarding' );
		expect( tagged.searchParams.has( 'utm_content' ) ).toBe( false );
		expect( tagged.searchParams.getAll( 'utm_medium' ) ).toHaveLength( 1 );
	} );

	it( 'leaves other hosts and invalid URLs unchanged', () => {
		const checkout =
			'https://plume.lemonsqueezy.com/checkout/buy/1?checkout[custom][site_token]=t';
		expect( withUtm( checkout, 'dashboard' ) ).toBe( checkout );
		expect( withUtm( 'https://example.test/pricing', 'chat' ) ).toBe(
			'https://example.test/pricing'
		);
		expect( withUtm( 'not a url', 'chat' ) ).toBe( 'not a url' );
		expect( withUtm( undefined, 'chat' ) ).toBeUndefined();
	} );
} );

describe( 'recordClientEvent', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'posts the event to the site REST API with keepalive', () => {
		apiFetch.mockResolvedValue( null );
		recordClientEvent( 'onboarding_step', {
			step: 'welcome',
			action: 'completed',
		} );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/plume/v1/telemetry/event',
			method: 'POST',
			data: {
				event: 'onboarding_step',
				props: { step: 'welcome', action: 'completed' },
			},
			keepalive: true,
		} );
	} );

	it( 'swallows a rejected request', async () => {
		apiFetch.mockRejectedValue( new Error( 'offline' ) );
		expect( () => recordClientEvent( 'upgrade_click' ) ).not.toThrow();
		// Let the rejection settle; an unhandled rejection would fail the test.
		await Promise.resolve();
	} );

	it( 'swallows a synchronous throw', () => {
		apiFetch.mockImplementation( () => {
			throw new Error( 'boom' );
		} );
		expect( () => recordClientEvent( 'upgrade_click' ) ).not.toThrow();
	} );

	it( 'recordUpgradeClick defaults the plan to pricing', () => {
		apiFetch.mockResolvedValue( null );
		recordUpgradeClick( 'out_of_credits', 'seo' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			event: 'upgrade_click',
			props: {
				placement: 'out_of_credits',
				screen: 'seo',
				plan: 'pricing',
			},
		} );
	} );
} );

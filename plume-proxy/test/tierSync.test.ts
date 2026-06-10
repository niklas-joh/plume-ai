/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect, vi, afterEach } from 'vitest';
import { pushTierUpdate } from '../src/tierSync';

afterEach( () => {
	vi.restoreAllMocks();
	vi.unstubAllGlobals();
} );

describe( 'pushTierUpdate', () => {
	it( 'aborts after one attempt on 4xx', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			new Response( null, { status: 403 } )
		);
		vi.stubGlobal( 'fetch', fetchMock );

		await pushTierUpdate( 'https://example.com', 'secret', 'pro_managed' );

		expect( fetchMock ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'retries on 5xx and succeeds on second attempt', async () => {
		const fetchMock = vi
			.fn()
			.mockResolvedValueOnce( new Response( null, { status: 503 } ) )
			.mockResolvedValueOnce( new Response( null, { status: 200 } ) );
		vi.stubGlobal( 'fetch', fetchMock );

		// Override the backoff delay so the test does not wait 1 second.
		vi.stubGlobal( 'setTimeout', ( fn: () => void, _delay?: number ) => fn() );

		await pushTierUpdate( 'https://example.com', 'secret', 'pro_managed' );

		expect( fetchMock ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'gives up silently after all three 5xx attempts', async () => {
		const fetchMock = vi.fn().mockResolvedValue(
			new Response( null, { status: 503 } )
		);
		vi.stubGlobal( 'fetch', fetchMock );
		vi.stubGlobal( 'setTimeout', ( fn: () => void, _delay?: number ) => fn() );

		await expect(
			pushTierUpdate( 'https://example.com', 'secret', 'pro_managed' )
		).resolves.toBeUndefined();

		expect( fetchMock ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'aborts without fetching when signing fails', async () => {
		vi.spyOn( crypto.subtle, 'sign' ).mockRejectedValue( new Error( 'sign failed' ) );
		const fetchMock = vi.fn();
		vi.stubGlobal( 'fetch', fetchMock );

		await expect(
			pushTierUpdate( 'https://example.com', 'secret', 'pro_managed' )
		).resolves.toBeUndefined();

		expect( fetchMock ).not.toHaveBeenCalled();
	} );
} );

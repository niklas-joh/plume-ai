/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import worker from '../src/index';
import { makeEnv } from './helpers/kv-mock';
import type { SiteRecord } from '../src/types';

const TEST_TOKEN =
	'1111aaaa2222bbbb3333cccc4444dddd5555eeee6666ffff1111aaaa2222bbbb';

function makeRotateRequest( authHeader?: string ): Request {
	const headers: Record< string, string > = {
		'Content-Type': 'application/json',
	};
	if ( authHeader !== undefined ) {
		headers.Authorization = authHeader;
	}
	return new Request( 'https://worker.example.com/rotate-secret', {
		method: 'POST',
		headers,
		body: '{}',
	} );
}

function makeCtx(): ExecutionContext {
	return {
		waitUntil: () => {},
		passThroughOnException: () => {},
		props: {},
	} as unknown as ExecutionContext;
}

async function seedSite(
	env: ReturnType< typeof makeEnv >,
	overrides: Partial< SiteRecord > = {}
): Promise< SiteRecord > {
	const record: SiteRecord = {
		site_url: 'https://wp.example.com',
		tier: 'pro_managed',
		created_at: Date.now(),
		tier_sync_secret: 'a'.repeat( 64 ),
		...overrides,
	};
	await env.USAGE_KV.put( `site:${ TEST_TOKEN }`, JSON.stringify( record ) );
	return record;
}

describe( '/rotate-secret', () => {
	it( 'returns 405 for non-POST requests', async () => {
		const env = makeEnv();
		const req = new Request( 'https://worker.example.com/rotate-secret', {
			method: 'GET',
		} );
		const res = await worker.fetch( req, env, makeCtx() );
		expect( res.status ).toBe( 405 );
	} );

	it( 'returns 401 when Authorization header is missing', async () => {
		const env = makeEnv();
		await seedSite( env );

		const res = await worker.fetch(
			makeRotateRequest(),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 for an unknown bearer token', async () => {
		const env = makeEnv();
		await seedSite( env );

		const res = await worker.fetch(
			makeRotateRequest( 'Bearer unknown-token' ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 200 with a new secret for a valid bearer token', async () => {
		const env = makeEnv();
		const original = await seedSite( env );

		const res = await worker.fetch(
			makeRotateRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 200 );

		const data = ( await res.json() ) as {
			tier_sync_secret: string;
			tier: string;
		};
		expect( data.tier_sync_secret ).toMatch( /^[0-9a-f]{64}$/ );
		expect( data.tier_sync_secret ).not.toBe( original.tier_sync_secret );
		expect( data.tier ).toBe( original.tier );
	} );

	it( 'persists the rotated secret on the SiteRecord', async () => {
		const env = makeEnv();
		const original = await seedSite( env );

		const res = await worker.fetch(
			makeRotateRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		const data = ( await res.json() ) as { tier_sync_secret: string };

		const stored = await env.USAGE_KV.get< SiteRecord >(
			`site:${ TEST_TOKEN }`,
			'json'
		);
		expect( stored?.tier_sync_secret ).toBe( data.tier_sync_secret );
		expect( stored?.tier_sync_secret ).not.toBe( original.tier_sync_secret );
		// Other fields preserved.
		expect( stored?.site_url ).toBe( original.site_url );
		expect( stored?.tier ).toBe( original.tier );
	} );

	it( 'rotating again issues yet another fresh secret (idempotent semantics)', async () => {
		const env = makeEnv();
		await seedSite( env );

		const res1 = await worker.fetch(
			makeRotateRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		const data1 = ( await res1.json() ) as { tier_sync_secret: string };

		const res2 = await worker.fetch(
			makeRotateRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		const data2 = ( await res2.json() ) as { tier_sync_secret: string };

		expect( data1.tier_sync_secret ).not.toBe( data2.tier_sync_secret );
	} );
} );

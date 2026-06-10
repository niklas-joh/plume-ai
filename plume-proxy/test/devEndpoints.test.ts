/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import { createHmac } from 'crypto';
import worker from '../src/index';
import { makeEnv } from './helpers/kv-mock';
import type { SiteRecord } from '../src/types';

const TEST_TOKEN =
	'aaaa1111bbbb2222cccc3333dddd4444eeee5555ffff6666aaaa1111bbbb2222';
const TEST_SECRET =
	'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

/**
 * Dev-environment override: enables the /dev/* routes that are absent in production.
 *
 * @param {Partial<ReturnType<typeof makeEnv>>} overrides Optional binding overrides.
 * @return {Env} Env fixture with DEV_ENDPOINTS_ENABLED set.
 */
function makeDevEnv( overrides: Partial< ReturnType< typeof makeEnv > > = {} ) {
	return makeEnv( { DEV_ENDPOINTS_ENABLED: '1', ...overrides } );
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
		tier: 'free',
		created_at: Date.now(),
		tier_sync_secret: TEST_SECRET,
		...overrides,
	};
	await env.USAGE_KV.put( `site:${ TEST_TOKEN }`, JSON.stringify( record ) );
	return record;
}

function signDevRequest(
	timestamp: number,
	body: string,
	secret = TEST_SECRET
): string {
	return createHmac( 'sha256', secret )
		.update( `${ timestamp }.${ body }` )
		.digest( 'hex' );
}

function makeDevRequest(
	pathname: string,
	body: string,
	opts: {
		signature?: string;
		timestamp?: number;
		authToken?: string;
		secret?: string;
	} = {}
): Request {
	const ts = opts.timestamp ?? Math.floor( Date.now() / 1000 );
	const sig =
		opts.signature ??
		signDevRequest( ts, body, opts.secret ?? TEST_SECRET );
	return new Request( `https://worker.example.com${ pathname }`, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Authorization: `Bearer ${ opts.authToken ?? TEST_TOKEN }`,
			'X-Dev-Signature': sig,
			'X-Dev-Timestamp': String( ts ),
		},
		body,
	} );
}

// ── DEV_ENDPOINTS_ENABLED gate ────────────────────────────────────────────────

describe( 'dev endpoints env gate', () => {
	it( 'returns 404 when DEV_ENDPOINTS_ENABLED is not set', async () => {
		const env = makeEnv(); // intentionally omits DEV_ENDPOINTS_ENABLED
		const res = await worker.fetch(
			new Request( 'https://worker.example.com/dev/set-tier', {
				method: 'POST',
			} ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 404 );
	} );
} );

// ── authenticateDevRequest ────────────────────────────────────────────────────

describe( 'authenticateDevRequest', () => {
	it( 'returns 401 when Authorization header is missing', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { tier: 'trial' } );
		const ts = Math.floor( Date.now() / 1000 );
		const req = new Request( 'https://worker.example.com/dev/set-tier', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-Dev-Signature': signDevRequest( ts, body ),
				'X-Dev-Timestamp': String( ts ),
			},
			body,
		} );
		const res = await worker.fetch( req, env, makeCtx() );
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 when tier_sync_secret is absent from the site record', async () => {
		const env = makeDevEnv();
		await seedSite( env, { tier_sync_secret: undefined } );
		const body = JSON.stringify( { tier: 'trial' } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 when X-Dev-Signature header is missing', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { tier: 'trial' } );
		const ts = Math.floor( Date.now() / 1000 );
		const req = new Request( 'https://worker.example.com/dev/set-tier', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${ TEST_TOKEN }`,
				'X-Dev-Timestamp': String( ts ),
			},
			body,
		} );
		const res = await worker.fetch( req, env, makeCtx() );
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 for a malformed (odd-length) hex signature', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { tier: 'trial' } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body, { signature: 'abc' } ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 when the HMAC does not match', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { tier: 'trial' } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body, { secret: 'wrong-secret' } ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 when timestamp is too far in the past (>60 s)', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { tier: 'trial' } );
		const oldTs = Math.floor( Date.now() / 1000 ) - 120;
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body, { timestamp: oldTs } ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 when timestamp is too far in the future (>60 s)', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { tier: 'trial' } );
		const futureTs = Math.floor( Date.now() / 1000 ) + 120;
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body, { timestamp: futureTs } ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );
} );

// ── handleDevSetTier ──────────────────────────────────────────────────────────

describe( '/dev/set-tier', () => {
	it( 'returns 200 and updates the tier for a valid request', async () => {
		const env = makeDevEnv();
		await seedSite( env, { tier: 'free' } );
		const body = JSON.stringify( { tier: 'trial' } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 200 );
		const json = ( await res.json() ) as { ok: boolean; tier: string };
		expect( json.ok ).toBe( true );
		expect( json.tier ).toBe( 'trial' );

		const stored = await env.USAGE_KV.get< SiteRecord >(
			`site:${ TEST_TOKEN }`,
			'json'
		);
		expect( stored?.tier ).toBe( 'trial' );
	} );

	it( 'returns 400 for an invalid tier string', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { tier: 'super_premium' } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 400 );
	} );

	it( 'returns 400 for invalid JSON body', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = 'not-json';
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-tier', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 400 );
	} );

	it( 'returns 405 for a non-POST request', async () => {
		const env = makeDevEnv();
		const res = await worker.fetch(
			new Request( 'https://worker.example.com/dev/set-tier', {
				method: 'GET',
			} ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 405 );
	} );

	it( 'accepts all valid SiteTier values', async () => {
		const tiers = [ 'free', 'trial', 'pro_managed', 'pro_byok' ] as const;
		for ( const tier of tiers ) {
			const env = makeDevEnv();
			await seedSite( env );
			const body = JSON.stringify( { tier } );
			const res = await worker.fetch(
				makeDevRequest( '/dev/set-tier', body ),
				env,
				makeCtx()
			);
			expect( res.status, `tier=${ tier }` ).toBe( 200 );
		}
	} );
} );

// ── handleDevResetUsage ───────────────────────────────────────────────────────

describe( '/dev/reset-usage', () => {
	it( 'returns 200 and zeroes the usage KV key', async () => {
		const env = makeDevEnv();
		await seedSite( env );

		const now = new Date();
		const month = `${ now.getFullYear() }-${ String(
			now.getMonth() + 1
		).padStart( 2, '0' ) }`;
		await env.USAGE_KV.put( `usage:${ TEST_TOKEN }:${ month }`, '99999' );

		const body = '{}';
		const res = await worker.fetch(
			makeDevRequest( '/dev/reset-usage', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 200 );
		const json = ( await res.json() ) as { ok: boolean; usage: number };
		expect( json.ok ).toBe( true );
		expect( json.usage ).toBe( 0 );

		const stored = await env.USAGE_KV.get(
			`usage:${ TEST_TOKEN }:${ month }`
		);
		expect( stored ).toBe( '0' );
	} );
} );

// ── handleDevSetUsage ─────────────────────────────────────────────────────────

describe( '/dev/set-usage', () => {
	it( 'returns 200 and persists the usage value', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { usage: 42000 } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-usage', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 200 );
		const json = ( await res.json() ) as { ok: boolean; usage: number };
		expect( json.ok ).toBe( true );
		expect( json.usage ).toBe( 42000 );
	} );

	it( 'returns 400 for a negative usage value', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { usage: -1 } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-usage', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 400 );
	} );

	it( 'returns 400 when usage is not a number', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const body = JSON.stringify( { usage: 'lots' } );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-usage', body ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 400 );
	} );

	it( 'returns 400 for invalid JSON body', async () => {
		const env = makeDevEnv();
		await seedSite( env );
		const res = await worker.fetch(
			makeDevRequest( '/dev/set-usage', 'bad-json' ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 400 );
	} );
} );

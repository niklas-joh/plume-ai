/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect, vi, afterEach } from 'vitest';
import {
	handleRegistration,
	handleActivationChallenge,
	REGISTRATION_RATE_LIMIT,
} from '../src/registration';
import { makeEnv } from './helpers/kv-mock';

afterEach( () => {
	vi.restoreAllMocks();
} );

/** Seed a valid challenge in KV and stub fetch to return 200 for the callback. */
async function makeEnvWithChallenge(
	challenge: string,
	callbackOk = true
): Promise< ReturnType< typeof makeEnv > > {
	const env = makeEnv();
	await env.USAGE_KV.put( `challenge:${ challenge }`, '1' );
	vi.stubGlobal(
		'fetch',
		vi.fn().mockResolvedValue( new Response( '{}', { status: callbackOk ? 200 : 500 } ) )
	);
	return env;
}

function makeRequest(
	opts: {
		method?: string;
		body?: unknown;
		headers?: Record< string, string >;
	} = {}
): Request {
	const { method = 'POST', body, headers = {} } = opts;
	return new Request( 'https://worker.example.com/register', {
		method,
		body: body !== undefined ? JSON.stringify( body ) : undefined,
		headers: {
			'Content-Type': 'application/json',
			'CF-Connecting-IP': '127.0.0.1',
			...headers,
		},
	} );
}

// ── handleActivationChallenge ──────────────────────────────────────────────

describe( 'handleActivationChallenge', () => {
	it( 'returns 405 for a POST request', async () => {
		const env = makeEnv();
		const req = new Request( 'https://worker.example.com/activation-challenge', {
			method: 'POST',
		} );
		const res = await handleActivationChallenge( req, env );
		expect( res.status ).toBe( 405 );
	} );

	it( 'returns a 64-char hex challenge and stores it in KV', async () => {
		const env = makeEnv();
		const req = new Request( 'https://worker.example.com/activation-challenge', {
			method: 'GET',
		} );
		const res = await handleActivationChallenge( req, env );
		expect( res.status ).toBe( 200 );
		const data = ( await res.json() ) as { challenge: string };
		expect( data.challenge ).toMatch( /^[0-9a-f]{64}$/ );
		const stored = await env.USAGE_KV.get( `challenge:${ data.challenge }` );
		expect( stored ).toBe( '1' );
	} );
} );

// ── handleRegistration ──────────────────────────────────────────────────────

describe( 'handleRegistration', () => {
	it( 'returns 405 for a GET request', async () => {
		const env = makeEnv();
		const res = await handleRegistration(
			makeRequest( { method: 'GET' } ),
			env
		);
		expect( res.status ).toBe( 405 );
	} );

	it( 'returns 400 when site_url is missing from body', async () => {
		const env = makeEnv();
		const res = await handleRegistration(
			makeRequest( { body: {} } ),
			env
		);
		expect( res.status ).toBe( 400 );
		const data = ( await res.json() ) as { error: string };
		expect( data.error ).toMatch( /site_url/i );
	} );

	it( 'returns 400 for a non-http/https site_url', async () => {
		const env = makeEnv();
		const res = await handleRegistration(
			makeRequest( { body: { site_url: 'ftp://example.com' } } ),
			env
		);
		expect( res.status ).toBe( 400 );
	} );

	it( 'returns 403 when challenge_token is missing', async () => {
		const env = makeEnv();
		const res = await handleRegistration(
			makeRequest( { body: { site_url: 'https://example.com' } } ),
			env
		);
		expect( res.status ).toBe( 403 );
		const data = ( await res.json() ) as { error: string };
		expect( data.error ).toMatch( /challenge_token/i );
	} );

	it( 'returns 403 when challenge_token is not in KV (invalid/expired)', async () => {
		const env = makeEnv();
		const res = await handleRegistration(
			makeRequest( {
				body: {
					site_url: 'https://example.com',
					challenge_token: 'a'.repeat( 64 ),
				},
			} ),
			env
		);
		expect( res.status ).toBe( 403 );
		const data = ( await res.json() ) as { error: string };
		expect( data.error ).toMatch( /invalid or expired/i );
	} );

	it( 'returns 403 when the site callback returns non-200', async () => {
		const challenge = 'b'.repeat( 64 );
		const env = await makeEnvWithChallenge( challenge, false );
		const res = await handleRegistration(
			makeRequest( {
				body: {
					site_url: 'https://example.com',
					challenge_token: challenge,
				},
			} ),
			env
		);
		expect( res.status ).toBe( 403 );
		const data = ( await res.json() ) as { error: string };
		expect( data.error ).toMatch( /site verification failed/i );
	} );

	it( 'returns 201 and tier=trial for a valid new registration', async () => {
		const challenge = 'c'.repeat( 64 );
		const env = await makeEnvWithChallenge( challenge );
		const res = await handleRegistration(
			makeRequest( {
				body: {
					site_url: 'https://example.com',
					challenge_token: challenge,
				},
			} ),
			env
		);
		expect( res.status ).toBe( 201 );
		const data = ( await res.json() ) as {
			token: string;
			tier: string;
			tier_sync_secret: string;
		};
		expect( typeof data.token ).toBe( 'string' );
		expect( data.token.length ).toBeGreaterThan( 0 );
		expect( data.tier ).toBe( 'trial' );
		// New: secret is returned in response and is a 64-char hex token.
		expect( data.tier_sync_secret ).toMatch( /^[0-9a-f]{64}$/ );

		// New: the secret is persisted on the SiteRecord in KV.
		const stored = await env.USAGE_KV.get< import('../src/types').SiteRecord >(
			`site:${ data.token }`,
			'json'
		);
		expect( stored?.tier_sync_secret ).toBe( data.tier_sync_secret );
	} );

	it( 'calls the activation-verify endpoint with the correct stilus/v1 URL', async () => {
		const challenge = 'a'.repeat( 64 );
		const fetchSpy = vi
			.fn()
			.mockResolvedValue( new Response( '{}', { status: 200 } ) );
		vi.stubGlobal( 'fetch', fetchSpy );
		const env = makeEnv();
		await env.USAGE_KV.put( `challenge:${ challenge }`, '1' );

		await handleRegistration(
			makeRequest( { body: { site_url: 'https://site.example.com', challenge_token: challenge } } ),
			env
		);

		expect( fetchSpy ).toHaveBeenCalledTimes( 1 );
		const [ calledUrl ] = fetchSpy.mock.calls[ 0 ] as [ string ];
		expect( calledUrl ).toContain( '/wp-json/stilus/v1/activation-verify' );
	} );

	it( 'consumes the challenge (single-use)', async () => {
		const challenge = 'd'.repeat( 64 );
		const env = await makeEnvWithChallenge( challenge );

		await handleRegistration(
			makeRequest( {
				body: {
					site_url: 'https://used-once.example.com',
					challenge_token: challenge,
				},
			} ),
			env
		);

		// Second attempt with same challenge token must fail.
		vi.restoreAllMocks();
		const res2 = await handleRegistration(
			makeRequest( {
				body: {
					site_url: 'https://used-once.example.com',
					challenge_token: challenge,
				},
			} ),
			env
		);
		expect( res2.status ).toBe( 403 );
	} );

	it( 're-registering the same URL returns the same token (idempotent)', async () => {
		const challenge1 = 'e'.repeat( 64 );
		const env = await makeEnvWithChallenge( challenge1 );
		const body = { site_url: 'https://idempotent-site.example.com' };

		const res1 = await handleRegistration(
			makeRequest( { body: { ...body, challenge_token: challenge1 } } ),
			env
		);
		const data1 = ( await res1.json() ) as { token: string };

		// Seed a second challenge for the second call.
		const challenge2 = 'f'.repeat( 64 );
		await env.USAGE_KV.put( `challenge:${ challenge2 }`, '1' );
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( new Response( '{}', { status: 200 } ) )
		);

		const res2 = await handleRegistration(
			makeRequest( { body: { ...body, challenge_token: challenge2 } } ),
			env
		);
		const data2 = ( await res2.json() ) as { token: string };

		expect( data1.token ).toBe( data2.token );
	} );

	it( 'idempotent re-registration of an expired trial returns tier=free', async () => {
		const challenge1 = '1'.repeat( 64 );
		const env = await makeEnvWithChallenge( challenge1 );
		const siteUrl = 'https://expired-trial.example.com';

		// Initial registration — creates trial record.
		const res1 = await handleRegistration(
			makeRequest( { body: { site_url: siteUrl, challenge_token: challenge1 } } ),
			env
		);
		const { token } = ( await res1.json() ) as { token: string };

		// Backdate trial_started_at by 31 days.
		const stored = await env.USAGE_KV.get< import('../src/types').SiteRecord >(
			`site:${ token }`,
			'json'
		) as import('../src/types').SiteRecord;
		const expired = { ...stored, trial_started_at: Date.now() - 31 * 24 * 60 * 60 * 1000 };
		await env.USAGE_KV.put( `site:${ token }`, JSON.stringify( expired ) );

		// Re-register — must come back as free.
		const challenge2 = '2'.repeat( 64 );
		await env.USAGE_KV.put( `challenge:${ challenge2 }`, '1' );
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( new Response( '{}', { status: 200 } ) )
		);

		const res2 = await handleRegistration(
			makeRequest( { body: { site_url: siteUrl, challenge_token: challenge2 } } ),
			env
		);
		const data2 = ( await res2.json() ) as { token: string; tier: string };
		expect( data2.token ).toBe( token );
		expect( data2.tier ).toBe( 'free' );
	} );

	it( 'returns 429 when IP exceeds the registration rate limit', async () => {
		const env = makeEnv();
		const ip = '203.0.113.42';

		for ( let i = 1; i <= REGISTRATION_RATE_LIMIT; i++ ) {
			const ch = String( i ).padStart( 2, '0' ).repeat( 32 );
			await env.USAGE_KV.put( `challenge:${ ch }`, '1' );
			vi.stubGlobal(
				'fetch',
				vi.fn().mockResolvedValue( new Response( '{}', { status: 200 } ) )
			);
			await handleRegistration(
				makeRequest( {
					body: {
						site_url: `https://site${ i }.example.com`,
						challenge_token: ch,
					},
					headers: { 'CF-Connecting-IP': ip },
				} ),
				env
			);
		}

		const chExtra = '99'.repeat( 32 );
		await env.USAGE_KV.put( `challenge:${ chExtra }`, '1' );
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( new Response( '{}', { status: 200 } ) )
		);

		const res = await handleRegistration(
			makeRequest( {
				body: {
					site_url: 'https://site6.example.com',
					challenge_token: chExtra,
				},
				headers: { 'CF-Connecting-IP': ip },
			} ),
			env
		);
		expect( res.status ).toBe( 429 );
	} );
} );

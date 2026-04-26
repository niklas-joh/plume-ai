/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import { handleRegistration } from '../src/registration';
import { makeEnv } from './helpers/kv-mock';

function makeRequest( opts: {
	method?: string;
	body?: unknown;
	headers?: Record<string, string>;
} = {} ): Request {
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

describe( 'handleRegistration', () => {
	it( 'returns 405 for a GET request', async () => {
		const env = makeEnv();
		const res = await handleRegistration( makeRequest( { method: 'GET' } ), env );
		expect( res.status ).toBe( 405 );
	} );

	it( 'returns 400 when site_url is missing from body', async () => {
		const env = makeEnv();
		const res = await handleRegistration( makeRequest( { body: {} } ), env );
		expect( res.status ).toBe( 400 );
		const data = await res.json() as { error: string };
		expect( data.error ).toMatch( /site_url/i );
	} );

	it( 'returns 400 for a non-http/https site_url', async () => {
		const env = makeEnv();
		const res = await handleRegistration(
			makeRequest( { body: { site_url: 'ftp://example.com' } } ),
			env,
		);
		expect( res.status ).toBe( 400 );
	} );

	it( 'returns 200/201 and a token for a valid new registration', async () => {
		const env = makeEnv();
		const res = await handleRegistration(
			makeRequest( { body: { site_url: 'https://example.com' } } ),
			env,
		);
		// spec allows 200 or 201 for new registration
		expect( [ 200, 201 ] ).toContain( res.status );
		const data = await res.json() as { token: string; tier: string };
		expect( typeof data.token ).toBe( 'string' );
		expect( data.token.length ).toBeGreaterThan( 0 );
		expect( data.tier ).toBe( 'free' );
	} );

	it( 're-registering the same URL returns the same token (idempotent)', async () => {
		const env = makeEnv();
		const body = { site_url: 'https://idempotent-site.example.com' };

		const res1 = await handleRegistration( makeRequest( { body } ), env );
		const data1 = await res1.json() as { token: string };

		const res2 = await handleRegistration( makeRequest( { body } ), env );
		const data2 = await res2.json() as { token: string };

		expect( data1.token ).toBe( data2.token );
	} );

	it( 'returns 429 when IP exceeds the registration rate limit', async () => {
		const env = makeEnv();
		const ip = '203.0.113.42';

		// exhaust the 5-per-hour limit
		for ( let i = 1; i <= 5; i++ ) {
			await handleRegistration(
				makeRequest( {
					body: { site_url: `https://site${ i }.example.com` },
					headers: { 'CF-Connecting-IP': ip },
				} ),
				env,
			);
		}

		const res = await handleRegistration(
			makeRequest( {
				body: { site_url: 'https://site6.example.com' },
				headers: { 'CF-Connecting-IP': ip },
			} ),
			env,
		);
		expect( res.status ).toBe( 429 );
	} );
} );

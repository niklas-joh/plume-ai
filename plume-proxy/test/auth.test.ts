/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import { authenticateRequest } from '../src/auth';
import { makeEnv } from './helpers/kv-mock';
import type { SiteRecord } from '../src/types';

function makeRequest( headers: Record< string, string > = {} ): Request {
	return new Request( 'https://worker.example.com/v1/chat', {
		method: 'POST',
		headers,
	} );
}

const TEST_TOKEN =
	'deadbeef1234567890abcdef1234567890abcdef1234567890abcdef12345678';

async function makeEnvWithSiteToken( tier: SiteRecord[ 'tier' ] = 'free' ) {
	const env = makeEnv();
	const record: SiteRecord = {
		site_url: 'https://example.com',
		tier,
		created_at: Date.now(),
	};
	await env.USAGE_KV.put( `site:${ TEST_TOKEN }`, JSON.stringify( record ) );
	return env;
}

describe( 'authenticateRequest', () => {
	it( 'returns authenticated: false when Authorization header is missing', async () => {
		const env = makeEnv();
		const result = await authenticateRequest( makeRequest(), env );
		expect( result.authenticated ).toBe( false );
	} );

	it( 'returns authenticated: false for malformed header (no "Bearer " prefix)', async () => {
		const env = makeEnv();
		const result = await authenticateRequest(
			makeRequest( { Authorization: `Token ${ TEST_TOKEN }` } ),
			env
		);
		expect( result.authenticated ).toBe( false );
	} );

	it( 'returns authenticated: false when token is not in KV', async () => {
		const env = makeEnv(); // empty KV
		const result = await authenticateRequest(
			makeRequest( { Authorization: `Bearer ${ TEST_TOKEN }` } ),
			env
		);
		expect( result.authenticated ).toBe( false );
	} );

	it( 'returns authenticated: true with site_token and tier for a valid token', async () => {
		const env = await makeEnvWithSiteToken( 'pro_managed' );
		const result = await authenticateRequest(
			makeRequest( { Authorization: `Bearer ${ TEST_TOKEN }` } ),
			env
		);
		expect( result.authenticated ).toBe( true );
		expect( result.site_token ).toBe( TEST_TOKEN );
		expect( result.tier ).toBe( 'pro_managed' );
	} );

	it( 'returns tier: trial for a trial-registered site', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );
		const result = await authenticateRequest(
			makeRequest( { Authorization: `Bearer ${ TEST_TOKEN }` } ),
			env
		);
		expect( result.authenticated ).toBe( true );
		expect( result.tier ).toBe( 'trial' );
	} );

	it( 'returns tier: free for a free-tier site', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		const result = await authenticateRequest(
			makeRequest( { Authorization: `Bearer ${ TEST_TOKEN }` } ),
			env
		);
		expect( result.authenticated ).toBe( true );
		expect( result.tier ).toBe( 'free' );
	} );

	it( 'returns authenticated: false when Bearer token is empty string', async () => {
		const env = makeEnv();
		const result = await authenticateRequest(
			makeRequest( { Authorization: 'Bearer ' } ),
			env
		);
		expect( result.authenticated ).toBe( false );
	} );
} );

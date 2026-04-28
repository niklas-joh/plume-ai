/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import worker from '../src/index';
import { makeEnv } from './helpers/kv-mock';
import type { SiteRecord } from '../src/types';

const TEST_TOKEN =
	'deadbeef1234567890abcdef1234567890abcdef1234567890abcdef12345678';

const VALID_BODY = JSON.stringify( {
	messages: [ { role: 'user', content: 'Hello' } ],
} );

async function makeEnvWithSiteToken( tier: SiteRecord[ 'tier' ] ) {
	const env = makeEnv();
	const record: SiteRecord = {
		site_url: 'https://example.com',
		tier,
		created_at: Date.now(),
	};
	await env.USAGE_KV.put( `site:${ TEST_TOKEN }`, JSON.stringify( record ) );
	return env;
}

describe( 'handleChatProxy', () => {
	it( 'returns 403 for pro_byok tier', async () => {
		const env = await makeEnvWithSiteToken( 'pro_byok' );
		const request = new Request( 'https://worker.example.com/v1/chat', {
			method: 'POST',
			headers: {
				Authorization: `Bearer ${ TEST_TOKEN }`,
				'Content-Type': 'application/json',
			},
			body: VALID_BODY,
		} );

		const response = await worker.fetch( request, env );

		expect( response.status ).toBe( 403 );
		const json = await response.json();
		expect( json ).toEqual( {
			error: 'BYOK tier must call Anthropic directly',
		} );
	} );
} );

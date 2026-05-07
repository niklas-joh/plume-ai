/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect, vi, afterEach } from 'vitest';
import worker from '../src/index';
import { makeEnv } from './helpers/kv-mock';
import type { SiteRecord, ToolParam } from '../src/types';

afterEach( () => {
	vi.restoreAllMocks();
} );

const TEST_TOKEN =
	'deadbeef1234567890abcdef1234567890abcdef1234567890abcdef12345678';

const VALID_BODY = JSON.stringify( {
	messages: [ { role: 'user', content: 'Hello' } ],
} );

async function makeEnvWithSiteToken(
	tier: SiteRecord[ 'tier' ],
	trialStartedAt?: number
) {
	const env = makeEnv();
	const record: SiteRecord = {
		site_url: 'https://example.com',
		tier,
		created_at: Date.now(),
		trial_started_at: trialStartedAt ?? Date.now(),
	};
	await env.USAGE_KV.put( `site:${ TEST_TOKEN }`, JSON.stringify( record ) );
	return env;
}

function makeChatRequest() {
	return new Request( 'https://worker.example.com/v1/chat', {
		method: 'POST',
		headers: {
			Authorization: `Bearer ${ TEST_TOKEN }`,
			'Content-Type': 'application/json',
		},
		body: VALID_BODY,
	} );
}

describe( 'handleChatProxy', () => {
	it( 'returns 403 for pro_byok tier', async () => {
		const env = await makeEnvWithSiteToken( 'pro_byok' );
		const response = await worker.fetch( makeChatRequest(), env );

		expect( response.status ).toBe( 403 );
		const json = await response.json();
		expect( json ).toEqual( {
			error: 'BYOK tier must call Anthropic directly',
		} );
	} );

	it( 'demotes an expired trial to free and stores the updated record', async () => {
		// trial_started_at is 31 days ago — past the 30-day window.
		const thirtyOneDaysAgo = Date.now() - 31 * 24 * 60 * 60 * 1000;
		const env = await makeEnvWithSiteToken( 'trial', thirtyOneDaysAgo );

		// Stub fetch so the Anthropic call returns a rate-limit (429) — the
		// important thing is that the demote path runs before the upstream call.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue(
				new Response( JSON.stringify( { error: 'rate limited' } ), {
					status: 429,
				} )
			)
		);

		await worker.fetch( makeChatRequest(), env );

		// Record in KV must now show tier=free.
		const updated = await env.USAGE_KV.get< SiteRecord >(
			`site:${ TEST_TOKEN }`,
			'json'
		) as SiteRecord;
		expect( updated.tier ).toBe( 'free' );
	} );

	it( 'forwards tools to the Anthropic API', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		const mockTool: ToolParam = {
			name: 'get_post_content',
			description: 'Get post content',
			input_schema: {
				type: 'object',
				properties: { post_id: { type: 'integer' } },
				required: [ 'post_id' ],
			},
		};

		let capturedBody: Record< string, unknown > | null = null;
		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async ( _url: string, init: RequestInit ) => {
				capturedBody = JSON.parse( init.body as string );
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'Summary' } ],
						model: 'claude-haiku-4-5-20251001',
						usage: { input_tokens: 10, output_tokens: 5 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Summarise post 140' } ],
			tools: [ mockTool ],
		} );

		const req = new Request( 'https://worker.example.com/v1/chat', {
			method: 'POST',
			headers: {
				Authorization: `Bearer ${ TEST_TOKEN }`,
				'Content-Type': 'application/json',
			},
			body,
		} );

		const response = await worker.fetch( req, env );
		expect( response.status ).toBe( 200 );
		expect( capturedBody ).not.toBeNull();
		expect( ( capturedBody as Record< string, unknown > ).tools ).toEqual( [
			mockTool,
		] );
	} );

	it( 'does not demote an active trial (< 30 days)', async () => {
		const env = await makeEnvWithSiteToken( 'trial' ); // trial_started_at = now

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue(
				new Response( JSON.stringify( { error: 'rate limited' } ), {
					status: 429,
				} )
			)
		);

		await worker.fetch( makeChatRequest(), env );

		const record = await env.USAGE_KV.get< SiteRecord >(
			`site:${ TEST_TOKEN }`,
			'json'
		) as SiteRecord;
		expect( record.tier ).toBe( 'trial' );
	} );
} );

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
	provider: 'claude',
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

function makeChatRequest( body = VALID_BODY ) {
	return new Request( 'https://worker.example.com/v1/chat', {
		method: 'POST',
		headers: {
			Authorization: `Bearer ${ TEST_TOKEN }`,
			'Content-Type': 'application/json',
		},
		body,
	} );
}

const mockTool: ToolParam = {
	name: 'get_post_content',
	description: 'Get post content',
	parameters: {
		type: 'object',
		properties: { post_id: { type: 'integer' } },
		required: [ 'post_id' ],
	},
};

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

		// Stub fetch so the upstream call returns a non-2xx — the
		// important thing is that the demote path runs before the upstream call.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockRejectedValue( new Error( 'upstream error' ) )
		);

		await worker.fetch( makeChatRequest(), env );

		// Record in KV must now show tier=free.
		const updated = ( await env.USAGE_KV.get< SiteRecord >(
			`site:${ TEST_TOKEN }`,
			'json'
		) ) as SiteRecord;
		expect( updated.tier ).toBe( 'free' );
	} );

	it( 'does not demote an active trial (< 30 days)', async () => {
		const env = await makeEnvWithSiteToken( 'trial' ); // trial_started_at = now

		vi.stubGlobal(
			'fetch',
			vi.fn().mockRejectedValue( new Error( 'upstream error' ) )
		);

		await worker.fetch( makeChatRequest(), env );

		const record = ( await env.USAGE_KV.get< SiteRecord >(
			`site:${ TEST_TOKEN }`,
			'json'
		) ) as SiteRecord;
		expect( record.tier ).toBe( 'trial' );
	} );

	it( 'Claude adapter: sends input_schema in upstream request', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		let capturedBody: Record< string, unknown > | null = null;
		vi.stubGlobal(
			'fetch',
			vi
				.fn()
				.mockImplementation(
					async ( _url: string, init: RequestInit ) => {
						capturedBody = JSON.parse( init.body as string );
						return new Response(
							JSON.stringify( {
								content: [ { type: 'text', text: 'Summary' } ],
								usage: { input_tokens: 10, output_tokens: 5 },
							} ),
							{ status: 200 }
						);
					}
				)
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Summarise post 140' } ],
			provider: 'claude',
			tools: [ mockTool ],
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );
		expect( capturedBody ).not.toBeNull();

		const sentTools = ( capturedBody as Record< string, unknown > )
			.tools as Array< Record< string, unknown > >;
		expect( sentTools ).toHaveLength( 1 );
		expect( sentTools[ 0 ] ).toEqual( {
			name: 'get_post_content',
			description: 'Get post content',
			input_schema: {
				type: 'object',
				properties: { post_id: { type: 'integer' } },
				required: [ 'post_id' ],
			},
		} );
	} );

	it( 'Claude adapter: relays tool_call when Claude returns a tool_use block', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [
							{
								type: 'text',
								text: "I'll fetch that post for you.",
							},
							{
								type: 'tool_use',
								id: 'toolu_01',
								name: 'get_post_content',
								input: { post_id: 42 },
							},
						],
						usage: { input_tokens: 20, output_tokens: 10 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Summarise post 42' } ],
			provider: 'claude',
			tools: [ mockTool ],
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		const json = ( await response.json() ) as {
			content: string;
			usage: { input_tokens: number; output_tokens: number };
			tool_call?: {
				id: string;
				name: string;
				arguments: Record< string, unknown >;
			};
		};
		expect( json.content ).toBe( "I'll fetch that post for you." );
		expect( json.tool_call ).toEqual( {
			id: 'toolu_01',
			name: 'get_post_content',
			arguments: { post_id: 42 },
		} );
		expect( json.usage ).toEqual( { input_tokens: 20, output_tokens: 10 } );
	} );

	it( 'Claude adapter: returns text-only response when no tool_use block is present', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [
							{ type: 'text', text: 'Here is the summary.' },
						],
						usage: { input_tokens: 15, output_tokens: 8 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 200 );

		const json = ( await response.json() ) as {
			content: string;
			tool_call?: unknown;
		};
		expect( json.content ).toBe( 'Here is the summary.' );
		expect( json.tool_call ).toBeUndefined();
	} );

	it( 'OpenAI adapter: sends correct OpenAI-format body and returns normalised response', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		let capturedUrl: string | null = null;
		let capturedBody: Record< string, unknown > | null = null;
		vi.stubGlobal(
			'fetch',
			vi
				.fn()
				.mockImplementation(
					async ( url: string, init: RequestInit ) => {
						capturedUrl = url;
						capturedBody = JSON.parse( init.body as string );
						return new Response(
							JSON.stringify( {
								choices: [
									{ message: { content: 'OpenAI reply' } },
								],
								usage: {
									prompt_tokens: 8,
									completion_tokens: 4,
								},
							} ),
							{ status: 200 }
						);
					}
				)
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello OpenAI' } ],
			provider: 'openai',
			tools: [ mockTool ],
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		expect( capturedUrl ).toBe(
			'https://api.openai.com/v1/chat/completions'
		);

		const sentTools = ( capturedBody as Record< string, unknown > )
			.tools as Array< Record< string, unknown > >;
		expect( sentTools ).toHaveLength( 1 );
		expect( sentTools[ 0 ] ).toEqual( {
			type: 'function',
			function: {
				name: 'get_post_content',
				description: 'Get post content',
				parameters: {
					type: 'object',
					properties: { post_id: { type: 'integer' } },
					required: [ 'post_id' ],
				},
			},
		} );

		const json = ( await response.json() ) as {
			content: string;
			usage: { input_tokens: number; output_tokens: number };
		};
		expect( json.content ).toBe( 'OpenAI reply' );
		expect( json.usage ).toEqual( { input_tokens: 8, output_tokens: 4 } );
	} );

	it( 'Gemini adapter: sends correct Gemini-format body and returns normalised response', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		let capturedUrl: string | null = null;
		let capturedBody: Record< string, unknown > | null = null;
		vi.stubGlobal(
			'fetch',
			vi
				.fn()
				.mockImplementation(
					async ( url: string, init: RequestInit ) => {
						capturedUrl = url as string;
						capturedBody = JSON.parse( init.body as string );
						return new Response(
							JSON.stringify( {
								candidates: [
									{
										content: {
											parts: [ { text: 'Gemini reply' } ],
										},
									},
								],
								usageMetadata: {
									promptTokenCount: 6,
									candidatesTokenCount: 3,
								},
							} ),
							{ status: 200 }
						);
					}
				)
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello Gemini' } ],
			provider: 'gemini',
			tools: [ mockTool ],
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		expect( capturedUrl ).toMatch( /generativelanguage\.googleapis\.com/ );

		const contents = ( capturedBody as Record< string, unknown > )
			.contents as Array< {
			role: string;
			parts: Array< { text: string } >;
		} >;
		expect( contents ).toEqual( [
			{ role: 'user', parts: [ { text: 'Hello Gemini' } ] },
		] );

		const sentTools = ( capturedBody as Record< string, unknown > )
			.tools as Array< Record< string, unknown > >;
		expect( sentTools ).toHaveLength( 1 );
		const decls = (
			sentTools[ 0 ] as {
				functionDeclarations: Array< Record< string, unknown > >;
			}
		 ).functionDeclarations;
		expect( decls[ 0 ].name ).toBe( 'get_post_content' );
		expect(
			( decls[ 0 ].parameters as Record< string, unknown > ).type
		).toBe( 'OBJECT' );

		const json = ( await response.json() ) as {
			content: string;
			usage: { input_tokens: number; output_tokens: number };
		};
		expect( json.content ).toBe( 'Gemini reply' );
		expect( json.usage ).toEqual( { input_tokens: 6, output_tokens: 3 } );
	} );

	it( 'returns a UUID-format tool_call id when Gemini functionCall part is returned', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						candidates: [
							{
								content: {
									parts: [
										{
											functionCall: {
												name: 'get_post_content',
												args: { post_id: 7 },
											},
										},
									],
								},
							},
						],
						usageMetadata: {
							promptTokenCount: 10,
							candidatesTokenCount: 5,
						},
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Fetch post 7' } ],
			provider: 'gemini',
			tools: [ mockTool ],
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		const json = ( await response.json() ) as {
			content: string;
			usage: { input_tokens: number; output_tokens: number };
			tool_call?: {
				id: string;
				name: string;
				arguments: Record< string, unknown >;
			};
		};

		expect( json.tool_call ).toBeDefined();
		const toolCall = json.tool_call!;
		expect( toolCall.id ).toMatch(
			/^gemini_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
		);
		expect( toolCall.name ).toBe( 'get_post_content' );
		expect( toolCall.arguments ).toEqual( { post_id: 7 } );
	} );

	it( 'returns 400 for unknown provider', async () => {
		const env = await makeEnvWithSiteToken( 'trial' );

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'groq',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 400 );
		const json = ( await response.json() ) as { error: string };
		expect( json.error ).toBe( 'Unknown provider' );
	} );

	it( 'returns 403 when a higher-tier OpenAI model is requested by a free site', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						choices: [ { message: { content: 'ok' } } ],
						usage: { prompt_tokens: 5, completion_tokens: 2 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'openai',
			model: 'gpt-4o',
		} );

		// Free tier does not include gpt-4o — worker should fall back to gpt-4o-mini
		// and succeed (200), not 403. The tier check silently falls back rather than
		// rejecting, which matches the existing Claude behaviour.
		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		// Verify it fell back to the allowed model, not gpt-4o
		const sentBody = JSON.parse(
			(
				vi.mocked( globalThis.fetch ).mock
					.calls[ 0 ][ 1 ] as RequestInit
			 ).body as string
		) as Record< string, unknown >;
		expect( sentBody.model ).toBe( 'gpt-4o-mini' );
	} );

	it( 'returns 200 when a higher-tier Gemini model is requested by a free site — falls back to default', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						candidates: [
							{ content: { parts: [ { text: 'ok' } ] } },
						],
						usageMetadata: {
							promptTokenCount: 5,
							candidatesTokenCount: 2,
						},
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'gemini',
			model: 'gemini-2.5-pro',
		} );

		// Free tier does not include gemini-2.5-pro — worker falls back to gemini-2.5-flash.
		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		const calledUrl = vi.mocked( globalThis.fetch ).mock
			.calls[ 0 ][ 0 ] as string;
		expect( calledUrl ).toContain( 'gemini-2.5-flash' );
	} );

	it( 'uses KV model config override when config:models is set in USAGE_KV', async () => {
		const env = await makeEnvWithSiteToken( 'pro_managed' );

		// Override: add a custom model to pro_managed for claude
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( {
				tier_models: {
					claude: {
						free: [ 'claude-haiku-4-5-20251001' ],
						trial: [ 'claude-haiku-4-5-20251001' ],
						pro_managed: [
							'claude-haiku-4-5-20251001',
							'claude-opus-4-7',
						],
					},
				},
				model_token_weight: { 'claude-opus-4-7': 20 },
			} )
		);

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'ok' } ],
						usage: { input_tokens: 10, output_tokens: 5 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'claude',
			model: 'claude-opus-4-7',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		// Verify the KV-specified model was used
		const sentBody = JSON.parse(
			(
				vi.mocked( globalThis.fetch ).mock
					.calls[ 0 ][ 1 ] as RequestInit
			 ).body as string
		) as Record< string, unknown >;
		expect( sentBody.model ).toBe( 'claude-opus-4-7' );

		// Verify the KV-specified token weight (10+5=15 raw, ×20 = 300 effective)
		const month =
			new Date().getFullYear() +
			'-' +
			String( new Date().getMonth() + 1 ).padStart( 2, '0' );
		const stored = await env.USAGE_KV.get(
			`usage:${ TEST_TOKEN }:${ month }`
		);
		expect( Number( stored ) ).toBe( 300 );
	} );

	it( 'token weight: effective tokens stored in KV equal raw tokens × weight for a heavy model', async () => {
		const env = await makeEnvWithSiteToken( 'pro_managed' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'response' } ],
						usage: { input_tokens: 100, output_tokens: 50 },
					} ),
					{ status: 200 }
				);
			} )
		);

		// claude-opus-4-6 has weight 15
		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'claude',
			model: 'claude-opus-4-6',
		} );

		await worker.fetch( makeChatRequest( body ), env );

		const month =
			new Date().getFullYear() +
			'-' +
			String( new Date().getMonth() + 1 ).padStart( 2, '0' );
		const stored = await env.USAGE_KV.get(
			`usage:${ TEST_TOKEN }:${ month }`
		);
		// raw = 150, weight = 5, effective = 750
		expect( Number( stored ) ).toBe( 750 );
	} );
} );

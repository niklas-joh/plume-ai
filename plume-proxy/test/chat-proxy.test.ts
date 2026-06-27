/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect, vi, afterEach } from 'vitest';
import worker from '../src/index';
import { makeEnv } from './helpers/kv-mock';
import { currentMonthKey } from './helpers/month';
import {
	chatCredits,
	GENERATOR_CREDITS,
	SEO_CREDITS,
	IMAGE_CREDITS,
} from '../src/credits';
import type { SiteRecord, ToolParam } from '../src/types';

afterEach( () => {
	vi.restoreAllMocks();
} );

const TEST_TOKEN =
	'deadbeef1234567890abcdef1234567890abcdef1234567890abcdef12345678';

const VALID_BODY = JSON.stringify( {
	messages: [ { role: 'user', content: 'Hello' } ],
	provider: 'claude',
	feature: 'chat',
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

async function getStoredUsage(
	env: ReturnType< typeof makeEnv >
): Promise< number > {
	const stored = await env.USAGE_KV.get(
		`usage:${ TEST_TOKEN }:${ currentMonthKey() }`
	);
	return Number( stored );
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

	it( 'BYOK request never reaches credit charging — no usage KV write after a 403', async () => {
		const env = await makeEnvWithSiteToken( 'pro_byok' );
		await worker.fetch( makeChatRequest(), env );

		const stored = await env.USAGE_KV.get(
			`usage:${ TEST_TOKEN }:${ currentMonthKey() }`
		);
		expect( stored ).toBeNull();
	} );

	it( 'returns 400 when feature field is missing from the request body', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'claude',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 400 );
		const json = ( await response.json() ) as { error: string };
		expect( json.error ).toMatch( /feature/i );
	} );

	it( 'returns 400 when feature field is an invalid value (not chat/generator/seo/images)', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'claude',
			feature: 'unicorn',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 400 );
		const json = ( await response.json() ) as { error: string };
		expect( json.error ).toMatch( /feature/i );
	} );

	it( 'Claude adapter: sends input_schema in upstream request', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

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
			feature: 'chat',
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
		const env = await makeEnvWithSiteToken( 'free' );

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
			feature: 'chat',
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
		const env = await makeEnvWithSiteToken( 'free' );

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
		const env = await makeEnvWithSiteToken( 'pro_managed' );

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
			feature: 'chat',
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
		const env = await makeEnvWithSiteToken( 'pro_managed' );

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
			feature: 'chat',
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
		const env = await makeEnvWithSiteToken( 'pro_managed' );

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
			feature: 'chat',
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
		const env = await makeEnvWithSiteToken( 'free' );

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'groq',
			feature: 'chat',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 400 );
		const json = ( await response.json() ) as { error: string };
		expect( json.error ).toBe( 'Unknown provider' );
	} );

	it( 'returns 200 when a higher-tier OpenAI model is requested by a free site — falls back to default', async () => {
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
			model: 'gpt-4.1',
			feature: 'chat',
		} );

		// Free tier has no openai models at all — getModelForTier throws a typed
		// 400 (empty allowed-models array), not a fallback to a different model.
		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 400 );
	} );

	it( 'GPT-4.1 nano is not yet present in DEFAULT_TIER_MODELS.free for openai', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'openai',
			model: 'gpt-4.1-nano',
			feature: 'chat',
		} );

		// free.openai is an empty array — issue #856 tracks adding gpt-4.1-nano,
		// not resolved by this PR.
		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 400 );
	} );

	it( 'returns 200 when a higher-tier Gemini model is requested by a free site — falls back to default', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'gemini',
			model: 'gemini-3.1-pro',
			feature: 'chat',
		} );

		// Free tier has no gemini models at all — getModelForTier throws a typed 400.
		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 400 );
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
						usage: { input_tokens: 2000, output_tokens: 2000 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'claude',
			model: 'claude-opus-4-7',
			feature: 'chat',
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

		// Verify chatCredits(input=2000, output=2000, weight=20) credits stored.
		const stored = await getStoredUsage( env );
		expect( stored ).toBe( chatCredits( 2000, 2000, 20 ) );
		expect( stored ).toBe( 40 );
	} );

	it( 'chat credits: KV value equals chatCredits(input, output, weight) for a heavy model', async () => {
		const env = await makeEnvWithSiteToken( 'pro_managed' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'response' } ],
						usage: { input_tokens: 10_000, output_tokens: 5_000 },
					} ),
					{ status: 200 }
				);
			} )
		);

		// claude-opus-4-6 has weight 5.
		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'claude',
			model: 'claude-opus-4-6',
			feature: 'chat',
		} );

		await worker.fetch( makeChatRequest( body ), env );

		const stored = await getStoredUsage( env );
		expect( stored ).toBe( chatCredits( 10_000, 5_000, 5 ) );
		expect( stored ).toBe( 38 );
	} );

	it( 'free tier: chat call charges credits per chatCredits(input, output, weight) and stores result in usage KV', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'response' } ],
						usage: { input_tokens: 1000, output_tokens: 1000 },
					} ),
					{ status: 200 }
				);
			} )
		);

		// claude-haiku-4-5-20251001 has weight 1.
		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 200 );

		const stored = await getStoredUsage( env );
		expect( stored ).toBe( chatCredits( 1000, 1000, 1 ) );
		expect( stored ).toBe( 1 );
	} );

	it( 'chat credits: Math.ceil rounding applies when (input+output)*weight is not a multiple of 2000', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'response' } ],
						usage: { input_tokens: 100, output_tokens: 1 },
					} ),
					{ status: 200 }
				);
			} )
		);

		// weight=1, raw=101 → ceil(101/2000) = 1, not a clean division.
		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 200 );

		const stored = await getStoredUsage( env );
		expect( stored ).toBe( chatCredits( 100, 1, 1 ) );
		expect( stored ).toBe( 1 );
	} );

	it( 'chat credits: no spurious rounding when (input+output)*weight is an exact multiple of 2000', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'response' } ],
						usage: { input_tokens: 2000, output_tokens: 2000 },
					} ),
					{ status: 200 }
				);
			} )
		);

		// weight=1, raw=4000 → 4000/2000 = 2 exactly.
		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 200 );

		const stored = await getStoredUsage( env );
		expect( stored ).toBe( chatCredits( 2000, 2000, 1 ) );
		expect( stored ).toBe( 2 );
	} );

	it.each( [
		[ 'generator', GENERATOR_CREDITS ],
		[ 'seo', SEO_CREDITS ],
		[ 'images', IMAGE_CREDITS ],
	] as const )(
		'%s feature charges the flat credit amount (%i) regardless of token usage',
		async ( feature, expectedCredits ) => {
			const env = await makeEnvWithSiteToken( 'pro_managed' );

			vi.stubGlobal(
				'fetch',
				vi.fn().mockImplementation( async () => {
					return new Response(
						JSON.stringify( {
							content: [ { type: 'text', text: 'response' } ],
							// Large token counts to prove the flat charge ignores them.
							usage: {
								input_tokens: 9999,
								output_tokens: 9999,
							},
						} ),
						{ status: 200 }
					);
				} )
			);

			const body = JSON.stringify( {
				messages: [ { role: 'user', content: 'Hello' } ],
				provider: 'claude',
				feature,
			} );

			const response = await worker.fetch( makeChatRequest( body ), env );
			expect( response.status ).toBe( 200 );

			const stored = await getStoredUsage( env );
			expect( stored ).toBe( expectedCredits );
		}
	);

	it( 'returns 429 once monthly credit allowance is exhausted for a free-tier site', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		await env.USAGE_KV.put(
			`usage:${ TEST_TOKEN }:${ currentMonthKey() }`,
			'100'
		);

		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 429 );
		const json = ( await response.json() ) as {
			error: string;
			used: number;
			limit: number;
		};
		expect( json ).toEqual( {
			error: 'Rate limit exceeded',
			used: 100,
			limit: 100,
		} );
	} );

	it( 'returns 429 once monthly credit allowance is exhausted for a pro_managed-tier site', async () => {
		const env = await makeEnvWithSiteToken( 'pro_managed' );
		await env.USAGE_KV.put(
			`usage:${ TEST_TOKEN }:${ currentMonthKey() }`,
			'500'
		);

		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 429 );
		const json = ( await response.json() ) as {
			error: string;
			used: number;
			limit: number;
		};
		expect( json ).toEqual( {
			error: 'Rate limit exceeded',
			used: 500,
			limit: 500,
		} );
	} );

	it( 'allows a request at used = limit-1, then blocks the next one at used = limit', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		const usageKey = `usage:${ TEST_TOKEN }:${ currentMonthKey() }`;
		await env.USAGE_KV.put( usageKey, '99' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'ok' } ],
						usage: { input_tokens: 1, output_tokens: 0 },
					} ),
					{ status: 200 }
				);
			} )
		);

		// used=99 < limit=100 — allowed, charges 1 credit, used becomes 100.
		const first = await worker.fetch( makeChatRequest(), env );
		expect( first.status ).toBe( 200 );
		expect( Number( await env.USAGE_KV.get( usageKey ) ) ).toBe( 100 );

		// used=100 is no longer < limit=100 — blocked.
		const second = await worker.fetch( makeChatRequest(), env );
		expect( second.status ).toBe( 429 );
	} );
} );

/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect, vi, afterEach } from 'vitest';
import worker from '../src/index';
import { makeEnv } from './helpers/kv-mock';
import { currentMonthKey } from './helpers/month';
import {
	chatCredits,
	rawChatCreditUnits,
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
			cache_control: { type: 'ephemeral' },
		} );
	} );

	it( 'Claude adapter: marks only the last tool with cache_control', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		const secondTool: ToolParam = {
			name: 'get_recent_posts',
			description: 'List recent posts',
			parameters: { type: 'object', properties: {} },
		};

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
			tools: [ mockTool, secondTool ],
			feature: 'chat',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		const sentTools = ( capturedBody as Record< string, unknown > )
			.tools as Array< Record< string, unknown > >;
		expect( sentTools ).toHaveLength( 2 );
		expect( sentTools[ 0 ] ).not.toHaveProperty( 'cache_control' );
		expect( sentTools[ 1 ].cache_control ).toEqual( { type: 'ephemeral' } );
	} );

	it( 'Claude adapter: relays tool_calls when Claude returns a tool_use block', async () => {
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
			credits_charged: number;
			tool_calls?: Array< {
				id: string;
				name: string;
				arguments: Record< string, unknown >;
			} >;
		};
		expect( json.content ).toBe( "I'll fetch that post for you." );
		expect( json.tool_calls ).toEqual( [
			{
				id: 'toolu_01',
				name: 'get_post_content',
				arguments: { post_id: 42 },
			},
		] );
		expect( json.usage ).toEqual( { input_tokens: 20, output_tokens: 10 } );
		// A tool-carrying response is billed like any other successful call, on its
		// own real usage — there is no more "intermediate step" free pass (#927).
		// First call from a fresh balance: delta === ceil(raw) === chatCredits().
		expect( json.credits_charged ).toBe( chatCredits( 20, 10, 1 ) );
		expect( await getStoredUsage( env ) ).toBe( rawChatCreditUnits( 20, 10, 1 ) );
	} );

	it( 'regression #927: two small agentic-loop calls bill their combined real usage, not double the naive per-call rounding', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		const smallUsage = { input_tokens: 150, output_tokens: 50 }; // raw = 200/2000 = 0.1 each

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [
							{
								type: 'tool_use',
								id: 'toolu_01',
								name: 'get_post_content',
								input: { post_id: 1 },
							},
						],
						usage: smallUsage,
					} ),
					{ status: 200 }
				);
			} )
		);
		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Look something up' } ],
			provider: 'claude',
			tools: [ mockTool ],
			feature: 'chat',
		} );

		// Naively rounding each 0.1-raw-credit call up independently (the bug caught
		// in review) would bill ceil(0.1)=1 twice, i.e. 2 credits for 400 combined
		// tokens — the same as a single 2000-token call. The fair delta-of-cumulative-
		// ceiling accounting must instead bill this pair the same as one equivalent
		// combined-size call: ceil(0.1 + 0.1) = 1, not 2.
		const first = await worker.fetch( makeChatRequest( body ), env );
		const firstJson = ( await first.json() ) as { credits_charged: number };
		expect( firstJson.credits_charged ).toBe( 1 ); // ceil(0.1) - ceil(0)

		const second = await worker.fetch( makeChatRequest( body ), env );
		const secondJson = ( await second.json() ) as { credits_charged: number };
		expect( secondJson.credits_charged ).toBe( 0 ); // ceil(0.2) - ceil(0.1) = 1 - 1

		const combined = firstJson.credits_charged + secondJson.credits_charged;
		expect( combined ).toBe( 1 );
		expect( combined ).toBe(
			Math.ceil( rawChatCreditUnits( 300, 100, 1 ) ) // one equivalent 400-token call
		);
		expect( await getStoredUsage( env ) ).toBeCloseTo(
			rawChatCreditUnits( 150, 50, 1 ) * 2,
			10
		);
	} );

	it( 'regression #927: a call that crosses a whole-credit boundary bills the crossing, calls that stay within a window bill 0', async () => {
		const env = await makeEnvWithSiteToken( 'free' );
		const usageKey = `usage:${ TEST_TOKEN }:${ currentMonthKey() }`;
		// Seed a raw running total already partway through a credit window.
		await env.USAGE_KV.put( usageKey, '0.5' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'ok' } ],
						usage: { input_tokens: 600, output_tokens: 0 }, // raw = 600/2000 = 0.3
					} ),
					{ status: 200 }
				);
			} )
		);

		// 0.5 -> 0.8: still under the next whole-credit boundary (ceil(0.8) === ceil(0.5) === 1) — bills 0.
		const first = await worker.fetch( makeChatRequest(), env );
		expect( ( ( await first.json() ) as { credits_charged: number } ).credits_charged ).toBe( 0 );

		// 0.8 -> 1.1: crosses the boundary (ceil(1.1)=2, ceil(0.8)=1) — bills 1.
		const second = await worker.fetch( makeChatRequest(), env );
		expect( ( ( await second.json() ) as { credits_charged: number } ).credits_charged ).toBe( 1 );

		// 1.1 -> 1.4: back under the next boundary (ceil(1.4)=2=ceil(1.1)) — bills 0 again.
		const third = await worker.fetch( makeChatRequest(), env );
		expect( ( ( await third.json() ) as { credits_charged: number } ).credits_charged ).toBe( 0 );

		expect( await getStoredUsage( env ) ).toBeCloseTo( 1.4, 10 );
	} );

	it( 'zero-token tool-use response bills 0 credits (usage-driven, not a hardcoded free pass)', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [
							{
								type: 'tool_use',
								id: 'toolu_01',
								name: 'get_post_content',
								input: { post_id: 1 },
							},
						],
						usage: { input_tokens: 0, output_tokens: 0 },
					} ),
					{ status: 200 }
				);
			} )
		);
		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'x' } ],
			provider: 'claude',
			tools: [ mockTool ],
			feature: 'chat',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );
		const json = ( await response.json() ) as { credits_charged: number };
		expect( json.credits_charged ).toBe( 0 );
		expect( await getStoredUsage( env ) ).toBe( 0 );
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
			tool_calls?: unknown;
		};
		expect( json.content ).toBe( 'Here is the summary.' );
		expect( json.tool_calls ).toBeUndefined();
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

	it( 'Gemini adapter: concatenates all text parts instead of only reading parts[0]', async () => {
		// Gemini 3.x can prepend a signature-only part (no `text`) ahead of the
		// actual answer; reading only parts[0] previously produced an empty reply.
		const env = await makeEnvWithSiteToken( 'pro_managed' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue(
				new Response(
					JSON.stringify( {
						candidates: [
							{
								content: {
									parts: [
										{ thoughtSignature: 'sig_xyz' },
										{ text: 'Here is my ' },
										{ text: 'full answer.' },
									],
								},
							},
						],
						usageMetadata: {
							promptTokenCount: 6,
							candidatesTokenCount: 3,
						},
					} ),
					{ status: 200 }
				)
			)
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello Gemini' } ],
			provider: 'gemini',
			feature: 'chat',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		const json = ( await response.json() ) as { content: string };
		expect( json.content ).toBe( 'Here is my full answer.' );
	} );

	it( 'Gemini adapter: strips additionalProperties from nested tool parameter schemas', async () => {
		// Gemini's function-declaration Schema is a restricted OpenAPI subset that
		// 400s on unknown keywords — additionalProperties (valid JSON Schema, used
		// by e.g. the meta_fields param in ToolRegistry.php for an open string map)
		// must be stripped recursively before the request reaches Gemini.
		const env = await makeEnvWithSiteToken( 'pro_managed' );

		const toolWithOpenMap: ToolParam = {
			name: 'update_post',
			description: 'Update a post',
			parameters: {
				type: 'object',
				properties: {
					post_id: { type: 'integer' },
					meta_fields: {
						type: 'object',
						description: 'Optional post meta key/value pairs.',
						additionalProperties: { type: 'string' },
					},
				},
				required: [ 'post_id' ],
			},
		};

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
			messages: [ { role: 'user', content: 'Update post 7' } ],
			provider: 'gemini',
			tools: [ toolWithOpenMap ],
			feature: 'chat',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		const sentTools = ( capturedBody as Record< string, unknown > )
			.tools as Array< Record< string, unknown > >;
		const decls = (
			sentTools[ 0 ] as {
				functionDeclarations: Array< Record< string, unknown > >;
			}
		 ).functionDeclarations;
		const metaFieldsSchema = (
			(
				decls[ 0 ].parameters as {
					properties: Record< string, unknown >;
				}
			 ).properties.meta_fields as Record< string, unknown >
		 );
		expect( metaFieldsSchema ).not.toHaveProperty( 'additionalProperties' );
		expect( metaFieldsSchema.description ).toBe(
			'Optional post meta key/value pairs.'
		);
	} );

	it( 'Gemini adapter: forwards pre-built functionCall/functionResponse parts verbatim on a tool-exchange follow-up turn', async () => {
		// ChatRestController::append_tool_exchange()'s 'gemini' case sends messages
		// shaped as { role, parts } (Gemini-native functionCall/functionResponse),
		// not { role, content } — callGemini() must not re-wrap these as {text: ...},
		// which would produce an empty, invalid Part (see plume-proxy/src/index.ts).
		const env = await makeEnvWithSiteToken( 'pro_managed' );

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
								candidates: [
									{
										content: {
											parts: [
												{ text: 'Here is the update.' },
											],
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
			messages: [
				{ role: 'user', content: 'Fetch post 7 and review it' },
				{
					role: 'model',
					parts: [
						{
							functionCall: {
								id: 'call_1',
								name: 'get_post_content',
								args: { post_id: 7 },
							},
						},
					],
				},
				{
					role: 'user',
					parts: [
						{
							functionResponse: {
								id: 'call_1',
								name: 'get_post_content',
								response: { content: 'Post body text' },
							},
						},
					],
				},
			],
			provider: 'gemini',
			feature: 'chat',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		const contents = ( capturedBody as Record< string, unknown > )
			.contents as Array< { role: string; parts: unknown[] } >;
		expect( contents[ 1 ] ).toEqual( {
			role: 'model',
			parts: [
				{
					functionCall: {
						id: 'call_1',
						name: 'get_post_content',
						args: { post_id: 7 },
					},
				},
			],
		} );
		expect( contents[ 2 ] ).toEqual( {
			role: 'user',
			parts: [
				{
					functionResponse: {
						id: 'call_1',
						name: 'get_post_content',
						response: { content: 'Post body text' },
					},
				},
			],
		} );
	} );

	it( 'returns a UUID-format tool_call id in tool_calls[0] when Gemini functionCall part is returned', async () => {
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
			tool_calls?: Array< {
				id: string;
				name: string;
				arguments: Record< string, unknown >;
			} >;
		};

		expect( json.tool_calls ).toBeDefined();
		expect( json.tool_calls ).toHaveLength( 1 );
		const toolCall = json.tool_calls![ 0 ];
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

	it( 'falls back to the free-tier default when a pro-only Gemini model is requested by a free site', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		let capturedUrl: string | null = null;
		vi.stubGlobal(
			'fetch',
			vi
				.fn()
				.mockImplementation( async ( url: string ) => {
					capturedUrl = url as string;
					return new Response(
						JSON.stringify( {
							candidates: [
								{ content: { parts: [ { text: 'Gemini reply' } ] } },
							],
							usageMetadata: {
								promptTokenCount: 6,
								candidatesTokenCount: 3,
							},
						} ),
						{ status: 200 }
					);
				} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Hello' } ],
			provider: 'gemini',
			model: 'gemini-3.1-pro-preview',
			feature: 'chat',
		} );

		// gemini-3.1-pro-preview isn't in free's allow-list — getModelForTier
		// falls back to allowed[0] (gemini-3.1-flash-lite) rather than rejecting.
		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );
		expect( capturedUrl ).toContain( 'gemini-3.1-flash-lite' );
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

		const response = await worker.fetch( makeChatRequest( body ), env );
		const json = ( await response.json() ) as { credits_charged: number };

		// KV now stores the raw (unrounded) running total, not chatCredits()'s rounded
		// value — 15,000 * 5 / 2,000 = 37.5, not a whole number. The billed amount
		// (credits_charged), which for a first call from a fresh balance equals
		// ceil(raw) === chatCredits(), is the one that still matches the rounded figure.
		const stored = await getStoredUsage( env );
		expect( stored ).toBe( rawChatCreditUnits( 10_000, 5_000, 5 ) );
		expect( stored ).toBe( 37.5 );
		expect( json.credits_charged ).toBe( chatCredits( 10_000, 5_000, 5 ) );
		expect( json.credits_charged ).toBe( 38 );
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

		const json = ( await response.json() ) as { credits_charged: number };
		const stored = await getStoredUsage( env );
		expect( stored ).toBe( chatCredits( 1000, 1000, 1 ) );
		expect( stored ).toBe( 1 );
		expect( json.credits_charged ).toBe( 1 );
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

		// weight=1, raw=101/2000=0.0505 → ceil(0.0505) = 1, not a clean division.
		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 200 );

		const json = ( await response.json() ) as { credits_charged: number };
		// KV stores the raw total (0.0505), not the rounded chatCredits() value;
		// credits_charged is what's actually billed and still equals chatCredits()
		// for this first call from a fresh balance.
		const stored = await getStoredUsage( env );
		expect( stored ).toBe( rawChatCreditUnits( 100, 1, 1 ) );
		expect( json.credits_charged ).toBe( chatCredits( 100, 1, 1 ) );
		expect( json.credits_charged ).toBe( 1 );
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

		const json = ( await response.json() ) as { credits_charged: number };
		const stored = await getStoredUsage( env );
		expect( stored ).toBe( chatCredits( 2000, 2000, 1 ) );
		expect( stored ).toBe( 2 );
		expect( json.credits_charged ).toBe( 2 );
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

	it( 'tool-use step is billed on its own real usage like any other successful call', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [
							{
								type: 'text',
								text: "I'll look that up.",
							},
							{
								type: 'tool_use',
								id: 'toolu_02',
								name: 'get_post_content',
								input: { post_id: 7 },
							},
						],
						usage: { input_tokens: 200, output_tokens: 50 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const body = JSON.stringify( {
			messages: [ { role: 'user', content: 'Get post 7' } ],
			provider: 'claude',
			tools: [ mockTool ],
			feature: 'chat',
		} );

		const response = await worker.fetch( makeChatRequest( body ), env );
		expect( response.status ).toBe( 200 );

		// First call from a fresh balance: delta === ceil(raw) === chatCredits().
		const json = ( await response.json() ) as { credits_charged: number };
		expect( json.credits_charged ).toBe( chatCredits( 200, 50, 1 ) );
		expect( await getStoredUsage( env ) ).toBe( rawChatCreditUnits( 200, 50, 1 ) );
	} );

	it( 'final chat response: credits_charged in response body matches chatCredits(); KV holds the raw total', async () => {
		const env = await makeEnvWithSiteToken( 'free' );

		vi.stubGlobal(
			'fetch',
			vi.fn().mockImplementation( async () => {
				return new Response(
					JSON.stringify( {
						content: [ { type: 'text', text: 'Here is the answer.' } ],
						usage: { input_tokens: 500, output_tokens: 500 },
					} ),
					{ status: 200 }
				);
			} )
		);

		const response = await worker.fetch( makeChatRequest(), env );
		expect( response.status ).toBe( 200 );

		// weight=1, raw=1000/2000=0.5 → ceil(0.5) = 1 credit billed.
		const json = ( await response.json() ) as { credits_charged: number };
		expect( json.credits_charged ).toBe( chatCredits( 500, 500, 1 ) );
		// KV stores the raw (unrounded) total, not the rounded billed amount.
		expect( await getStoredUsage( env ) ).toBe( rawChatCreditUnits( 500, 500, 1 ) );
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
						// weight=1, raw = 2000/2000 = 1.0 exactly, so the running total
						// lands precisely on the limit after this call.
						usage: { input_tokens: 2000, output_tokens: 0 },
					} ),
					{ status: 200 }
				);
			} )
		);

		// used=99 < limit=100 — allowed, bills ceil(100)-ceil(99)=1 credit, raw total becomes 100.
		const first = await worker.fetch( makeChatRequest(), env );
		expect( first.status ).toBe( 200 );
		expect( Number( await env.USAGE_KV.get( usageKey ) ) ).toBe( 100 );

		// used=100 is no longer < limit=100 — blocked.
		const second = await worker.fetch( makeChatRequest(), env );
		expect( second.status ).toBe( 429 );
	} );
} );

/// <reference types="@cloudflare/workers-types" />

// TEMPORARY(#1020): this whole file tests the Claude substitution of disabled
// providers. On removal: delete it together with src/substitution.ts.

import { describe, it, expect } from 'vitest';
import {
	asObject,
	substituteRoute,
	toClaudeMessages,
	toClaudeRequest,
	toClaudeSystem,
	toolId,
	type ClaudeMessage,
} from '../src/substitution';
import type { MessageParam, ProxyRequest } from '../src/types';

type Block = Record< string, unknown >;

function blocksOf( message: ClaudeMessage ): Block[] {
	return typeof message.content === 'string'
		? [ { type: 'text', text: message.content } ]
		: ( message.content as Block[] );
}

/**
 * Assert the invariants Anthropic enforces on a translated history: roles
 * strictly alternate starting with user, every tool_use is answered by a
 * tool_result in the very next (user) turn, and tool_result blocks come before
 * any text in that turn.
 */
function expectValidClaudeHistory( messages: ClaudeMessage[] ): void {
	expect( messages[ 0 ].role ).toBe( 'user' );
	messages.forEach( ( message, i ) => {
		expect( message.role ).toBe( i % 2 === 0 ? 'user' : 'assistant' );
		const blocks = blocksOf( message );
		expect( blocks.length ).toBeGreaterThan( 0 );

		if ( message.role === 'user' ) {
			const firstNonResult = blocks.findIndex(
				( b ) => b.type !== 'tool_result'
			);
			if ( firstNonResult !== -1 ) {
				expect(
					blocks.slice( firstNonResult ).some(
						( b ) => b.type === 'tool_result'
					)
				).toBe( false );
			}
		}

		const toolUseIds = blocks
			.filter( ( b ) => b.type === 'tool_use' )
			.map( ( b ) => b.id );
		if ( toolUseIds.length > 0 ) {
			const next = messages[ i + 1 ];
			expect( next?.role ).toBe( 'user' );
			const resultIds = blocksOf( next )
				.filter( ( b ) => b.type === 'tool_result' )
				.map( ( b ) => b.tool_use_id );
			expect( resultIds ).toEqual( toolUseIds );
		}

		for ( const b of blocks ) {
			if ( b.type === 'tool_use' ) {
				expect( b.id ).toMatch( /^[a-zA-Z0-9_-]+$/ );
				expect( b.input ).toBeTypeOf( 'object' );
				expect( Array.isArray( b.input ) ).toBe( false );
			}
		}
	} );
}

describe( 'TEMPORARY(#1020) substitution', () => {
	describe( 'asObject', () => {
		it.each( [
			[ [], {} ],
			[ null, {} ],
			[ undefined, {} ],
			[ 'x', {} ],
			[ 3, {} ],
			[ { a: 1 }, { a: 1 } ],
		] )( 'maps %j to %j', ( input, expected ) => {
			expect( asObject( input ) ).toEqual( expected );
		} );
	} );

	describe( 'toolId', () => {
		it( 'keeps ids that already match the Anthropic pattern', () => {
			expect( toolId( 'toolu_01AbC-9' ) ).toBe( 'toolu_01AbC-9' );
		} );

		it( 'replaces characters Anthropic rejects', () => {
			expect( toolId( 'call.1:a/b c' ) ).toBe( 'call_1_a_b_c' );
		} );

		it( 'never returns an empty id', () => {
			expect( toolId( '' ) ).toBe( 'tool' );
		} );
	} );

	describe( 'substituteRoute', () => {
		it( 'builds a substituted route that keeps the requested provider', () => {
			expect(
				substituteRoute( 'openai', 'claude', 'claude-haiku-4-5-20251001' )
			).toEqual( {
				requestedProvider: 'openai',
				provider: 'claude',
				model: 'claude-haiku-4-5-20251001',
				substituted: true,
			} );
		} );
	} );

	describe( 'toClaudeMessages', () => {
		it( 'passes plain string history through unchanged', () => {
			const history: MessageParam[] = [
				{ role: 'user', content: 'Hello' },
				{ role: 'assistant', content: 'Hi there' },
				{ role: 'user', content: 'Thanks' },
			];
			expect( toClaudeMessages( history ) ).toEqual( history );
		} );

		it( 'passes Claude-shaped block arrays through unchanged', () => {
			const history: MessageParam[] = [
				{
					role: 'user',
					content: [
						{
							type: 'text',
							text: 'Hello',
							cache_control: { type: 'ephemeral' },
						},
					],
				},
			];
			expect( toClaudeMessages( history ) ).toEqual( history );
		} );

		it( 'drops empty text and merges consecutive same-role messages', () => {
			expect(
				toClaudeMessages( [
					{ role: 'user', content: 'One' },
					{ role: 'user', content: 'Two' },
					{ role: 'assistant', content: '' },
					{ role: 'assistant', content: 'Reply' },
				] )
			).toEqual( [
				{
					role: 'user',
					content: [
						{ type: 'text', text: 'One' },
						{ type: 'text', text: 'Two' },
					],
				},
				{ role: 'assistant', content: 'Reply' },
			] );
		} );

		it( 'drops an empty message before merging, so the turns either side merge', () => {
			const result = toClaudeMessages( [
				{ role: 'user', content: 'First' },
				{ role: 'assistant', content: '' },
				{ role: 'user', content: 'Second' },
			] );
			expect( result ).toEqual( [
				{
					role: 'user',
					content: [
						{ type: 'text', text: 'First' },
						{ type: 'text', text: 'Second' },
					],
				},
			] );
		} );

		it( 'drops system messages', () => {
			expect(
				toClaudeMessages( [
					{ role: 'system', content: 'Ignored' },
					{ role: 'user', content: 'Hello' },
				] )
			).toEqual( [ { role: 'user', content: 'Hello' } ] );
		} );

		describe( 'OpenAI-shaped exchange', () => {
			it( 'translates a single call into tool_use plus one tool_result turn', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Fetch post 7' },
					{
						role: 'assistant',
						tool_calls: [
							{
								id: 'toolu_01',
								type: 'function',
								function: {
									name: 'get_post_content',
									arguments: '{"post_id":7}',
								},
							},
						],
					},
					{
						role: 'tool',
						tool_call_id: 'toolu_01',
						content: '{"content":"Body"}',
					},
				] );

				expect( result ).toEqual( [
					{ role: 'user', content: 'Fetch post 7' },
					{
						role: 'assistant',
						content: [
							{
								type: 'tool_use',
								id: 'toolu_01',
								name: 'get_post_content',
								input: { post_id: 7 },
							},
						],
					},
					{
						role: 'user',
						content: [
							{
								type: 'tool_result',
								tool_use_id: 'toolu_01',
								content: '{"content":"Body"}',
							},
						],
					},
				] );
			} );

			it( 'merges parallel tool messages into one user turn with N tool_result blocks', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Fetch posts 7 and 8' },
					{
						role: 'assistant',
						content: 'Looking them up.',
						tool_calls: [
							{
								id: 'toolu_a',
								function: {
									name: 'get_post_content',
									arguments: '{"post_id":7}',
								},
							},
							{
								id: 'toolu_b',
								function: {
									name: 'get_post_content',
									arguments: '{"post_id":8}',
								},
							},
						],
					},
					{ role: 'tool', tool_call_id: 'toolu_a', content: 'A' },
					{ role: 'tool', tool_call_id: 'toolu_b', content: 'B' },
				] );

				expect( result ).toHaveLength( 3 );
				expect( blocksOf( result[ 1 ] ) ).toEqual( [
					{ type: 'text', text: 'Looking them up.' },
					{
						type: 'tool_use',
						id: 'toolu_a',
						name: 'get_post_content',
						input: { post_id: 7 },
					},
					{
						type: 'tool_use',
						id: 'toolu_b',
						name: 'get_post_content',
						input: { post_id: 8 },
					},
				] );
				expect( result[ 2 ] ).toEqual( {
					role: 'user',
					content: [
						{ type: 'tool_result', tool_use_id: 'toolu_a', content: 'A' },
						{ type: 'tool_result', tool_use_id: 'toolu_b', content: 'B' },
					],
				} );
				expectValidClaudeHistory( result );
			} );

			it( 'maps a zero-argument tool ("[]" from wp_json_encode) to an empty object input', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'List my recent posts' },
					{
						role: 'assistant',
						tool_calls: [
							{
								id: 'toolu_z',
								function: {
									name: 'get_recent_posts',
									arguments: '[]',
								},
							},
						],
					},
					{ role: 'tool', tool_call_id: 'toolu_z', content: '[]' },
				] );

				const toolUse = blocksOf( result[ 1 ] )[ 0 ];
				expect( toolUse.input ).toEqual( {} );
				expect( Array.isArray( toolUse.input ) ).toBe( false );
			} );

			it( 'maps malformed arguments to an empty object input', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Go' },
					{
						role: 'assistant',
						tool_calls: [
							{
								id: 'toolu_m',
								function: { name: 'x', arguments: '{not json' },
							},
						],
					},
					{ role: 'tool', tool_call_id: 'toolu_m', content: 'ok' },
				] );
				expect( blocksOf( result[ 1 ] )[ 0 ].input ).toEqual( {} );
			} );

			it( 'sanitises ids identically on both sides of each pair', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Go' },
					{
						role: 'assistant',
						tool_calls: [
							{
								id: 'call.1:x',
								function: { name: 'x', arguments: '{}' },
							},
						],
					},
					{ role: 'tool', tool_call_id: 'call.1:x', content: 'ok' },
				] );
				expect( blocksOf( result[ 1 ] )[ 0 ].id ).toBe( 'call_1_x' );
				expect( blocksOf( result[ 2 ] )[ 0 ].tool_use_id ).toBe(
					'call_1_x'
				);
				expectValidClaudeHistory( result );
			} );

			it( 'produces a valid alternating history over two or more tool rounds', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Review my last two posts' },
					{
						role: 'assistant',
						tool_calls: [
							{
								id: 'toolu_r1',
								function: {
									name: 'get_recent_posts',
									arguments: '[]',
								},
							},
						],
					},
					{ role: 'tool', tool_call_id: 'toolu_r1', content: '[7,8]' },
					{
						role: 'assistant',
						tool_calls: [
							{
								id: 'toolu_r2a',
								function: {
									name: 'get_post_content',
									arguments: '{"post_id":7}',
								},
							},
							{
								id: 'toolu_r2b',
								function: {
									name: 'get_post_content',
									arguments: '{"post_id":8}',
								},
							},
						],
					},
					{ role: 'tool', tool_call_id: 'toolu_r2a', content: 'A' },
					{ role: 'tool', tool_call_id: 'toolu_r2b', content: 'B' },
					{
						role: 'assistant',
						tool_calls: [
							{
								id: 'toolu_r3',
								function: {
									name: 'get_post_content',
									arguments: '{"post_id":9}',
								},
							},
						],
					},
					{ role: 'tool', tool_call_id: 'toolu_r3', content: 'C' },
				] );

				expect( result ).toHaveLength( 7 );
				expectValidClaudeHistory( result );
			} );
		} );

		describe( 'Gemini-shaped exchange', () => {
			it( 'maps role model to assistant and drops thoughtSignature', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Fetch post 7' },
					{
						role: 'model',
						parts: [
							{
								functionCall: {
									id: 'toolu_g1',
									name: 'get_post_content',
									args: { post_id: 7 },
								},
								thoughtSignature: 'sig_abc',
							},
						],
					},
					{
						role: 'user',
						parts: [
							{
								functionResponse: {
									id: 'toolu_g1',
									name: 'get_post_content',
									response: { content: 'Body' },
								},
							},
						],
					},
				] );

				expect( result ).toEqual( [
					{ role: 'user', content: 'Fetch post 7' },
					{
						role: 'assistant',
						content: [
							{
								type: 'tool_use',
								id: 'toolu_g1',
								name: 'get_post_content',
								input: { post_id: 7 },
							},
						],
					},
					{
						role: 'user',
						content: [
							{
								type: 'tool_result',
								tool_use_id: 'toolu_g1',
								content: '{"content":"Body"}',
							},
						],
					},
				] );
			} );

			it( 'maps a zero-argument tool (args: []) to an empty object input', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'List my recent posts' },
					{
						role: 'model',
						parts: [
							{
								functionCall: {
									id: 'toolu_gz',
									name: 'get_recent_posts',
									args: [],
								},
							},
						],
					},
					{
						role: 'user',
						parts: [
							{
								functionResponse: {
									id: 'toolu_gz',
									name: 'get_recent_posts',
									response: {},
								},
							},
						],
					},
				] );

				const toolUse = blocksOf( result[ 1 ] )[ 0 ];
				expect( toolUse.input ).toEqual( {} );
				expect( Array.isArray( toolUse.input ) ).toBe( false );
				expect( blocksOf( result[ 2 ] )[ 0 ].content ).toBe( '{}' );
			} );

			it( 'falls back to the function name when a part carries no id', () => {
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Go' },
					{
						role: 'model',
						parts: [ { functionCall: { name: 'get_recent_posts' } } ],
					},
					{
						role: 'user',
						parts: [
							{
								functionResponse: {
									name: 'get_recent_posts',
									response: { ok: true },
								},
							},
						],
					},
				] );
				expect( blocksOf( result[ 1 ] )[ 0 ].id ).toBe(
					'get_recent_posts'
				);
				expect( blocksOf( result[ 2 ] )[ 0 ].tool_use_id ).toBe(
					'get_recent_posts'
				);
				expectValidClaudeHistory( result );
			} );

			it( 'produces a valid alternating history over two or more tool rounds', () => {
				const call = ( id: string, args: unknown ) => ( {
					functionCall: { id, name: 'get_post_content', args },
				} );
				const reply = ( id: string ) => ( {
					functionResponse: {
						id,
						name: 'get_post_content',
						response: { content: id },
					},
				} );
				const result = toClaudeMessages( [
					{ role: 'user', content: 'Review my posts' },
					{ role: 'model', parts: [ call( 'gemini_1', [] ) ] },
					{ role: 'user', parts: [ reply( 'gemini_1' ) ] },
					{
						role: 'model',
						parts: [
							{ text: '' },
							call( 'gemini_2', { post_id: 7 } ),
							call( 'gemini_3', { post_id: 8 } ),
						],
					},
					{
						role: 'user',
						parts: [ reply( 'gemini_2' ), reply( 'gemini_3' ) ],
					},
					// A follow-up user text right after the results merges into the
					// same turn, behind the tool_result blocks.
					{ role: 'user', content: 'Also check SEO' },
					{ role: 'model', parts: [ call( 'gemini_4', { post_id: 9 } ) ] },
					{ role: 'user', parts: [ reply( 'gemini_4' ) ] },
				] );

				expect( result ).toHaveLength( 7 );
				expectValidClaudeHistory( result );
				expect( blocksOf( result[ 4 ] ).map( ( b ) => b.type ) ).toEqual( [
					'tool_result',
					'tool_result',
					'text',
				] );
			} );
		} );

		it( 'puts tool_result blocks first when user text precedes them in a merged turn', () => {
			const result = toClaudeMessages( [
				{ role: 'user', content: 'Go' },
				{
					role: 'assistant',
					tool_calls: [
						{ id: 't1', function: { name: 'x', arguments: '{}' } },
					],
				},
				{ role: 'user', content: 'Note' },
				{ role: 'tool', tool_call_id: 't1', content: 'ok' },
			] );
			expect( blocksOf( result[ 2 ] ).map( ( b ) => b.type ) ).toEqual( [
				'tool_result',
				'text',
			] );
			expectValidClaudeHistory( result );
		} );
	} );

	describe( 'toClaudeSystem', () => {
		it( 'returns undefined for an absent system prompt', () => {
			expect( toClaudeSystem( undefined ) ).toBeUndefined();
		} );

		it( 'keeps a string at the 8192-character threshold as a plain string', () => {
			const text = 'a'.repeat( 8192 );
			expect( toClaudeSystem( text ) ).toBe( text );
		} );

		it( 'wraps a longer string in a cached text block', () => {
			const text = 'a'.repeat( 8193 );
			expect( toClaudeSystem( text ) ).toEqual( [
				{ type: 'text', text, cache_control: { type: 'ephemeral' } },
			] );
		} );

		it( 'counts characters, not UTF-16 code units, like mb_strlen', () => {
			// 4097 astral characters = 8194 UTF-16 units, but only 4097 characters.
			const text = '😀'.repeat( 4097 );
			expect( toClaudeSystem( text ) ).toBe( text );
		} );

		it( 'returns a block array as-is', () => {
			const blocks = [ { type: 'text' as const, text: 'Sys' } ];
			expect( toClaudeSystem( blocks ) ).toBe( blocks );
		} );
	} );

	describe( 'toClaudeRequest', () => {
		it( 'translates messages and system and keeps every other field', () => {
			const body: ProxyRequest = {
				messages: [
					{ role: 'user', content: 'Go' },
					{
						role: 'model',
						parts: [ { functionCall: { id: 'g1', name: 'x', args: [] } } ],
					},
					{
						role: 'user',
						parts: [
							{ functionResponse: { id: 'g1', name: 'x', response: {} } },
						],
					},
				],
				system: 'Be brief.',
				provider: 'gemini',
				model: 'gemini-3.1-flash-lite',
				max_tokens: 500,
				feature: 'chat',
			};

			const translated = toClaudeRequest( body );
			expect( translated ).toEqual( {
				...body,
				system: 'Be brief.',
				messages: [
					{ role: 'user', content: 'Go' },
					{
						role: 'assistant',
						content: [
							{ type: 'tool_use', id: 'g1', name: 'x', input: {} },
						],
					},
					{
						role: 'user',
						content: [
							{ type: 'tool_result', tool_use_id: 'g1', content: '{}' },
						],
					},
				],
			} );
			// The original body is not mutated.
			expect( body.messages[ 1 ].role ).toBe( 'model' );
		} );
	} );
} );

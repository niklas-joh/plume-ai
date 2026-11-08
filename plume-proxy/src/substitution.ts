// src/substitution.ts
//
// TEMPORARY(#1020): everything in this file exists only so that a chat for a
// disabled managed provider (OpenAI, Gemini) can be served by Claude.
// On removal: delete this file and test/substitution.test.ts, then run
// `grep -rn "TEMPORARY(#1020)" plume-proxy` and follow each note
// (see README.md § "Reverting Claude-only").
//
// The module deliberately imports only types, so deleting it touches nothing
// but its tagged call sites in index.ts.

import type {
	ChatRoute,
	MessageParam,
	Provider,
	ProxyRequest,
	SystemBlock,
} from './types';

/**
 * One Anthropic Messages API content block (text, tool_use or tool_result).
 *
 * @since NEXT_VERSION
 */
export type ClaudeBlock = Record< string, unknown > & { type: string };

/**
 * One Anthropic Messages API message.
 *
 * @since NEXT_VERSION
 */
export interface ClaudeMessage {
	role: 'user' | 'assistant';
	content: string | ClaudeBlock[];
}

/**
 * Mirrors ClaudeProvider::CACHE_MIN_CHARS on the plugin's own Claude path.
 */
const CACHE_MIN_CHARS = 8_192;

/**
 * Build the route for a request whose provider is not served: Claude answers
 * it with the given model, and the requested provider is kept for logging.
 *
 * @since NEXT_VERSION
 * @param {Provider} requestedProvider Provider the client asked for.
 * @param {Provider} fallbackProvider  Provider that serves the request instead.
 * @param {string}   fallbackModel     Model of the fallback provider for the caller's tier.
 * @return {ChatRoute} Substituted route.
 */
export function substituteRoute(
	requestedProvider: Provider,
	fallbackProvider: Provider,
	fallbackModel: string
): ChatRoute {
	return {
		requestedProvider,
		provider: fallbackProvider,
		model: fallbackModel,
		substituted: true,
	};
}

/**
 * Return a plain object, or `{}` for anything else.
 *
 * PHP's json encoding turns an empty tool input into a JSON array: Gemini
 * history carries `args: []` and OpenAI history `arguments: "[]"`. Anthropic
 * rejects `tool_use.input: []`, so every translated input goes through this.
 *
 * @since NEXT_VERSION
 * @param {unknown} value Decoded tool input.
 * @return {Record<string, unknown>} The value if it is a plain object, else `{}`.
 */
export function asObject( value: unknown ): Record< string, unknown > {
	return value && typeof value === 'object' && ! Array.isArray( value )
		? ( value as Record< string, unknown > )
		: {};
}

/**
 * Make a tool-call id valid for Anthropic (`^[a-zA-Z0-9_-]+$`).
 *
 * Applied identically to `tool_use.id` and `tool_result.tool_use_id`, so each
 * pair still matches after sanitising.
 *
 * @since NEXT_VERSION
 * @param {unknown} id Original id (or the derived id of an id-less Gemini call).
 * @return {string} Sanitised, non-empty id.
 */
export function toolId( id: unknown ): string {
	const safe = String( id ?? '' ).replace( /[^a-zA-Z0-9_-]/g, '_' );
	return safe || 'tool';
}

function parseArguments( raw: unknown ): Record< string, unknown > {
	if ( typeof raw !== 'string' ) {
		return asObject( raw );
	}
	try {
		return asObject( JSON.parse( raw ) );
	} catch {
		return {};
	}
}

function textBlocks( content: MessageParam[ 'content' ] ): ClaudeBlock[] {
	if ( typeof content === 'string' ) {
		return content ? [ { type: 'text', text: content } ] : [];
	}
	if ( ! Array.isArray( content ) ) {
		return [];
	}
	return content.filter(
		( block ): block is ClaudeBlock =>
			!! block &&
			typeof block.type === 'string' &&
			! ( block.type === 'text' && ! block.text )
	);
}

/**
 * Running count of id-less Gemini calls and responses per function name, so
 * the n-th call to a function pairs with the n-th response to it.
 */
interface GeminiIdCounters {
	calls: Map< string, number >;
	responses: Map< string, number >;
}

// PHP sends `id ?? name` for a response, so "no id" is detected as id === name
// on both sides. Using the bare name would give parallel calls to the same
// function duplicate tool_use ids, which Anthropic rejects.
function geminiId(
	id: string | undefined,
	name: string,
	counts: Map< string, number >
): string {
	const raw = id ?? name;
	if ( raw !== name ) {
		return toolId( raw );
	}
	const n = counts.get( name ) ?? 0;
	counts.set( name, n + 1 );
	return toolId( `${ name }_${ n }` );
}

function geminiPartToBlock(
	part: Record< string, unknown >,
	counters: GeminiIdCounters
): ClaudeBlock | null {
	if ( typeof part.text === 'string' ) {
		return part.text ? { type: 'text', text: part.text } : null;
	}
	const call = part.functionCall as
		| { id?: string; name?: string; args?: unknown }
		| undefined;
	if ( call ) {
		const name = call.name ?? '';
		return {
			type: 'tool_use',
			id: geminiId( call.id, name, counters.calls ),
			name,
			input: asObject( call.args ),
		};
	}
	const reply = part.functionResponse as
		| { id?: string; name?: string; response?: unknown }
		| undefined;
	if ( reply ) {
		return {
			type: 'tool_result',
			tool_use_id: geminiId( reply.id, reply.name ?? '', counters.responses ),
			content: JSON.stringify( reply.response ?? {} ),
		};
	}
	// thoughtSignature-only parts and any other Gemini-specific keys.
	return null;
}

function convertMessage(
	message: MessageParam,
	counters: GeminiIdCounters
): { role: ClaudeMessage[ 'role' ]; blocks: ClaudeBlock[] } | null {
	if ( message.role === 'system' ) {
		return null;
	}

	if ( message.role === 'tool' ) {
		return {
			role: 'user',
			blocks: [
				{
					type: 'tool_result',
					tool_use_id: toolId( message.tool_call_id ),
					content:
						typeof message.content === 'string'
							? message.content
							: JSON.stringify( message.content ?? '' ),
				},
			],
		};
	}

	const role = message.role === 'user' ? 'user' : 'assistant';

	if ( Array.isArray( message.parts ) ) {
		return {
			role,
			blocks: message.parts
				.map( ( part ) => geminiPartToBlock( part, counters ) )
				.filter( ( b ): b is ClaudeBlock => b !== null ),
		};
	}

	const blocks = textBlocks( message.content );
	for ( const call of message.tool_calls ?? [] ) {
		blocks.push( {
			type: 'tool_use',
			id: toolId( call.id ),
			name: call.function?.name ?? '',
			input: parseArguments( call.function?.arguments ),
		} );
	}
	return { role, blocks };
}

function isPlainTextBlock( block: ClaudeBlock ): boolean {
	return (
		block.type === 'text' &&
		typeof block.text === 'string' &&
		Object.keys( block ).length === 2
	);
}

/**
 * Translate a provider-shaped chat history into Anthropic Messages format.
 *
 * Plugin versions up to 2.0.0 build tool-exchange history in the shape of the
 * provider they asked for (ChatRestController::append_tool_exchange()), so a
 * substituted OpenAI or Gemini chat would otherwise reach Anthropic as an
 * invalid request on its second iteration.
 *
 * Order of operations (each step relies on the previous one):
 * 1. Convert each message to `{ role, blocks }`: `model` → assistant, `tool` →
 *    user with a tool_result, Gemini `parts` and OpenAI `tool_calls` → blocks,
 *    system messages and empty text dropped. An id-less Gemini call gets
 *    `<name>_<n>`, counted per function across the history, and its response
 *    the same id.
 * 2. Drop messages with no blocks, so they cannot stop step 3 merging the
 *    turns either side of them.
 * 3. Merge consecutive same-role messages (several `tool` messages become one
 *    user turn).
 * 4. Put tool_result blocks first in every user turn, as Anthropic requires
 *    for the turn that answers a tool_use turn.
 * 5. Emit a turn holding one plain text block as a string, so plain history
 *    passes through unchanged.
 *
 * @since NEXT_VERSION
 * @param {MessageParam[]} messages History as received from the plugin.
 * @return {ClaudeMessage[]} Anthropic-compatible history.
 */
export function toClaudeMessages( messages: MessageParam[] ): ClaudeMessage[] {
	const merged: Array< { role: ClaudeMessage[ 'role' ]; blocks: ClaudeBlock[] } > =
		[];
	const counters: GeminiIdCounters = { calls: new Map(), responses: new Map() };

	for ( const message of messages ) {
		const converted = convertMessage( message, counters );
		if ( ! converted || converted.blocks.length === 0 ) {
			continue;
		}
		const last = merged[ merged.length - 1 ];
		if ( last && last.role === converted.role ) {
			last.blocks.push( ...converted.blocks );
		} else {
			merged.push( converted );
		}
	}

	return merged.map( ( { role, blocks } ) => {
		const ordered =
			role === 'user'
				? [
						...blocks.filter( ( b ) => b.type === 'tool_result' ),
						...blocks.filter( ( b ) => b.type !== 'tool_result' ),
				  ]
				: blocks;
		if ( ordered.length === 1 && isPlainTextBlock( ordered[ 0 ] ) ) {
			return { role, content: ordered[ 0 ].text as string };
		}
		return { role, content: ordered };
	} );
}

/**
 * Shape a system prompt the way the plugin's own Claude path does
 * (ClaudeProvider::build_system_field()), so substituted chats get the same
 * prompt caching: a string over 8,192 characters becomes one cached text block.
 *
 * @since NEXT_VERSION
 * @param {ProxyRequest['system']} system System prompt from the request.
 * @return {ProxyRequest['system']} System prompt for Anthropic.
 */
export function toClaudeSystem(
	system: ProxyRequest[ 'system' ]
): ProxyRequest[ 'system' ] {
	if ( typeof system !== 'string' ) {
		return system;
	}
	// Array.from counts characters like PHP's mb_strlen, not UTF-16 code units.
	if ( Array.from( system ).length <= CACHE_MIN_CHARS ) {
		return system;
	}
	const block: SystemBlock = {
		type: 'text',
		text: system,
		cache_control: { type: 'ephemeral' },
	};
	return [ block ];
}

/**
 * Copy a request with its messages and system prompt translated for Claude.
 *
 * @since NEXT_VERSION
 * @param {ProxyRequest} body Request as received from the plugin.
 * @return {ProxyRequest} New request object; the original is not modified.
 */
export function toClaudeRequest( body: ProxyRequest ): ProxyRequest {
	return {
		...body,
		messages: toClaudeMessages( body.messages ) as MessageParam[],
		system: toClaudeSystem( body.system ),
	};
}

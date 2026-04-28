// src/index.ts

import { Env, ProxyRequest, ProxyTier } from './types';
import { authenticateRequest } from './auth';
import { handleRegistration } from './registration';
import { handleWebhook } from './webhook';

const MAX_BODY_BYTES = 1_048_576; // 1 MB

export default {
	async fetch( request: Request, env: Env ): Promise< Response > {
		if ( request.method !== 'POST' ) {
			return jsonResponse( { error: 'Method not allowed' }, 405 );
		}

		const { pathname } = new URL( request.url );

		if ( pathname === '/register' ) {
			return handleRegistration( request, env );
		}
		if ( pathname === '/webhook' ) {
			return handleWebhook( request, env );
		}
		if ( pathname === '/v1/chat' ) {
			return handleChatProxy( request, env );
		}

		return jsonResponse( { error: 'Not found' }, 404 );
	},
};

async function handleChatProxy(
	request: Request,
	env: Env
): Promise< Response > {
	try {
		const auth = await authenticateRequest( request, env );
		if ( ! auth.authenticated || ! auth.site_token || ! auth.tier ) {
			return jsonResponse( { error: 'Unauthorised' }, 401 );
		}

		const contentLength = Number(
			request.headers.get( 'Content-Length' ) ?? 0
		);
		if ( contentLength > MAX_BODY_BYTES ) {
			return jsonResponse( { error: 'Request too large' }, 413 );
		}

		const bodyText = await request.text();
		if ( new TextEncoder().encode( bodyText ).length > MAX_BODY_BYTES ) {
			return jsonResponse( { error: 'Request too large' }, 413 );
		}

		const body = JSON.parse( bodyText ) as ProxyRequest;
		const { messages, model, max_tokens: maxTokens, system } = body;
		const { site_token: siteToken, tier } = auth;

		// BYOK sites call Anthropic directly via ClaudeProvider and must never reach here.
		if ( tier === 'pro_byok' ) {
			return jsonResponse(
				{ error: 'BYOK tier must call Anthropic directly' },
				403
			);
		}

		const rateLimitCheck = await checkRateLimit( siteToken, tier, env );
		if ( ! rateLimitCheck.allowed ) {
			return jsonResponse(
				{
					error: 'Rate limit exceeded',
					used: rateLimitCheck.used,
					limit: rateLimitCheck.limit,
				},
				429
			);
		}

		const selectedModel = getModelForTier( tier, model );
		const clampedMaxTokens = Math.min(
			maxTokens ?? ( tier === 'free' ? 1000 : 4000 ),
			MAX_TOKENS[ tier ]
		);

		const anthropicBody: Record< string, unknown > = {
			model: selectedModel,
			max_tokens: clampedMaxTokens,
			messages,
		};
		if ( system ) {
			anthropicBody.system = system;
		}

		const anthropicResponse = await fetch(
			'https://api.anthropic.com/v1/messages',
			{
				method: 'POST',
				headers: {
					'x-api-key': env.ANTHROPIC_API_KEY,
					'anthropic-version': '2023-06-01',
					'content-type': 'application/json',
				},
				body: JSON.stringify( anthropicBody ),
			}
		);

		const result = ( await anthropicResponse.json() ) as Record<
			string,
			unknown
		>;

		// Tokens are only tracked for successful (2xx) responses. A non-2xx
		// Anthropic response with a usage block (e.g. 429 with partial tokens)
		// is intentionally not counted to avoid billing complexity.
		if ( anthropicResponse.ok && result.usage ) {
			const usage = result.usage as {
				input_tokens: number;
				output_tokens: number;
			};
			await updateUsage(
				siteToken,
				usage.input_tokens + usage.output_tokens,
				env
			);
		}

		return new Response( JSON.stringify( result ), {
			status: anthropicResponse.status,
			headers: { 'Content-Type': 'application/json' },
		} );
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( 'Proxy error:', error );
		return jsonResponse( { error: 'Internal proxy error' }, 500 );
	}
}

// Mirrors NJ_Tier_Config::MONTHLY_LIMITS (PHP). Keep in sync.
const MONTHLY_LIMITS: Record< ProxyTier, number > = {
	free: 50_000,
	trial: 300_000,
	pro_managed: 2_000_000,
};

const MAX_TOKENS: Record< ProxyTier, number > = {
	free: 1_000,
	trial: 4_000,
	pro_managed: 8_000,
};

async function checkRateLimit(
	siteToken: string,
	tier: ProxyTier,
	env: Env
): Promise< { allowed: boolean; used: number; limit: number } > {
	const limit = MONTHLY_LIMITS[ tier ];
	const key = `usage:${ siteToken }:${ getCurrentMonth() }`;
	const used = parseInt( ( await env.USAGE_KV.get( key ) ) ?? '0', 10 );
	return { allowed: used < limit, used, limit };
}

async function updateUsage(
	siteToken: string,
	tokens: number,
	env: Env
): Promise< void > {
	const key = `usage:${ siteToken }:${ getCurrentMonth() }`;
	// KV does not support atomic increments, so concurrent requests perform a
	// non-atomic read-modify-write. Under burst load this can under-count tokens,
	// meaning at most one extra request per concurrent burst slips past the monthly
	// limit. Replace with a Durable Object counter (tracked in issue #312) to make
	// enforcement fully atomic.
	const current = parseInt( ( await env.USAGE_KV.get( key ) ) ?? '0', 10 );
	await env.USAGE_KV.put( key, String( current + tokens ), {
		expirationTtl: getSecondsUntilNextMonth(),
	} );
}

// Mirrors ClaudeProvider::MODELS (PHP). Keep in sync.
const TIER_MODELS: Record< ProxyTier, string[] > = {
	free: [ 'claude-haiku-4-5-20251001' ],
	trial: [ 'claude-haiku-4-5-20251001' ],
	pro_managed: [
		'claude-haiku-4-5-20251001',
		'claude-sonnet-4-6',
		'claude-opus-4-6',
	],
};

function getModelForTier( tier: ProxyTier, requestedModel?: string ): string {
	const allowed = TIER_MODELS[ tier ];
	if ( requestedModel && allowed.includes( requestedModel ) ) {
		return requestedModel;
	}
	return allowed[ 0 ];
}

function getCurrentMonth(): string {
	const now = new Date();
	return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart(
		2,
		'0'
	) }`;
}

function getSecondsUntilNextMonth(): number {
	const now = new Date();
	const next = new Date( now.getFullYear(), now.getMonth() + 1, 1 );
	return Math.floor( ( next.getTime() - now.getTime() ) / 1000 );
}

function jsonResponse( data: unknown, status = 200 ): Response {
	return new Response( JSON.stringify( data ), {
		status,
		headers: { 'Content-Type': 'application/json' },
	} );
}

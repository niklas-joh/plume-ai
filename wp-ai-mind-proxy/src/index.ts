import type { Env, ProxyRequest, ProxyTier } from './types';
import { verifySignature } from './signature';

export default {
	async fetch( request: Request, env: Env ): Promise<Response> {
		if ( request.method !== 'POST' ) {
			return jsonResponse( { error: 'Method not allowed' }, 405 );
		}

		const url = new URL( request.url );
		if ( url.pathname === '/v1/chat' ) {
			return handleChatProxy( request, env );
		}

		return jsonResponse( { error: 'Not found' }, 404 );
	},
};

const MAX_BODY_BYTES = 1_048_576; // 1 MB — guards against oversized message arrays

async function handleChatProxy( request: Request, env: Env ): Promise<Response> {
	try {
		const contentLength = parseInt( request.headers.get( 'Content-Length' ) ?? '0', 10 );
		if ( contentLength > MAX_BODY_BYTES ) {
			return jsonResponse( { error: 'Request body too large' }, 413 );
		}

		// Read raw body before parsing — signature covers the exact bytes WordPress sent.
		const bodyText = await request.text();
		const signature = request.headers.get( 'X-WP-Signature' ) ?? '';

		if ( ! signature || ! ( await verifySignature( bodyText, signature, env ) ) ) {
			return jsonResponse( { error: 'Invalid signature' }, 401 );
		}

		const body = JSON.parse( bodyText ) as ProxyRequest;
		const { user_id, tier, messages, model, max_tokens } = body;

		// Validate tier is one we proxy for.
		const validTiers: ProxyTier[] = [ 'free', 'trial', 'pro_managed' ];
		if ( ! validTiers.includes( tier ) ) {
			return jsonResponse( { error: 'Invalid tier for proxy' }, 400 );
		}

		// KV is the authoritative rate limit. WordPress pre-check is a fail-fast optimisation.
		const limitCheck = await checkRateLimit( user_id, tier, env );
		if ( ! limitCheck.allowed ) {
			return jsonResponse(
				{ error: 'Rate limit exceeded', used: limitCheck.used, limit: limitCheck.limit },
				429,
			);
		}

		// Enforce model selection per tier regardless of what WordPress requested.
		const selectedModel = getModelForTier( tier, model );

		// Clamp max_tokens to a per-tier ceiling so a large client-supplied value
		// cannot consume far more tokens than the per-response default allows.
		const resolvedMaxTokens = Math.min( max_tokens ?? MAX_TOKENS[ tier ], MAX_TOKENS[ tier ] );

		const anthropicResponse = await fetch( 'https://api.anthropic.com/v1/messages', {
			method: 'POST',
			headers: {
				'x-api-key': env.ANTHROPIC_API_KEY,
				'anthropic-version': '2023-06-01',
				'content-type': 'application/json',
			},
			body: JSON.stringify( {
				model: selectedModel,
				max_tokens: resolvedMaxTokens,
				messages,
			} ),
		} );

		const result = await anthropicResponse.json() as Record<string, unknown>;

		// Update KV usage counter after a successful Anthropic response.
		// Pass limitCheck.used to avoid a second KV read (prevents under-counting under concurrent load).
		if ( anthropicResponse.ok && result.usage ) {
			const usage = result.usage as { input_tokens: number; output_tokens: number };
			await updateUsage( user_id, usage.input_tokens + usage.output_tokens, limitCheck.used, env );
		}

		return new Response( JSON.stringify( result ), {
			status: anthropicResponse.status,
			headers: { 'Content-Type': 'application/json' },
		} );

	} catch ( error ) {
		console.error( 'Proxy error:', error );
		return jsonResponse( { error: 'Internal proxy error' }, 500 );
	}
}

// Per-tier ceiling for max_tokens forwarded to Anthropic.
const MAX_TOKENS: Record<ProxyTier, number> = {
	free:        1000,
	trial:       2000,
	pro_managed: 8000,
};

// Mirrors NJ_Tier_Config::MONTHLY_LIMITS (PHP). Keep in sync.
const MONTHLY_LIMITS: Record<ProxyTier, number> = {
	free:        50_000,
	trial:       300_000,
	pro_managed: 2_000_000,
};

async function checkRateLimit(
	userId: number,
	tier: ProxyTier,
	env: Env,
): Promise<{ allowed: boolean; used: number; limit: number }> {
	const limit = MONTHLY_LIMITS[ tier ];
	const monthKey = `usage:${ userId }:${ getCurrentMonth() }`;
	const currentUsage = parseInt( ( await env.USAGE_KV.get( monthKey ) ) ?? '0', 10 );
	return { allowed: currentUsage < limit, used: currentUsage, limit };
}

async function updateUsage( userId: number, tokens: number, knownUsage: number, env: Env ): Promise<void> {
	const monthKey = `usage:${ userId }:${ getCurrentMonth() }`;
	await env.USAGE_KV.put( monthKey, String( knownUsage + tokens ), {
		expirationTtl: getSecondsUntilNextMonth(),
	} );
}

// Mirrors ClaudeProvider::MODELS (PHP). Keep in sync.
const TIER_MODELS: Record<ProxyTier, string[]> = {
	free:        [ 'claude-haiku-4-5-20251001' ],
	trial:       [ 'claude-haiku-4-5-20251001' ],
	pro_managed: [ 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'claude-opus-4-6' ],
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
	return `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart( 2, '0' ) }`;
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

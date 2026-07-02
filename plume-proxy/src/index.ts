// src/index.ts

import {
	Env,
	ModelConfig,
	NormalizedResponse,
	ProxyRequest,
	ProxyTier,
	SiteRecord,
	SiteTier,
	ToolParam,
} from './types';
import { authenticateRequest, generateToken } from './auth';
import { handleActivationChallenge, handleRegistration } from './registration';
import { handleWebhook } from './webhook';
import { verifyHmac } from './signature';
import { chatCredits, GENERATOR_CREDITS, SEO_CREDITS, IMAGE_CREDITS } from './credits';

const MAX_BODY_BYTES = 1_048_576; // 1 MB

// Flat per-call credit cost for non-chat features. Chat is special-cased
// (token-weighted) in handleChatProxy; everything else is a fixed lookup.
const FLAT_FEATURE_CREDITS: Record<
	Exclude< ProxyRequest[ 'feature' ], 'chat' >,
	number
> = {
	generator: GENERATOR_CREDITS,
	seo: SEO_CREDITS,
	images: IMAGE_CREDITS,
};

type Provider = 'claude' | 'openai' | 'gemini';

// Fallback model lists used when no config:models entry exists in USAGE_KV.
// `free` carries only Claude Haiku — gpt-4.1-nano is deliberately not added
// (its weight is undefined, tracked in issue #856).
const DEFAULT_TIER_MODELS: Record< Provider, Record< ProxyTier, string[] > > = {
	claude: {
		free: [ 'claude-haiku-4-5-20251001' ],
		pro_managed: [
			'claude-haiku-4-5-20251001',
			'claude-sonnet-4-6',
			'claude-opus-4-6',
		],
	},
	openai: {
		free: [],
		pro_managed: [ 'gpt-4.1' ],
	},
	gemini: {
		free: [],
		pro_managed: [ 'gemini-3.5-flash', 'gemini-3.1-pro' ],
	},
};

// Fallback token weights — relative cost multipliers applied to raw token counts
// before computing credits, so quota enforcement is provider/model-agnostic.
const DEFAULT_MODEL_TOKEN_WEIGHT: Record< string, number > = {
	'claude-haiku-4-5-20251001': 1,
	'claude-sonnet-4-6': 3,
	'claude-opus-4-6': 5,
	'gpt-4.1': 2,
	'gemini-3.5-flash': 2,
	'gemini-3.1-pro': 2,
};

/**
 * Load model config from USAGE_KV (`config:models`).
 * Falls back to compiled defaults if the key is absent or unparseable.
 * Both `tier_models` and `model_token_weight` can be overridden independently.
 *
 * @param {Env} env Worker environment bindings.
 * @return {Promise<{tierModels: Record<string, Record<string, string[]>>, tokenWeights: Record<string, number>}>} Resolved model config.
 */
async function getModelConfig( env: Env ): Promise< {
	tierModels: Record< string, Record< string, string[] > >;
	tokenWeights: Record< string, number >;
} > {
	try {
		const raw = await env.USAGE_KV.get( 'config:models' );
		if ( raw ) {
			const parsed = JSON.parse( raw ) as ModelConfig;
			return {
				// Deep-merge so a partial KV config (e.g. only claude block) does not
				// discard the defaults for unmentioned providers or model weights.
				tierModels: { ...DEFAULT_TIER_MODELS, ...parsed.tier_models },
				tokenWeights: {
					...DEFAULT_MODEL_TOKEN_WEIGHT,
					...parsed.model_token_weight,
				},
			};
		}
	} catch {
		// Corrupt KV entry — fall through to defaults.
	}
	return {
		tierModels: DEFAULT_TIER_MODELS,
		tokenWeights: DEFAULT_MODEL_TOKEN_WEIGHT,
	};
}

function toClaudeTools( tools: ToolParam[] ) {
	return tools.map( ( t ) => ( {
		name: t.name,
		description: t.description,
		input_schema: t.parameters,
	} ) );
}

function toOpenAITools( tools: ToolParam[] ) {
	return tools.map( ( t ) => ( {
		type: 'function',
		function: {
			name: t.name,
			description: t.description,
			parameters: t.parameters,
		},
	} ) );
}

function toGeminiTools( tools: ToolParam[] ) {
	return [
		{
			functionDeclarations: tools.map( ( t ) => ( {
				name: t.name,
				description: t.description,
				// Gemini requires uppercase 'OBJECT'; list each field explicitly so future
				// additions to ToolParam.parameters do not accidentally bleed into the output.
				parameters: {
					type: 'OBJECT',
					properties: t.parameters.properties,
					required: t.parameters.required,
				},
			} ) ),
		},
	];
}

async function callClaude(
	body: ProxyRequest,
	resolvedModel: string,
	clampedMaxTokens: number,
	env: Env
): Promise< NormalizedResponse > {
	const claudeBody: Record< string, unknown > = {
		model: resolvedModel,
		max_tokens: clampedMaxTokens,
		messages: body.messages,
	};
	if ( body.system ) {
		claudeBody.system = body.system;
	}
	if ( body.tools && body.tools.length > 0 ) {
		claudeBody.tools = toClaudeTools( body.tools );
	}

	const response = await fetch( 'https://api.anthropic.com/v1/messages', {
		method: 'POST',
		headers: {
			'x-api-key': env.ANTHROPIC_API_KEY,
			'anthropic-version': '2023-06-01',
			'content-type': 'application/json',
		},
		body: JSON.stringify( claudeBody ),
	} );

	const result = ( await response.json() ) as Record< string, unknown >;

	if ( ! response.ok ) {
		throw Object.assign( new Error( 'Claude API error' ), {
			status: response.status,
			body: result,
		} );
	}

	const blocks = Array.isArray( result.content )
		? ( result.content as Array< {
				type: string;
				text?: string;
				id?: string;
				name?: string;
				input?: Record< string, unknown >;
		  } > )
		: [];
	const usage = ( result.usage as
		| { input_tokens: number; output_tokens: number }
		| undefined ) ?? { input_tokens: 0, output_tokens: 0 };

	const textBlock = blocks.find( ( b ) => b.type === 'text' && b.text );
	const toolBlocks = blocks.filter(
		( b ) => b.type === 'tool_use' && b.id && b.name
	);
	const textContent = textBlock?.text ?? '';

	if ( toolBlocks.length > 0 ) {
		return {
			content: textContent,
			usage: {
				input_tokens: usage.input_tokens,
				output_tokens: usage.output_tokens,
			},
			tool_calls: toolBlocks.map( ( b ) => ( {
				id: b.id as string,
				name: b.name as string,
				arguments: ( b.input as Record< string, unknown > ) ?? {},
			} ) ),
		};
	}

	return {
		content: textContent,
		usage: {
			input_tokens: usage.input_tokens,
			output_tokens: usage.output_tokens,
		},
	};
}

/**
 * Extract a plain text string from a system field that may be a string or a SystemBlock array.
 * Claude passes the block array through as-is; OpenAI and Gemini need a plain string.
 *
 * @param {ProxyRequest['system']} system - System field from the proxy request.
 * @return {string} Plain text content.
 */
function resolveSystemText( system: ProxyRequest[ 'system' ] ): string {
	if ( ! system ) {
		return '';
	}
	return typeof system === 'string' ? system : ( system[ 0 ]?.text ?? '' );
}

async function callOpenAI(
	body: ProxyRequest,
	resolvedModel: string,
	clampedMaxTokens: number,
	env: Env
): Promise< NormalizedResponse > {
	const sysText = resolveSystemText( body.system );
	const messages = sysText
		? [ { role: 'system', content: sysText }, ...body.messages ]
		: body.messages;
	const openaiBody: Record< string, unknown > = {
		model: resolvedModel,
		max_tokens: clampedMaxTokens,
		messages,
	};
	if ( body.tools && body.tools.length > 0 ) {
		openaiBody.tools = toOpenAITools( body.tools );
	}

	const response = await fetch(
		'https://api.openai.com/v1/chat/completions',
		{
			method: 'POST',
			headers: {
				Authorization: `Bearer ${ env.OPENAI_API_KEY }`,
				'content-type': 'application/json',
			},
			body: JSON.stringify( openaiBody ),
		}
	);

	const result = ( await response.json() ) as Record< string, unknown >;

	if ( ! response.ok ) {
		throw Object.assign( new Error( 'OpenAI API error' ), {
			status: response.status,
			body: result,
		} );
	}

	const choices = result.choices as Array< {
		message: {
			content: string;
			tool_calls?: Array< {
				id: string;
				function: { name: string; arguments: string };
			} >;
		};
		finish_reason: string;
	} >;
	const usage = result.usage as {
		prompt_tokens: number;
		completion_tokens: number;
	};
	const normalizedUsage = {
		input_tokens: usage.prompt_tokens,
		output_tokens: usage.completion_tokens,
	};

	if ( choices[ 0 ]?.finish_reason === 'tool_calls' ) {
		const tcs = choices[ 0 ].message.tool_calls ?? [];
		if ( tcs.length > 0 ) {
			return {
				content: '',
				usage: normalizedUsage,
				tool_calls: tcs.map( ( tc ) => ( {
					id: tc.id,
					name: tc.function.name,
					arguments: JSON.parse(
						tc.function.arguments ?? '{}'
					) as Record< string, unknown >,
				} ) ),
			};
		}
	}

	return {
		content: choices[ 0 ]?.message?.content ?? '',
		usage: normalizedUsage,
	};
}

async function callGemini(
	body: ProxyRequest,
	resolvedModel: string,
	clampedMaxTokens: number,
	env: Env
): Promise< NormalizedResponse > {
	const contents = body.messages.map( ( m ) => ( {
		role: m.role,
		parts: [ { text: m.content } ],
	} ) );
	const geminiBody: Record< string, unknown > = {
		contents,
		generationConfig: { maxOutputTokens: clampedMaxTokens },
	};
	const sysText = resolveSystemText( body.system );
	if ( sysText ) {
		geminiBody.systemInstruction = { parts: [ { text: sysText } ] };
	}
	if ( body.tools && body.tools.length > 0 ) {
		geminiBody.tools = toGeminiTools( body.tools );
	}

	const response = await fetch(
		`https://generativelanguage.googleapis.com/v1beta/models/${ resolvedModel }:generateContent?key=${ env.GEMINI_API_KEY }`,
		{
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( geminiBody ),
		}
	);

	const result = ( await response.json() ) as Record< string, unknown >;

	if ( ! response.ok ) {
		throw Object.assign( new Error( 'Gemini API error' ), {
			status: response.status,
			body: result,
		} );
	}

	const candidates = result.candidates as Array< {
		content: {
			parts: Array< {
				text?: string;
				functionCall?: {
					name: string;
					args?: Record< string, unknown >;
				};
			} >;
		};
	} >;
	const usageMeta = result.usageMetadata as {
		promptTokenCount: number;
		candidatesTokenCount: number;
	};
	const normalizedUsage = {
		input_tokens: usageMeta.promptTokenCount,
		output_tokens: usageMeta.candidatesTokenCount,
	};

	const functionCallParts = ( candidates[ 0 ]?.content?.parts ?? [] ).filter(
		( p ) => p.functionCall
	);

	if ( functionCallParts.length > 0 ) {
		return {
			content: '',
			usage: normalizedUsage,
			tool_calls: functionCallParts.map( ( part ) => ( {
				id: `gemini_${ crypto.randomUUID() }`,
				name: part.functionCall!.name,
				arguments: part.functionCall!.args ?? {},
			} ) ),
		};
	}

	return {
		content: candidates[ 0 ]?.content?.parts[ 0 ]?.text ?? '',
		usage: normalizedUsage,
	};
}

export default {
	async fetch(
		request: Request,
		env: Env,
		ctx: ExecutionContext
	): Promise< Response > {
		const { pathname } = new URL( request.url );

		if ( pathname === '/activation-challenge' ) {
			return handleActivationChallenge( request, env );
		}
		if ( pathname === '/register' ) {
			if ( request.method !== 'POST' ) {
				return jsonResponse( { error: 'Method not allowed' }, 405 );
			}
			return handleRegistration( request, env );
		}
		if ( pathname === '/webhook' ) {
			if ( request.method !== 'POST' ) {
				return jsonResponse( { error: 'Method not allowed' }, 405 );
			}
			return handleWebhook( request, env, ctx );
		}
		if ( pathname === '/rotate-secret' ) {
			if ( request.method !== 'POST' ) {
				return jsonResponse( { error: 'Method not allowed' }, 405 );
			}
			return handleRotateSecret( request, env );
		}

		// Dev endpoints for the devtools plugin.
		// Require Bearer token + HMAC-signed timestamp using the per-site tier_sync_secret.
		if (
			pathname === '/dev/set-tier' ||
			pathname === '/dev/reset-usage' ||
			pathname === '/dev/set-usage'
		) {
			// Return 404 in any environment where DEV_ENDPOINTS_ENABLED is absent (e.g. production).
			if ( ! env.DEV_ENDPOINTS_ENABLED ) {
				return jsonResponse( { error: 'Not found' }, 404 );
			}
			if ( request.method !== 'POST' ) {
				return jsonResponse( { error: 'Method not allowed' }, 405 );
			}
			if ( pathname === '/dev/set-tier' ) {
				return handleDevSetTier( request, env );
			}
			if ( pathname === '/dev/reset-usage' ) {
				return handleDevResetUsage( request, env );
			}
			return handleDevSetUsage( request, env );
		}

		if ( pathname === '/v1/chat' ) {
			if ( request.method !== 'POST' ) {
				return jsonResponse( { error: 'Method not allowed' }, 405 );
			}
			return handleChatProxy( request, env );
		}

		return jsonResponse( { error: 'Not found' }, 404 );
	},
};

/**
 * Issue a fresh tier-sync secret for a Bearer-authenticated site.
 *
 * Idempotent on the WP side — each call hands back a new secret and the WP
 * plugin simply overwrites its stored value. Used by sites registered before
 * the tier-sync handshake existed (backfill) and as a manual rotation path.
 *
 * @param {Request} request Incoming Worker request.
 * @param {Env}     env     Worker environment bindings.
 * @return {Promise<Response>} JSON response with new secret or error.
 */
async function handleRotateSecret(
	request: Request,
	env: Env
): Promise< Response > {
	const auth = await authenticateRequest( request, env );
	if ( ! auth.authenticated || ! auth.site_token || ! auth.record ) {
		return jsonResponse( { error: 'Unauthorised' }, 401 );
	}

	const newSecret = generateToken();
	const updated: SiteRecord = {
		...auth.record,
		tier_sync_secret: newSecret,
	};
	await env.USAGE_KV.put(
		`site:${ auth.site_token }`,
		JSON.stringify( updated )
	);

	return jsonResponse( {
		tier_sync_secret: newSecret,
		tier: updated.tier,
	} );
}

// ── Dev override endpoints ────────────────────────────────────────────────────

/** Replay window applied symmetrically to both past and future timestamps. */
const DEV_TIMESTAMP_WINDOW_S = 60;

/** Valid tier values accepted by /dev/set-tier. */
const VALID_TIERS: SiteTier[] = [ 'free', 'pro_managed', 'pro_byok' ];

/** Valid feature values accepted by /v1/chat — kept in lockstep with ProxyRequest['feature']. */
const VALID_FEATURES: ProxyRequest[ 'feature' ][] = [
	'chat',
	'generator',
	'seo',
	'images',
];

type DevAuthResult =
	| { authenticated: true; site_token: string; record: SiteRecord }
	| { authenticated: false; error: string; status: number };

/**
 * Verify a dev endpoint request: Bearer token + HMAC-signed timestamp.
 *
 * Two-factor check:
 *   1. Bearer token lookup (site identity, same as /v1/chat).
 *   2. HMAC-SHA-256 over `${timestamp}.${rawBodyText}` using the site's
 *      tier_sync_secret. Mirrors the TierUpdateWebhookController.php pattern
 *      (Worker→WP) in the reverse direction (WP→Worker).
 *
 * Required headers: X-Dev-Signature (hex HMAC) and X-Dev-Timestamp (unix sec).
 * Timestamp must be within ±60 s of the Worker clock to prevent replay.
 *
 * @param {Request} request  Incoming Worker request.
 * @param {string}  bodyText Raw request body (already read by caller).
 * @param {Env}     env      Worker environment bindings.
 * @return {Promise<DevAuthResult>} Auth result or error detail.
 */
async function authenticateDevRequest(
	request: Request,
	bodyText: string,
	env: Env
): Promise< DevAuthResult > {
	const auth = await authenticateRequest( request, env );
	if ( ! auth.authenticated || ! auth.site_token || ! auth.record ) {
		return { authenticated: false, error: 'Unauthorised', status: 401 };
	}

	const secret = auth.record.tier_sync_secret;
	if ( ! secret ) {
		return {
			authenticated: false,
			error: 'No dev secret — rotate the site secret first via /rotate-secret',
			status: 401,
		};
	}

	const signatureHex = request.headers.get( 'X-Dev-Signature' ) ?? '';
	const timestampStr = request.headers.get( 'X-Dev-Timestamp' ) ?? '';
	if ( ! signatureHex || ! timestampStr ) {
		return {
			authenticated: false,
			error: 'Missing X-Dev-Signature or X-Dev-Timestamp header',
			status: 401,
		};
	}

	const timestamp = parseInt( timestampStr, 10 );
	if ( isNaN( timestamp ) ) {
		return {
			authenticated: false,
			error: 'Invalid timestamp',
			status: 401,
		};
	}

	const skew = Math.floor( Date.now() / 1000 ) - timestamp;
	if ( skew > DEV_TIMESTAMP_WINDOW_S || skew < -DEV_TIMESTAMP_WINDOW_S ) {
		return {
			authenticated: false,
			error: 'Timestamp out of window',
			status: 401,
		};
	}

	// Payload mirrors TierUpdateWebhookController.php: `${timestamp}.${body}`.
	const valid = await verifyHmac(
		`${ timestamp }.${ bodyText }`,
		signatureHex,
		secret
	);
	if ( ! valid ) {
		return {
			authenticated: false,
			error: 'Invalid dev signature',
			status: 401,
		};
	}

	return {
		authenticated: true,
		site_token: auth.site_token,
		record: auth.record,
	};
}

/**
 * Override the tier stored in the site's KV record.
 * Mirrors what a real LemonSqueezy webhook would do, but without payment.
 *
 * @param {Request} request Incoming Worker request.
 * @param {Env}     env     Worker environment bindings.
 * @return {Promise<Response>} JSON response confirming the tier change or error.
 */
async function handleDevSetTier(
	request: Request,
	env: Env
): Promise< Response > {
	const bodyText = await request.text();
	const auth = await authenticateDevRequest( request, bodyText, env );
	if ( ! auth.authenticated ) {
		return jsonResponse( { error: auth.error }, auth.status );
	}

	let body: { tier?: string };
	try {
		body = JSON.parse( bodyText ) as { tier?: string };
	} catch {
		return jsonResponse( { error: 'Invalid JSON body' }, 400 );
	}

	if ( ! body.tier || ! VALID_TIERS.includes( body.tier as SiteTier ) ) {
		return jsonResponse( { error: 'Invalid tier' }, 400 );
	}

	const updated: SiteRecord = { ...auth.record, tier: body.tier as SiteTier };
	await env.USAGE_KV.put(
		`site:${ auth.site_token }`,
		JSON.stringify( updated )
	);

	return jsonResponse( { ok: true, tier: body.tier } );
}

/**
 * Zero out this month's credit counter for the authenticated site.
 *
 * @param {Request} request Incoming Worker request.
 * @param {Env}     env     Worker environment bindings.
 * @return {Promise<Response>} JSON response confirming the reset or error.
 */
async function handleDevResetUsage(
	request: Request,
	env: Env
): Promise< Response > {
	const bodyText = await request.text();
	const auth = await authenticateDevRequest( request, bodyText, env );
	if ( ! auth.authenticated ) {
		return jsonResponse( { error: auth.error }, auth.status );
	}

	const key = `usage:${ auth.site_token }:${ getCurrentMonth() }`;
	await env.USAGE_KV.put( key, '0', {
		expirationTtl: getSecondsUntilNextMonth(),
	} );

	return jsonResponse( { ok: true, usage: 0 } );
}

/**
 * Set this month's credit counter to an explicit value for the authenticated site.
 * Pass the tier's monthly limit (free=100, pro_managed=500) to simulate a
 * fully-exhausted quota.
 *
 * @param {Request} request Incoming Worker request.
 * @param {Env}     env     Worker environment bindings.
 * @return {Promise<Response>} JSON response confirming the new usage value or error.
 */
async function handleDevSetUsage(
	request: Request,
	env: Env
): Promise< Response > {
	const bodyText = await request.text();
	const auth = await authenticateDevRequest( request, bodyText, env );
	if ( ! auth.authenticated ) {
		return jsonResponse( { error: auth.error }, auth.status );
	}

	let body: { usage?: number };
	try {
		body = JSON.parse( bodyText ) as { usage?: number };
	} catch {
		return jsonResponse( { error: 'Invalid JSON body' }, 400 );
	}

	if ( typeof body.usage !== 'number' || body.usage < 0 ) {
		return jsonResponse(
			{ error: 'Invalid usage value — must be a non-negative number' },
			400
		);
	}

	const key = `usage:${ auth.site_token }:${ getCurrentMonth() }`;
	await env.USAGE_KV.put( key, String( body.usage ), {
		expirationTtl: getSecondsUntilNextMonth(),
	} );

	return jsonResponse( { ok: true, usage: body.usage } );
}

// ─────────────────────────────────────────────────────────────────────────────

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
		if ( ! body.feature || ! VALID_FEATURES.includes( body.feature ) ) {
			return jsonResponse(
				{ error: 'Missing or invalid feature — must be one of: chat, generator, seo, images' },
				400
			);
		}
		const { feature } = body;

		const { model, max_tokens: maxTokens } = body;
		const { site_token: siteToken, tier } = auth;

		const provider: Provider = body.provider ?? 'claude';
		if (
			provider !== 'claude' &&
			provider !== 'openai' &&
			provider !== 'gemini'
		) {
			return jsonResponse( { error: 'Unknown provider' }, 400 );
		}

		// BYOK sites call Anthropic directly via ClaudeProvider and must never reach here.
		if ( tier === 'pro_byok' ) {
			return jsonResponse(
				{ error: 'BYOK tier must call Anthropic directly' },
				403
			);
		}

		const effectiveTier = tier as ProxyTier;

		const rateLimitCheck = await checkRateLimit(
			siteToken,
			effectiveTier,
			env
		);
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

		const { tierModels, tokenWeights } = await getModelConfig( env );
		const selectedModel = getModelForTier(
			provider,
			effectiveTier,
			tierModels,
			model
		);
		const clampedMaxTokens = Math.min(
			maxTokens ?? ( effectiveTier === 'free' ? 1000 : 4000 ),
			MAX_TOKENS[ effectiveTier ]
		);

		let normalized: NormalizedResponse;
		if ( provider === 'claude' ) {
			normalized = await callClaude(
				body,
				selectedModel,
				clampedMaxTokens,
				env
			);
		} else if ( provider === 'openai' ) {
			normalized = await callOpenAI(
				body,
				selectedModel,
				clampedMaxTokens,
				env
			);
		} else {
			normalized = await callGemini(
				body,
				selectedModel,
				clampedMaxTokens,
				env
			);
		}

		// Intermediate tool-use steps are not billed; only the final response is.
		// The final call's usage.input_tokens naturally encompasses all prior context,
		// so total token cost is captured without needing cross-request accumulation.
		// Scoped to 'chat' so flat-rate features are never silently zeroed if they
		// ever gain tool support in future.
		const isToolUseStep =
			feature === 'chat' && ( normalized.tool_calls?.length ?? 0 ) > 0;

		let creditsCharged: number;
		if ( isToolUseStep ) {
			creditsCharged = 0;
		} else if ( feature === 'chat' ) {
			const weight = tokenWeights[ selectedModel ] ?? 1;
			creditsCharged = chatCredits(
				normalized.usage.input_tokens,
				normalized.usage.output_tokens,
				weight
			);
		} else {
			creditsCharged = FLAT_FEATURE_CREDITS[ feature ];
		}

		if ( ! isToolUseStep ) {
			await updateUsage( siteToken, creditsCharged, env );
		}

		const responseData: Record< string, unknown > = {
			content: normalized.content,
			usage: normalized.usage,
			credits_charged: creditsCharged,
			model: selectedModel,
		};
		if ( isToolUseStep ) {
			responseData.tool_calls = normalized.tool_calls;
		}
		return jsonResponse( responseData );
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( 'Proxy error:', error );
		// Only honour our own tagged validation error (the typed 400 from
		// getModelForTier). Upstream provider errors also carry a `status`
		// (e.g. a 429/401 from our Anthropic/OpenAI account) but must never be
		// forwarded verbatim, as their status semantics collide with the
		// proxy's own — they collapse to a generic 500 instead.
		const tagged = error as { status?: number; isValidationError?: boolean };
		const isValidationError =
			tagged?.isValidationError === true &&
			typeof tagged.status === 'number';
		const status = isValidationError ? ( tagged.status as number ) : 500;
		const message =
			isValidationError && error instanceof Error
				? error.message
				: 'Internal proxy error';
		return jsonResponse( { error: message }, status );
	}
}

// Worker is now authoritative for monthly allowances, expressed in credits
// (not raw tokens). PR 2 will have the plugin fetch this from the Worker at
// runtime rather than re-declaring it in PHP — see plan §3.3.
const MONTHLY_CREDIT_LIMITS: Record< ProxyTier, number > = {
	free: 100,
	pro_managed: 500,
};

const MAX_TOKENS: Record< ProxyTier, number > = {
	free: 6_000,
	pro_managed: 8_000,
};

async function checkRateLimit(
	siteToken: string,
	tier: ProxyTier,
	env: Env
): Promise< { allowed: boolean; used: number; limit: number } > {
	const limit = MONTHLY_CREDIT_LIMITS[ tier ];
	const key = `usage:${ siteToken }:${ getCurrentMonth() }`;
	const used = parseInt( ( await env.USAGE_KV.get( key ) ) ?? '0', 10 );
	return { allowed: used < limit, used, limit };
}

async function updateUsage(
	siteToken: string,
	credits: number,
	env: Env
): Promise< void > {
	const key = `usage:${ siteToken }:${ getCurrentMonth() }`;
	// KV does not support atomic increments, so concurrent requests perform a
	// non-atomic read-modify-write. Under burst load this can under-count credits.
	// Replace with a Durable Object counter (tracked in issue #312) to make
	// enforcement fully atomic. NOT fixed by this migration — see risk list.
	const current = parseInt( ( await env.USAGE_KV.get( key ) ) ?? '0', 10 );
	await env.USAGE_KV.put( key, String( current + credits ), {
		expirationTtl: getSecondsUntilNextMonth(),
	} );
}

function getModelForTier(
	provider: Provider,
	tier: ProxyTier,
	tierModels: Record< string, Record< string, string[] > >,
	requestedModel?: string
): string {
	const allowed =
		tierModels[ provider ]?.[ tier ] ??
		DEFAULT_TIER_MODELS[ provider ][ tier ];
	if ( allowed.length === 0 ) {
		// Tagged with isValidationError so the handleChatProxy catch block can
		// distinguish this intended 400 from arbitrary upstream provider errors
		// (which also carry a `status`) and avoid forwarding their status verbatim.
		throw Object.assign(
			new Error( `No ${ provider } models available for tier ${ tier }` ),
			{ status: 400, isValidationError: true }
		);
	}
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

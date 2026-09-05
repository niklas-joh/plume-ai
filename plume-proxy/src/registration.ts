// src/registration.ts

import { Env, SiteRecord, VerificationFailureReason } from './types';
import { generateToken } from './auth';

export const REGISTRATION_RATE_LIMIT = 5; // max new registrations per IP per hour
export const CHALLENGE_RATE_LIMIT = 20; // max challenge requests per IP per hour
const REGISTRATION_WINDOW_TTL = 3600; // seconds
const CHALLENGE_TTL = 300; // seconds

/**
 * Generate a single-use registration challenge token and store it in KV.
 * The PHP plugin fetches this first, stores it as a transient, then sends it
 * back during /register so the worker can verify the site is live.
 *
 * @param {Request} request Incoming Worker request.
 * @param {Env}     env     Worker environment bindings.
 * @return {Promise<Response>} JSON response with challenge token or error.
 */
export async function handleActivationChallenge(
	request: Request,
	env: Env
): Promise< Response > {
	if ( request.method !== 'GET' ) {
		return jsonResponse( { error: 'Method not allowed' }, 405 );
	}

	// Rate-limit challenge issuance by IP to prevent KV exhaustion.
	const ip = request.headers.get( 'CF-Connecting-IP' ) ?? 'unknown';
	const challengeRateLimitKey = `challenge_ip:${ ip }:${ getCurrentHour() }`;
	const challengeAttempts = parseInt(
		( await env.USAGE_KV.get( challengeRateLimitKey ) ) ?? '0',
		10
	);
	if ( challengeAttempts >= CHALLENGE_RATE_LIMIT ) {
		return jsonResponse(
			{ error: 'Too many challenge requests. Try again later.' },
			429
		);
	}
	await env.USAGE_KV.put(
		challengeRateLimitKey,
		String( challengeAttempts + 1 ),
		{
			expirationTtl: REGISTRATION_WINDOW_TTL,
		}
	);

	const bytes = new Uint8Array( 32 );
	crypto.getRandomValues( bytes );
	const challenge = Array.from( bytes )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );

	await env.USAGE_KV.put( `challenge:${ challenge }`, '1', {
		expirationTtl: CHALLENGE_TTL,
	} );

	return jsonResponse( { challenge } );
}

/**
 * Register a new site or return its existing token if already registered.
 *
 * @param {Request} request Incoming Worker request.
 * @param {Env}     env     Worker environment bindings.
 * @return {Promise<Response>} JSON response with token and tier or error.
 */
export async function handleRegistration(
	request: Request,
	env: Env
): Promise< Response > {
	if ( request.method !== 'POST' ) {
		return jsonResponse( { error: 'Method not allowed' }, 405 );
	}

	let body: { site_url?: string; challenge_token?: string };
	try {
		body = ( await request.json() ) as {
			site_url?: string;
			challenge_token?: string;
		};
	} catch {
		return jsonResponse( { error: 'Invalid JSON' }, 400 );
	}

	const siteUrl = ( body.site_url ?? '' ).trim();
	if ( ! siteUrl || ! isValidUrl( siteUrl ) ) {
		return jsonResponse( { error: 'Invalid site_url' }, 400 );
	}

	// Challenge is mandatory — no token means an unverified registration attempt.
	const challengeToken = ( body.challenge_token ?? '' ).trim();
	if ( ! challengeToken ) {
		return jsonResponse( { error: 'challenge_token required' }, 403 );
	}

	// Validate challenge: must exist in KV (single-use, deleted after successful callback).
	const storedChallenge = await env.USAGE_KV.get(
		`challenge:${ challengeToken }`
	);
	if ( ! storedChallenge ) {
		return jsonResponse( { error: 'Invalid or expired challenge' }, 403 );
	}

	// Verify the site is live by calling back to its WP REST endpoint.
	const verifyUrl =
		siteUrl.replace( /\/$/, '' ) +
		'/wp-json/plume/v1/activation-verify' +
		'?challenge=' +
		encodeURIComponent( challengeToken );
	const failure = await verifySite( verifyUrl );
	if ( failure ) {
		return jsonResponse(
			{
				error: 'Site verification failed',
				reason: failure.reason,
				...( failure.status !== undefined && {
					status: failure.status,
				} ),
			},
			403
		);
	}
	// Consume the challenge only after a successful callback so a transient
	// network failure does not permanently invalidate the token.
	await env.USAGE_KV.delete( `challenge:${ challengeToken }` );

	// Idempotent — return the existing token if already registered.
	const urlHash = await sha256( siteUrl );
	const existingToken = await env.USAGE_KV.get( `site_url:${ urlHash }` );
	if ( existingToken ) {
		const record = await env.USAGE_KV.get< SiteRecord >(
			`site:${ existingToken }`,
			'json'
		);
		if ( record ) {
			return jsonResponse( { token: existingToken, tier: record.tier } );
		}
	}

	// Rate-limit new registrations by IP to prevent KV exhaustion.
	// Non-atomic read-modify-write: under burst load the counter can under-count,
	// allowing up to REGISTRATION_RATE_LIMIT concurrent bursts instead of exactly
	// REGISTRATION_RATE_LIMIT. Acceptable for registration (low-frequency); tracked
	// in issue #309 alongside the usage-tracking race in index.ts.
	const ip = request.headers.get( 'CF-Connecting-IP' ) ?? 'unknown';
	const rateLimitKey = `reg_ip:${ ip }:${ getCurrentHour() }`;
	const attempts = parseInt(
		( await env.USAGE_KV.get( rateLimitKey ) ) ?? '0',
		10
	);
	if ( attempts >= REGISTRATION_RATE_LIMIT ) {
		return jsonResponse(
			{ error: 'Too many registration attempts. Try again later.' },
			429
		);
	}
	await env.USAGE_KV.put( rateLimitKey, String( attempts + 1 ), {
		expirationTtl: REGISTRATION_WINDOW_TTL,
	} );

	const token = generateToken();
	const tierSyncSecret = generateToken();
	const now = Date.now();
	const record: SiteRecord = {
		site_url: siteUrl,
		tier: 'free',
		created_at: now,
		tier_sync_secret: tierSyncSecret,
	};

	await env.USAGE_KV.put( `site:${ token }`, JSON.stringify( record ) );
	await env.USAGE_KV.put( `site_url:${ urlHash }`, token );

	return jsonResponse(
		{ token, tier: 'free', tier_sync_secret: tierSyncSecret },
		201
	);
}

/**
 * Result of a failed verifySite() call.
 */
interface VerificationFailure {
	reason: VerificationFailureReason;
	status?: number;
}

/**
 * Call back to the site's `/wp-json/plume/v1/activation-verify` endpoint and
 * classify the outcome.
 *
 * @param {string} verifyUrl Fully-formed callback URL, including the challenge query arg.
 * @return {Promise<VerificationFailure|null>} `null` on a 2xx response; a classified
 *         failure otherwise.
 */
async function verifySite(
	verifyUrl: string
): Promise< VerificationFailure | null > {
	try {
		const cbRes = await fetch( verifyUrl, {
			signal: AbortSignal.timeout( 10_000 ),
		} );
		if ( cbRes.ok ) {
			return null;
		}
		if ( cbRes.status === 401 ) {
			return { reason: 'http_unauthorized', status: cbRes.status };
		}
		if ( cbRes.status === 403 ) {
			return { reason: 'http_forbidden', status: cbRes.status };
		}
		return { reason: 'http_error', status: cbRes.status };
	} catch ( err ) {
		return classifyFetchError( err );
	}
}

/**
 * Classify a thrown fetch() error into a VerificationFailure.
 *
 * Deliberately conservative default: an edge fetch that throws for an
 * unrecognized reason is far more likely to indicate a structurally
 * unreachable site (e.g. no public DNS, connection refused) than a
 * transient blip, so anything not matched below is treated as
 * `network_error` — one of the plugin's PERMANENT_VERIFICATION_REASONS.
 *
 * @param {unknown} err The error thrown by fetch().
 * @return {VerificationFailure} Classified failure (no `status` — the request never got a response).
 */
function classifyFetchError( err: unknown ): VerificationFailure {
	if (
		err instanceof DOMException &&
		( err.name === 'TimeoutError' || err.name === 'AbortError' )
	) {
		return { reason: 'timeout' };
	}
	const message = err instanceof Error ? err.message.toLowerCase() : '';
	if (
		message.includes( 'certificate' ) ||
		message.includes( 'tls' ) ||
		message.includes( 'ssl' )
	) {
		return { reason: 'tls_error' };
	}
	return { reason: 'network_error' };
}

function getCurrentHour(): string {
	const now = new Date();
	return `${ now.getUTCFullYear() }-${ String(
		now.getUTCMonth() + 1
	).padStart( 2, '0' ) }-${ String( now.getUTCDate() ).padStart(
		2,
		'0'
	) }-${ String( now.getUTCHours() ).padStart( 2, '0' ) }`;
}

async function sha256( input: string ): Promise< string > {
	const bytes = await crypto.subtle.digest(
		'SHA-256',
		new TextEncoder().encode( input )
	);
	return Array.from( new Uint8Array( bytes ) )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

function isValidUrl( url: string ): boolean {
	try {
		const { protocol } = new URL( url );
		return protocol === 'http:' || protocol === 'https:';
	} catch {
		return false;
	}
}

function jsonResponse( data: unknown, status = 200 ): Response {
	return new Response( JSON.stringify( data ), {
		status,
		headers: { 'Content-Type': 'application/json' },
	} );
}

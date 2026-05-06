// src/registration.ts

import { Env, SiteRecord } from './types';
import { generateToken } from './auth';
import { TRIAL_PERIOD_MS } from './constants';

export const REGISTRATION_RATE_LIMIT = 5; // max new registrations per IP per hour
export const CHALLENGE_RATE_LIMIT = 20; // max challenge requests per IP per hour
const REGISTRATION_WINDOW_TTL = 3600; // seconds
const CHALLENGE_TTL = 300; // seconds

/**
 * Generate a single-use registration challenge token and store it in KV.
 * The PHP plugin fetches this first, stores it as a transient, then sends it
 * back during /register so the worker can verify the site is live.
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
	await env.USAGE_KV.put( challengeRateLimitKey, String( challengeAttempts + 1 ), {
		expirationTtl: REGISTRATION_WINDOW_TTL,
	} );

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
		'/wp-json/wp-ai-mind/v1/activation-verify' +
		'?challenge=' +
		encodeURIComponent( challengeToken );
	let siteVerified = false;
	try {
		const cbRes = await fetch( verifyUrl, {
			signal: AbortSignal.timeout( 10_000 ),
		} );
		siteVerified = cbRes.ok;
	} catch {
		siteVerified = false;
	}
	if ( ! siteVerified ) {
		return jsonResponse( { error: 'Site verification failed' }, 403 );
	}
	// Consume the challenge only after a successful callback so a transient
	// network failure does not permanently invalidate the token.
	await env.USAGE_KV.delete( `challenge:${ challengeToken }` );

	// Idempotent — return the existing token if already registered.
	// If the stored record is an expired trial, demote it to free.
	const urlHash = await sha256( siteUrl );
	const existingToken = await env.USAGE_KV.get( `site_url:${ urlHash }` );
	if ( existingToken ) {
		const record = await env.USAGE_KV.get< SiteRecord >(
			`site:${ existingToken }`,
			'json'
		);
		if ( record ) {
			const startedAt = record.trial_started_at ?? record.created_at;
			if (
				record.tier === 'trial' &&
				Date.now() - startedAt > TRIAL_PERIOD_MS
			) {
				const demoted: SiteRecord = { ...record, tier: 'free' };
				await env.USAGE_KV.put(
					`site:${ existingToken }`,
					JSON.stringify( demoted )
				);
				return jsonResponse( {
					token: existingToken,
					tier: 'free',
				} );
			}
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
	const now = Date.now();
	const record: SiteRecord = {
		site_url: siteUrl,
		tier: 'trial',
		created_at: now,
		trial_started_at: now,
	};

	await env.USAGE_KV.put( `site:${ token }`, JSON.stringify( record ) );
	await env.USAGE_KV.put( `site_url:${ urlHash }`, token );

	return jsonResponse( { token, tier: 'trial' }, 201 );
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

// src/registration.ts

import { Env, SiteRecord } from './types';
import { generateToken } from './auth';

export const REGISTRATION_RATE_LIMIT = 5; // max new registrations per IP per hour
const REGISTRATION_WINDOW_TTL = 3600; // seconds

export async function handleRegistration(
	request: Request,
	env: Env
): Promise< Response > {
	if ( request.method !== 'POST' ) {
		return jsonResponse( { error: 'Method not allowed' }, 405 );
	}

	let body: { site_url?: string };
	try {
		body = ( await request.json() ) as { site_url?: string };
	} catch {
		return jsonResponse( { error: 'Invalid JSON' }, 400 );
	}

	const siteUrl = ( body.site_url ?? '' ).trim();
	if ( ! siteUrl || ! isValidUrl( siteUrl ) ) {
		return jsonResponse( { error: 'Invalid site_url' }, 400 );
	}

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
	const record: SiteRecord = {
		site_url: siteUrl,
		tier: 'free',
		created_at: Date.now(),
	};

	await env.USAGE_KV.put( `site:${ token }`, JSON.stringify( record ) );
	await env.USAGE_KV.put( `site_url:${ urlHash }`, token );

	return jsonResponse( { token, tier: 'free' }, 201 );
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

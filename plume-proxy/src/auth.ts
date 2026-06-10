// src/auth.ts

import { Env, SiteRecord, SiteTier } from './types';

export interface AuthResult {
	authenticated: boolean;
	site_token?: string;
	tier?: SiteTier;
	site_url?: string;
	record?: SiteRecord;
}

/**
 * Verify the Bearer token in the request against the USAGE_KV store.
 *
 * @param {Request} request Incoming Worker request.
 * @param {Env}     env     Worker environment bindings.
 * @return {Promise<AuthResult>} Authentication result with site record if valid.
 */
export async function authenticateRequest(
	request: Request,
	env: Env
): Promise< AuthResult > {
	const authHeader = request.headers.get( 'Authorization' ) ?? '';
	if ( ! authHeader.startsWith( 'Bearer ' ) ) {
		return { authenticated: false };
	}

	const token = authHeader.slice( 7 ).trim();
	if ( ! token ) {
		return { authenticated: false };
	}

	const record = await env.USAGE_KV.get< SiteRecord >(
		`site:${ token }`,
		'json'
	);
	if ( ! record ) {
		return { authenticated: false };
	}

	return {
		authenticated: true,
		site_token: token,
		tier: record.tier,
		site_url: record.site_url,
		record,
	};
}

/**
 * Generate a cryptographically random 32-byte hex token.
 *
 * @return {string} 64-character hex string.
 */
export function generateToken(): string {
	const bytes = new Uint8Array( 32 );
	crypto.getRandomValues( bytes );
	return Array.from( bytes )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

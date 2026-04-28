// src/auth.ts

import { Env, SiteRecord, AnyTier } from './types';

export interface AuthResult {
	authenticated: boolean;
	site_token?: string;
	tier?: AnyTier;
	site_url?: string;
}

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
	};
}

export function generateToken(): string {
	const bytes = new Uint8Array( 32 );
	crypto.getRandomValues( bytes );
	return Array.from( bytes )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

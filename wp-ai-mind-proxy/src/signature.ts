// Signs the raw request body bytes — avoids JSON key-ordering fragility.
// crypto.subtle.verify() is constant-time (no timing oracle).
import type { Env } from './types';

export async function verifySignature(
	bodyText: string,
	signature: string,
	env: Env,
): Promise<boolean> {
	try {
		const key = await crypto.subtle.importKey(
			'raw',
			new TextEncoder().encode( env.PROXY_SIGNATURE_SECRET ),
			{ name: 'HMAC', hash: 'SHA-256' },
			false,
			[ 'verify' ],
		);
		const sigBytes = hexToBytes( signature );
		return await crypto.subtle.verify(
			'HMAC',
			key,
			sigBytes,
			new TextEncoder().encode( bodyText ),
		);
	} catch {
		return false;
	}
}

function hexToBytes( hex: string ): Uint8Array {
	if ( hex.length % 2 !== 0 || ! /^[0-9a-f]+$/i.test( hex ) ) {
		return new Uint8Array( 0 ); // crypto.subtle.verify will return false safely
	}
	const out = new Uint8Array( hex.length / 2 );
	for ( let i = 0; i < hex.length; i += 2 ) {
		out[ i / 2 ] = parseInt( hex.slice( i, i + 2 ), 16 );
	}
	return out;
}

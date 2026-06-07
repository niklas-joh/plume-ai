// src/signature.ts
// Verifies HMAC-SHA-256 signatures. Used for both LemonSqueezy webhook
// signatures (X-Signature header) and dev endpoint auth (X-Dev-Signature).
// Uses constant-time comparison to prevent timing attacks.

import { Env } from './types';

/**
 * Verify a hex-encoded HMAC-SHA-256 signature over bodyText using secret.
 *
 * Returns false for any invalid input (malformed hex, wrong HMAC, empty secret).
 *
 * @param {string} bodyText     The exact string that was signed.
 * @param {string} signatureHex Hex-encoded HMAC sent by the caller.
 * @param {string} secret       Raw HMAC secret key.
 * @return {Promise<boolean>} True if the signature is valid.
 */
export async function verifyHmac(
	bodyText: string,
	signatureHex: string,
	secret: string
): Promise< boolean > {
	try {
		if ( ! secret ) return false;
		if (
			! signatureHex ||
			signatureHex.length % 2 !== 0 ||
			! /^[0-9a-f]+$/i.test( signatureHex )
		) {
			return false;
		}
		const key = await crypto.subtle.importKey(
			'raw',
			new TextEncoder().encode( secret ),
			{ name: 'HMAC', hash: 'SHA-256' },
			false,
			[ 'verify' ]
		);
		const sigBytes = new Uint8Array( signatureHex.length / 2 );
		for ( let i = 0; i < signatureHex.length; i += 2 ) {
			sigBytes[ i / 2 ] = parseInt( signatureHex.slice( i, i + 2 ), 16 );
		}
		return await crypto.subtle.verify(
			'HMAC',
			key,
			sigBytes,
			new TextEncoder().encode( bodyText )
		);
	} catch {
		return false;
	}
}

/**
 * Verify a LemonSqueezy webhook signature from the X-Signature header.
 *
 * @param {string} bodyText  Raw request body string.
 * @param {string} signature Hex HMAC from the X-Signature header.
 * @param {Env}    env       Worker env bindings (reads LS_WEBHOOK_SECRET).
 * @return {Promise<boolean>} True if the signature is valid.
 */
export async function verifyLsSignature(
	bodyText: string,
	signature: string,
	env: Env
): Promise< boolean > {
	return verifyHmac( bodyText, signature, env.LS_WEBHOOK_SECRET );
}

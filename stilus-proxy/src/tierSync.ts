// src/tierSync.ts
// Pushes signed tier-update notifications from the Worker to a registered WP site.
// Mirrors the verification logic in includes/Payments/TierUpdateWebhookController.php.

import type { SiteTier } from './types';

const TIER_UPDATE_PATH = '/wp-json/stilus/v1/tier-update';
const BACKOFF_MS = [ 1000, 3000, 9000 ];
const REQUEST_TIMEOUT_MS = 5_000;

/**
 * Compute a hex HMAC-SHA256 digest over `${timestamp}.${body}` using the shared secret.
 *
 * Co-located here (rather than reused from signature.ts) because that helper is shaped
 * for raw-bytes verification of LemonSqueezy signatures, not for our timestamp-prefixed
 * push format.
 *
 * @param {number} timestamp Unix timestamp in seconds.
 * @param {string} body      JSON-serialised payload string.
 * @param {string} secret    Shared HMAC secret.
 * @return {Promise<string>} Hex-encoded HMAC-SHA256 digest.
 */
async function signTierPayload(
	timestamp: number,
	body: string,
	secret: string
): Promise< string > {
	const key = await crypto.subtle.importKey(
		'raw',
		new TextEncoder().encode( secret ),
		{ name: 'HMAC', hash: 'SHA-256' },
		false,
		[ 'sign' ]
	);
	const sigBytes = await crypto.subtle.sign(
		'HMAC',
		key,
		new TextEncoder().encode( `${ timestamp }.${ body }` )
	);
	return Array.from( new Uint8Array( sigBytes ) )
		.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
		.join( '' );
}

/**
 * Push a tier-update notification to a WordPress site. Caller is responsible for
 * running this inside `ctx.waitUntil()` so the Worker's response to the LS webhook
 * is not delayed by the WP round-trip.
 *
 * Retries up to three times with backoff [1s, 3s, 9s] on connection errors or
 * non-2xx responses. Silently gives up after the final attempt — the WP site is
 * never the source of truth for tier state, so a failed push degrades to "WP is
 * temporarily stale" rather than data loss.
 *
 * @param {string}   siteUrl WordPress site URL (trailing slash stripped internally).
 * @param {string}   secret  Per-site tier_sync_secret from the KV record.
 * @param {SiteTier} tier    New tier to push to the site.
 * @return {Promise<void>}
 */
export async function pushTierUpdate(
	siteUrl: string,
	secret: string,
	tier: SiteTier
): Promise< void > {
	const url = siteUrl.replace( /\/$/, '' ) + TIER_UPDATE_PATH;
	const body = JSON.stringify( { tier } );

	for ( let attempt = 0; attempt < BACKOFF_MS.length; attempt++ ) {
		// Sign on each attempt — replay window on the receiver is short, so an
		// earlier signature could expire between retries.
		const timestamp = Math.floor( Date.now() / 1000 );
		let signature: string;
		try {
			signature = await signTierPayload( timestamp, body, secret );
		} catch {
			// crypto.subtle failure is non-recoverable; abort the whole push.
			return;
		}

		try {
			const res = await fetch( url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-Stilus-Signature': signature,
					'X-Stilus-Timestamp': String( timestamp ),
				},
				body,
				signal: AbortSignal.timeout( REQUEST_TIMEOUT_MS ),
			} );

			if ( res.ok ) {
				return;
			}

			if ( res.status >= 400 && res.status < 500 ) {
				console.error( '[tierSync] Terminal error, not retrying:', { status: res.status, siteUrl } );
				return;
			}
		} catch {
			// Connection error — fall through to backoff.
		}

		if ( attempt < BACKOFF_MS.length - 1 ) {
			await new Promise( ( resolve ) =>
				setTimeout( resolve, BACKOFF_MS[ attempt ] )
			);
		}
	}
}

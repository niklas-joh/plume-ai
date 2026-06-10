import { createHmac } from 'crypto';

/**
 * Compute a hex HMAC-SHA256 signature for test request bodies.
 *
 * @param {string} body   Raw body string to sign.
 * @param {string} secret HMAC secret.
 * @return {string} Hex-encoded HMAC-SHA256 digest.
 */
export function signBody( body: string, secret: string ): string {
	return createHmac( 'sha256', secret ).update( body ).digest( 'hex' );
}

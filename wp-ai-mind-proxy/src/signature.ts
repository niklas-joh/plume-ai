// src/signature.ts
// Verifies LemonSqueezy webhook signatures sent in the X-Signature header.
// Uses constant-time comparison to prevent timing attacks.

import { Env } from './types';

export async function verifyLsSignature(
  bodyText: string,
  signature: string,
  env: Env
): Promise<boolean> {
  try {
    if (!signature || signature.length % 2 !== 0 || !/^[0-9a-f]+$/i.test(signature)) {
      return false;
    }
    const key = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode(env.LS_WEBHOOK_SECRET),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['verify']
    );
    const sigBytes = new Uint8Array(signature.length / 2);
    for (let i = 0; i < signature.length; i += 2) {
      sigBytes[i / 2] = parseInt(signature.slice(i, i + 2), 16);
    }
    return await crypto.subtle.verify('HMAC', key, sigBytes, new TextEncoder().encode(bodyText));
  } catch {
    return false;
  }
}

import { hex } from './utils';

const ITERATIONS = 100_000;
const KEY_BYTES  = 32;

export async function hashPassword(plain: string): Promise<string> {
  const salt    = crypto.getRandomValues(new Uint8Array(16));
  const keyMat  = await derive(plain, salt);
  return `${hex(salt)}:${hex(new Uint8Array(keyMat))}`;
}

export async function verifyPassword(plain: string, stored: string): Promise<boolean> {
  try {
    const parts = stored.split(':');
    if (parts.length !== 2) return false;
    const [saltHex, hashHex] = parts;
    if (saltHex.length !== 32 || hashHex.length !== 64) return false;
    const salt   = unhex(saltHex);
    const keyMat = await derive(plain, salt);
    const a = new Uint8Array(keyMat);
    const b = unhex(hashHex);
    if (a.length !== b.length) return false;
    let diff = 0;
    for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
    return diff === 0;
  } catch {
    return false;
  }
}

async function derive(plain: string, salt: Uint8Array): Promise<ArrayBuffer> {
  const base = await crypto.subtle.importKey(
    'raw', new TextEncoder().encode(plain), 'PBKDF2', false, ['deriveBits']
  );
  return crypto.subtle.deriveBits(
    { name: 'PBKDF2', hash: 'SHA-256', salt, iterations: ITERATIONS },
    base, KEY_BYTES * 8
  );
}

function unhex(s: string): Uint8Array {
  const pairs = s.match(/.{2}/g);
  if (!pairs) throw new Error('Invalid hex string');
  return new Uint8Array(pairs.map(b => parseInt(b, 16)));
}

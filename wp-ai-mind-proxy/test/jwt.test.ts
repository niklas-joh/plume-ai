import { describe, it, expect } from 'vitest';
import { signJWT, verifyJWT } from '../src/jwt';

const SECRET = 'test-secret-32-chars-minimum-length';

describe('signJWT', () => {
  it('returns a three-part dot-separated string', async () => {
    const token = await signJWT({ sub: 1 }, SECRET, 3600);
    expect(token.split('.')).toHaveLength(3);
  });

  it('encodes the payload correctly', async () => {
    const token   = await signJWT({ sub: 42, plan: 'trial' }, SECRET, 3600);
    const [, p]   = token.split('.');
    const payload = JSON.parse(atob(p.replace(/-/g, '+').replace(/_/g, '/')));
    expect(payload.sub).toBe(42);
    expect(payload.plan).toBe('trial');
    expect(payload.exp).toBeGreaterThan(Math.floor(Date.now() / 1000));
  });
});

describe('verifyJWT', () => {
  it('returns payload for a valid token', async () => {
    const token   = await signJWT({ sub: 1, email: 'test@example.com' }, SECRET, 3600);
    const payload = await verifyJWT(token, SECRET);
    expect(payload).not.toBeNull();
    expect(payload!.sub).toBe(1);
    expect(payload!.email).toBe('test@example.com');
  });

  it('returns null for a tampered token', async () => {
    const token  = await signJWT({ sub: 1 }, SECRET, 3600);
    const parts  = token.split('.');
    parts[1]     = btoa(JSON.stringify({ sub: 999 })).replace(/=/g, '');
    const result = await verifyJWT(parts.join('.'), SECRET);
    expect(result).toBeNull();
  });

  it('returns null for a wrong secret', async () => {
    const token  = await signJWT({ sub: 1 }, SECRET, 3600);
    const result = await verifyJWT(token, 'wrong-secret');
    expect(result).toBeNull();
  });

  it('returns null for an expired token', async () => {
    const token  = await signJWT({ sub: 1 }, SECRET, -1);
    const result = await verifyJWT(token, SECRET);
    expect(result).toBeNull();
  });

  it('returns null for a malformed token', async () => {
    expect(await verifyJWT('not.a.jwt', SECRET)).toBeNull();
    expect(await verifyJWT('', SECRET)).toBeNull();
  });
});

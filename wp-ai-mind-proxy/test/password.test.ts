import { describe, it, expect } from 'vitest';
import { hashPassword, verifyPassword } from '../src/password';

describe('hashPassword', () => {
  it('returns a salt:hash hex string', async () => {
    const hash = await hashPassword('MyPassword123!');
    const parts = hash.split(':');
    expect(parts).toHaveLength(2);
    expect(parts[0]).toHaveLength(32); // 16 bytes hex = 32 chars
    expect(parts[1]).toHaveLength(64); // 32 bytes hex = 64 chars
  });

  it('produces different hashes for same password (random salt)', async () => {
    const h1 = await hashPassword('same');
    const h2 = await hashPassword('same');
    expect(h1).not.toBe(h2);
  });
});

describe('verifyPassword', () => {
  it('returns true for correct password', async () => {
    const hash  = await hashPassword('correct-horse-battery-staple');
    const valid = await verifyPassword('correct-horse-battery-staple', hash);
    expect(valid).toBe(true);
  });

  it('returns false for wrong password', async () => {
    const hash  = await hashPassword('correct');
    const valid = await verifyPassword('wrong', hash);
    expect(valid).toBe(false);
  });

  it('returns false for malformed stored hash', async () => {
    expect(await verifyPassword('test', 'not-a-hash')).toBe(false);
    expect(await verifyPassword('test', '')).toBe(false);
    expect(await verifyPassword('test', 'aaa:bbb:ccc')).toBe(false);
  });
});

import { describe, it, expect, vi } from 'vitest';
import { handleRegister, handleToken, handleRefresh } from '../src/auth';

function makeEnv(dbResult: Record<string, unknown> | null = null) {
  return {
    DB: {
      prepare: vi.fn().mockReturnValue({
        bind: vi.fn().mockReturnThis(),
        run:   vi.fn().mockResolvedValue({ success: true }),
        first: vi.fn().mockResolvedValue(dbResult),
      }),
    },
    JWT_SECRET: 'test-secret-long-enough-for-hmac-sha256',
  } as unknown as import('../src/types').Env;
}

function makeRequest(body: unknown): Request {
  return new Request('http://localhost', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

describe('handleRegister', () => {
  it('returns 400 if email missing', async () => {
    const res = await handleRegister(makeRequest({ password: 'pass123' }), makeEnv());
    expect(res.status).toBe(400);
  });

  it('returns 400 if password missing', async () => {
    const res = await handleRegister(makeRequest({ email: 'a@b.com' }), makeEnv());
    expect(res.status).toBe(400);
  });

  it('returns 400 if password too short', async () => {
    const res = await handleRegister(makeRequest({ email: 'a@b.com', password: 'short' }), makeEnv());
    expect(res.status).toBe(400);
  });

  it('returns 400 if email invalid', async () => {
    const res = await handleRegister(makeRequest({ email: 'notanemail', password: 'pass1234' }), makeEnv());
    expect(res.status).toBe(400);
  });

  it('returns 201 on success', async () => {
    const res = await handleRegister(makeRequest({ email: 'a@b.com', password: 'pass1234' }), makeEnv());
    expect(res.status).toBe(201);
  });
});

describe('handleToken', () => {
  it('returns 401 if user not found', async () => {
    const res = await handleToken(makeRequest({ email: 'no@user.com', password: 'x' }), makeEnv(null));
    expect(res.status).toBe(401);
  });
});

describe('handleRefresh', () => {
  it('returns 401 if refresh_token missing', async () => {
    const res = await handleRefresh(makeRequest({}), makeEnv());
    expect(res.status).toBe(401);
  });

  it('returns 401 for invalid refresh token', async () => {
    const res = await handleRefresh(makeRequest({ refresh_token: 'invalid.token.here' }), makeEnv());
    expect(res.status).toBe(401);
  });
});

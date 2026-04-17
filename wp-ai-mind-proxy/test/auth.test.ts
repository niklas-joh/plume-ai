import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleRegister, handleToken, handleRefresh } from '../src/auth';
import { signJWT } from '../src/jwt';

vi.mock('../src/password', () => ({
  hashPassword:   vi.fn().mockResolvedValue('fakehash'),
  verifyPassword: vi.fn().mockResolvedValue(true),
}));

const JWT_SECRET = 'test-secret-long-enough-for-hmac-sha256';

function makeEnv(dbResult: Record<string, unknown> | null = null) {
  return {
    DB: {
      prepare: vi.fn().mockReturnValue({
        bind: vi.fn().mockReturnThis(),
        run:   vi.fn().mockResolvedValue({ success: true }),
        first: vi.fn().mockResolvedValue(dbResult),
      }),
    },
    JWT_SECRET,
  } as unknown as import('../src/types').Env;
}

beforeEach(() => {
  vi.clearAllMocks();
});

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

  it('demotes expired trial user to free and updates DB', async () => {
    const user = {
      id: 1,
      email: 'trial@example.com',
      password_hash: 'fakehash',
      plan: 'trial',
      plan_expires: new Date(Date.now() - 86_400_000).toISOString(), // yesterday
    };
    const env = makeEnv(user);
    const res = await handleToken(makeRequest({ email: 'trial@example.com', password: 'pass1234' }), env);
    expect(res.status).toBe(200);
    const body = await res.json<{ plan: string }>();
    expect(body.plan).toBe('free');
    expect(env.DB.prepare).toHaveBeenCalledWith(
      expect.stringContaining('UPDATE users SET plan')
    );
  });

  it('preserves trial plan for non-expired trial user', async () => {
    const user = {
      id: 2,
      email: 'active@example.com',
      password_hash: 'fakehash',
      plan: 'trial',
      plan_expires: new Date(Date.now() + 86_400_000).toISOString(), // tomorrow
    };
    const env = makeEnv(user);
    const res = await handleToken(makeRequest({ email: 'active@example.com', password: 'pass1234' }), env);
    expect(res.status).toBe(200);
    const body = await res.json<{ plan: string }>();
    expect(body.plan).toBe('trial');
    expect(env.DB.prepare).not.toHaveBeenCalledWith(
      expect.stringContaining('UPDATE users SET plan')
    );
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

  it('returns 200 with new access_token for a valid refresh token', async () => {
    const refreshToken = await signJWT({ sub: 1, type: 'refresh' }, JWT_SECRET, 30 * 86_400);
    const user = { id: 1, email: 'a@b.com', plan: 'free', password_hash: 'fakehash', plan_expires: null };
    const env = makeEnv(user);
    const res = await handleRefresh(makeRequest({ refresh_token: refreshToken }), env);
    expect(res.status).toBe(200);
    const body = await res.json<{ access_token: string }>();
    expect(body.access_token).toBeTruthy();
  });
});

import { describe, it, expect, vi } from 'vitest';
import { handleEntitlement } from '../src/entitlement';
import type { Env, AuthUser } from '../src/types';

function makeEnv(usedTokens: number): Env {
  return {
    USAGE_KV: {
      get: vi.fn().mockResolvedValue(usedTokens > 0 ? String(usedTokens) : null),
    },
  } as unknown as Env;
}

const trialUser:      AuthUser = { sub: 1, email: 'test@example.com',  plan: 'trial',       iat: 0, exp: 9999999999 };
const freeUser:       AuthUser = { sub: 2, email: 'free@example.com',  plan: 'free',        iat: 0, exp: 9999999999 };
const proManagedUser: AuthUser = { sub: 4, email: 'pm@example.com',    plan: 'pro_managed', iat: 0, exp: 9999999999 };
const proUser:        AuthUser = { sub: 3, email: 'pro@example.com',   plan: 'pro',         iat: 0, exp: 9999999999 };

describe('handleEntitlement', () => {
  it('returns correct token limit for trial plan', async () => {
    const res  = await handleEntitlement(new Request('http://x'), makeEnv(5000), trialUser);
    const body = await res.json() as Record<string, unknown>;
    expect(body.tokens_limit).toBe(300_000);
    expect(body.tokens_used).toBe(5000);
    expect(body.plan).toBe('trial');
  });

  it('returns correct token limit for free plan', async () => {
    const res  = await handleEntitlement(new Request('http://x'), makeEnv(0), freeUser);
    const body = await res.json() as Record<string, unknown>;
    expect(body.tokens_limit).toBe(50_000);
    expect(body.tokens_used).toBe(0);
  });

  it('returns higher token limit for pro_managed plan', async () => {
    const res  = await handleEntitlement(new Request('http://x'), makeEnv(100_000), proManagedUser);
    const body = await res.json() as Record<string, unknown>;
    expect((body.tokens_limit as number)).toBeGreaterThan(300_000);
    expect((body.features as Record<string, boolean>).model_selection).toBe(true);
    expect((body.features as Record<string, boolean>).own_key).toBe(false);
    expect((body.allowed_models as string[]).length).toBeGreaterThan(1);
  });

  it('returns null token_limit for pro (BYOK) plan', async () => {
    const res  = await handleEntitlement(new Request('http://x'), makeEnv(0), proUser);
    const body = await res.json() as Record<string, unknown>;
    expect(body.tokens_limit).toBeNull();
    expect((body.features as Record<string, boolean>).own_key).toBe(true);
    expect((body.allowed_models as string[])).toHaveLength(0);
  });

  it('returns correct feature flags for free plan', async () => {
    const res      = await handleEntitlement(new Request('http://x'), makeEnv(0), freeUser);
    const body     = await res.json() as Record<string, unknown>;
    const features = body.features as Record<string, boolean>;
    expect(features.chat).toBe(true);
    expect(features.generator).toBe(false);
    expect(features.own_key).toBe(false);
    expect(features.model_selection).toBe(false);
    expect((body.allowed_models as string[])).toHaveLength(1);
  });

  it('returns correct feature flags for trial plan', async () => {
    const res      = await handleEntitlement(new Request('http://x'), makeEnv(0), trialUser);
    const body     = await res.json() as Record<string, unknown>;
    const features = body.features as Record<string, boolean>;
    expect(features.chat).toBe(true);
    expect(features.generator).toBe(true);
    expect(features.own_key).toBe(false);
    expect(features.model_selection).toBe(false);
  });
});

import { describe, it, expect } from 'vitest';
import { getTierConfig, TIER_CONFIG } from '../src/tier-config';

describe('TIER_CONFIG', () => {
  it('defines all four tiers', () => {
    expect(Object.keys(TIER_CONFIG)).toEqual(
      expect.arrayContaining(['free', 'trial', 'pro_managed', 'pro'])
    );
  });

  it('free tier has lower limit than trial', () => {
    const free  = TIER_CONFIG.free;
    const trial = TIER_CONFIG.trial;
    expect(free.tokens_per_month).toBeGreaterThan(0);
    expect(trial.tokens_per_month!).toBeGreaterThan(free.tokens_per_month!);
  });

  it('pro_managed has higher limit than trial', () => {
    const trial      = TIER_CONFIG.trial;
    const proManaged = TIER_CONFIG.pro_managed;
    expect(proManaged.tokens_per_month!).toBeGreaterThan(trial.tokens_per_month!);
  });

  it('pro (BYOK) has null token limit', () => {
    expect(TIER_CONFIG.pro.tokens_per_month).toBeNull();
  });

  it('free and trial only allow Haiku', () => {
    expect(TIER_CONFIG.free.allowed_models).toEqual(['claude-haiku-4-5']);
    expect(TIER_CONFIG.trial.allowed_models).toEqual(['claude-haiku-4-5']);
  });

  it('pro_managed allows multiple models', () => {
    expect(TIER_CONFIG.pro_managed.allowed_models.length).toBeGreaterThan(1);
    expect(TIER_CONFIG.pro_managed.allowed_models).toContain('claude-haiku-4-5');
  });

  it('pro (BYOK) has empty allowed_models (unrestricted)', () => {
    expect(TIER_CONFIG.pro.allowed_models).toEqual([]);
  });

  it('model_selection feature is false for free/trial', () => {
    expect(TIER_CONFIG.free.features.model_selection).toBe(false);
    expect(TIER_CONFIG.trial.features.model_selection).toBe(false);
  });

  it('model_selection feature is true for pro_managed and pro', () => {
    expect(TIER_CONFIG.pro_managed.features.model_selection).toBe(true);
    expect(TIER_CONFIG.pro.features.model_selection).toBe(true);
  });

  it('own_key feature is only true for pro (BYOK)', () => {
    expect(TIER_CONFIG.free.features.own_key).toBe(false);
    expect(TIER_CONFIG.trial.features.own_key).toBe(false);
    expect(TIER_CONFIG.pro_managed.features.own_key).toBe(false);
    expect(TIER_CONFIG.pro.features.own_key).toBe(true);
  });
});

describe('getTierConfig', () => {
  it('returns free config for unknown plans', () => {
    const config = getTierConfig('unknown_plan');
    expect(config).toEqual(TIER_CONFIG.free);
  });

  it('returns the correct config for each known plan', () => {
    for (const plan of ['free', 'trial', 'pro_managed', 'pro']) {
      expect(getTierConfig(plan)).toEqual(TIER_CONFIG[plan]);
    }
  });
});

export interface Env {
  DB:         D1Database;
  USAGE_KV:   KVNamespace;
  JWT_SECRET: string;
  ANTHROPIC_API_KEY: string;
  LEMONSQUEEZY_WEBHOOK_SECRET: string;
  ENVIRONMENT?: string;
}

/** All valid plan values. Must stay in sync with TIER_CONFIG keys in tier-config.ts. */
export type Plan = 'free' | 'trial' | 'pro_managed' | 'pro';

export interface User {
  id:            number;
  email:         string;
  password_hash: string;
  plan:          Plan;
  plan_expires:  string | null;
  created_at:    string;
  updated_at:    string;
}

export interface AuthUser {
  sub:   number;
  email: string;
  plan:  Plan;
  iat:   number;
  exp:   number;
  type?: string;
}

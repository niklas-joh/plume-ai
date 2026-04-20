// src/types.ts

export interface Env {
  USAGE_KV: KVNamespace;
  ANTHROPIC_API_KEY: string;
  LS_WEBHOOK_SECRET: string;
  LS_PRO_MONTHLY_VARIANT_ID: string;
  LS_PRO_ANNUAL_VARIANT_ID: string;
  // PROXY_SIGNATURE_SECRET intentionally removed — replaced by site token Bearer auth.
}

export type ProxyTier = 'free' | 'trial' | 'pro_managed';

export interface SiteRecord {
  site_url: string;
  tier: ProxyTier;
  created_at: number;
  ls_licence_key?: string;
}

export interface LicenceRecord {
  tier: ProxyTier;
  site_token: string;
  activated_at: number;
}

export interface ProxyRequest {
  user_id: number; // kept for logging only — rate limiting is per site token
  tier: ProxyTier;
  messages: MessageParam[];
  model?: string;
  max_tokens?: number;
  system?: string;
}

export interface MessageParam {
  role: 'user' | 'assistant';
  content: string;
}

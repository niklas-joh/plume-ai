// src/types.ts

export interface Env {
	USAGE_KV: KVNamespace;
	ANTHROPIC_API_KEY: string;
	LS_WEBHOOK_SECRET: string;
	LS_PRO_MONTHLY_VARIANT_ID: string;
	LS_PRO_ANNUAL_VARIANT_ID: string;
	// PROXY_SIGNATURE_SECRET intentionally removed — replaced by site token Bearer auth.
}

/** Tiers handled by this proxy (rate-limited and counted). */
export type ProxyTier = 'free' | 'trial' | 'pro_managed';

/** All possible tier values a site JWT may carry. */
export type SiteTier = ProxyTier | 'pro_byok';

export interface SiteRecord {
	site_url: string;
	tier: SiteTier;
	created_at: number;
	ls_licence_key?: string;
}

export interface LicenceRecord {
	tier: ProxyTier;
	site_token: string;
	activated_at: number;
}

export interface ProxyRequest {
	messages: MessageParam[];
	model?: string;
	max_tokens?: number;
	system?: string;
}

export interface MessageParam {
	role: 'user' | 'assistant';
	content: string;
}

// src/types.ts

export interface Env {
	USAGE_KV: KVNamespace;
	ANTHROPIC_API_KEY: string;
	OPENAI_API_KEY: string;
	GEMINI_API_KEY: string;
	LS_WEBHOOK_SECRET: string;
	LS_PRO_MONTHLY_VARIANT_ID: string;
	LS_PRO_ANNUAL_VARIANT_ID: string;
	// PROXY_SIGNATURE_SECRET intentionally removed — replaced by site token Bearer auth.
	/** Present only in non-production environments; gates /dev/* endpoints to return 404 in production. */
	DEV_ENDPOINTS_ENABLED?: string;
}

/** Tiers handled by this proxy (rate-limited and counted). */
export type ProxyTier = 'free' | 'trial' | 'pro_managed';

/** All possible tier values a site JWT may carry. */
export type SiteTier = ProxyTier | 'pro_byok';

export interface SiteRecord {
	site_url: string;
	tier: SiteTier;
	created_at: number;
	trial_started_at?: number;
	ls_licence_key?: string;
	/**
	 * Shared HMAC secret used to sign tier-update pushes from the Worker to the
	 * WordPress site. Optional so legacy KV records (issued before this field
	 * existed) still decode; the Worker treats absent secrets as "no push".
	 */
	tier_sync_secret?: string;
}

export interface LicenceRecord {
	tier: ProxyTier;
	site_token: string;
	activated_at: number;
}

export interface ToolParam {
	name: string;
	description: string;
	parameters: {
		type: 'object';
		properties: Record< string, unknown >;
		required?: string[];
	};
}

export interface NormalizedResponse {
	content: string;
	usage: { input_tokens: number; output_tokens: number };
	tool_call?: { id: string; name: string; arguments: Record< string, unknown > };
}

export interface ProxyRequest {
	messages: MessageParam[];
	model?: string;
	max_tokens?: number;
	system?: string;
	tools?: ToolParam[];
	provider?: 'claude' | 'openai' | 'gemini';
}

export interface MessageParam {
	role: 'user' | 'assistant';
	content: string;
}

/**
 * Model configuration stored in USAGE_KV under the key `config:models`.
 * Both fields are optional — absent fields fall back to the compiled defaults in index.ts.
 */
export interface ModelConfig {
	tier_models?: Record< string, Record< string, string[] > >;
	model_token_weight?: Record< string, number >;
}

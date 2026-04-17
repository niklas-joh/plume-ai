/**
 * Tier Configuration — SINGLE SOURCE OF TRUTH for all plan capabilities.
 *
 * To add a new tier, add an entry here.
 * To add a new feature flag, add it to TierFeatures and update each tier entry.
 * To change a token limit or allowed model, edit this file only.
 *
 * No other file should define per-plan constants. Import getTierConfig() instead.
 */

export interface TierFeatures {
  chat:            boolean;
  generator:       boolean;
  seo:             boolean;
  images:          boolean;
  own_key:         boolean;  // Pro BYOK: user supplies their own API key
  model_selection: boolean;  // User can choose from allowed_models list
}

export interface TierConfig {
  /** Monthly token budget. null = unlimited (Pro BYOK user's own cost). */
  tokens_per_month:       number | null;
  /**
   * Models this tier may request. The Worker validates the requested model against this list.
   * Empty array = unrestricted (Pro BYOK — any model the user's own key supports).
   */
  allowed_models:         string[];
  /** Per-request token cap enforced by the Worker. null = no cap. */
  max_tokens_per_request: number | null;
  features:               TierFeatures;
}

export const TIER_CONFIG: Record<string, TierConfig> = {
  free: {
    tokens_per_month:       50_000,
    allowed_models:         ['claude-haiku-4-5'],
    max_tokens_per_request: 1_000,
    features: {
      chat: true, generator: false, seo: false, images: false,
      own_key: false, model_selection: false,
    },
  },

  trial: {
    tokens_per_month:       300_000,
    allowed_models:         ['claude-haiku-4-5'],
    max_tokens_per_request: 1_000,
    features: {
      chat: true, generator: true, seo: true, images: true,
      own_key: false, model_selection: false,
    },
  },

  pro_managed: {
    tokens_per_month:       2_000_000,
    allowed_models:         ['claude-haiku-4-5', 'claude-sonnet-4-5', 'claude-opus-4-5'],
    max_tokens_per_request: 8_000,
    features: {
      chat: true, generator: true, seo: true, images: true,
      own_key: false, model_selection: true,
    },
  },

  /** Pro BYOK: user supplies their own provider API key and routes direct. */
  pro: {
    tokens_per_month:       null,   // Unlimited — user pays their own API cost.
    allowed_models:         [],     // Unrestricted — Worker not involved for BYOK routing.
    max_tokens_per_request: null,
    features: {
      chat: true, generator: true, seo: true, images: true,
      own_key: true, model_selection: true,
    },
  },
};

/**
 * Returns the TierConfig for a given plan value.
 * Falls back to `free` for any unknown plan to fail safe.
 */
export function getTierConfig(plan: string): TierConfig {
  return TIER_CONFIG[plan] ?? TIER_CONFIG.free;
}

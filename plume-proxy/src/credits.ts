// src/credits.ts

import { ProxyTier, SiteTier } from './types';

export const GENERATOR_CREDITS = 10;
export const SEO_CREDITS = 1;
export const IMAGE_CREDITS = 15;

/**
 * Monthly credit allowance per metered tier. The Worker is authoritative —
 * the plugin fetches this via GET /v1/config rather than re-declaring it in
 * PHP, so the two never drift (see issue #867).
 */
export const MONTHLY_CREDIT_LIMITS: Record< ProxyTier, number > = {
	free: 100,
	pro_managed: 500,
};

/**
 * Resolve the monthly credit limit for a site's tier.
 *
 * @since NEXT_VERSION
 * @param {SiteTier} tier Site's current tier, including the unmetered pro_byok tier.
 * @return {number | null} Monthly credit limit, or null for the unmetered pro_byok tier.
 */
export function getCreditLimit( tier: SiteTier ): number | null {
	return tier === 'pro_byok' ? null : MONTHLY_CREDIT_LIMITS[ tier ];
}

/**
 * Weighted tokens that map to a single chat credit. Deliberately mirrors the
 * free-tier MAX_TOKENS (2_000) in index.ts so the relationship between the
 * per-call token cap and one credit is explicit and tunable in one place.
 */
export const TOKENS_PER_CREDIT = 2000;


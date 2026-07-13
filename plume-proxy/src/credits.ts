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

/**
 * Raw (unrounded) chat cost in credit units — the building block chatCredits()
 * rounds. Used directly by handleChatProxy()'s per-call billing so that N calls
 * within one agentic-loop turn are billed on their real combined token volume
 * (via a delta-of-cumulative-ceiling against the running KV total) rather than
 * each call independently rounding up to its own whole credit, which would
 * overcharge multi-step turns relative to a single equivalent-sized call.
 *
 * @since NEXT_VERSION
 * @param {number} inputTokens  Actual input token count returned by the provider for this call.
 * @param {number} outputTokens Actual output token count returned by the provider for this call.
 * @param {number} weight       Resolved per-model weight (DEFAULT_MODEL_TOKEN_WEIGHT, possibly KV-overridden).
 * @return {number} Unrounded credit units for this call.
 */
export function rawChatCreditUnits(
	inputTokens: number,
	outputTokens: number,
	weight: number
): number {
	return ( ( inputTokens + outputTokens ) * weight ) / TOKENS_PER_CREDIT;
}

/**
 * TEST-ONLY helper. Computes the credit cost of a single chat completion,
 * scaled by total token volume and the model's relative weight (the existing
 * per-model weight table resolved by index.ts's getModelConfig(), not redefined
 * here).
 *
 * Formula: ceil((inputTokens + outputTokens) * weight / TOKENS_PER_CREDIT).
 *
 * Not used by handleChatProxy()'s per-call billing path — that bills on the raw
 * (unrounded) running total via rawChatCreditUnits() above. This thin ceil()
 * wrapper has no production caller and exists solely so tests can assert a
 * single call's rounded, in-isolation cost.
 *
 * @internal
 * @since NEXT_VERSION
 * @param {number} inputTokens  Actual input token count returned by the provider for this call.
 * @param {number} outputTokens Actual output token count returned by the provider for this call.
 * @param {number} weight       Resolved per-model weight (DEFAULT_MODEL_TOKEN_WEIGHT, possibly KV-overridden).
 * @return {number} Credits to charge, rounded up to the nearest whole credit.
 */
export function chatCredits(
	inputTokens: number,
	outputTokens: number,
	weight: number
): number {
	return Math.ceil( rawChatCreditUnits( inputTokens, outputTokens, weight ) );
}

// src/credits.ts

export const GENERATOR_CREDITS = 10;
export const SEO_CREDITS = 1;
export const IMAGE_CREDITS = 15;

/**
 * Weighted tokens that map to a single chat credit. Deliberately mirrors the
 * free-tier MAX_TOKENS (2_000) in index.ts so the relationship between the
 * per-call token cap and one credit is explicit and tunable in one place.
 */
export const TOKENS_PER_CREDIT = 2000;

/**
 * Computes the credit cost of a single chat completion, scaled by total token
 * volume and the model's relative weight (the existing per-model weight table
 * resolved by index.ts's getModelConfig(), not redefined here).
 *
 * Formula: ceil((inputTokens + outputTokens) * weight / TOKENS_PER_CREDIT).
 *
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
	return Math.ceil(
		( ( inputTokens + outputTokens ) * weight ) / TOKENS_PER_CREDIT
	);
}

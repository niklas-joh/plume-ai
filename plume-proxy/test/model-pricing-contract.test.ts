/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import {
	DEFAULT_TIER_MODELS,
	DEFAULT_MODEL_TOKEN_WEIGHT,
} from '../src/index';

// Billing uses `tokenWeights[ selectedModel ] ?? 1` as a silent fallback. Any
// tier model missing from DEFAULT_MODEL_TOKEN_WEIGHT would therefore be billed
// at weight 1 — over-billing cheap models (gpt-6-luna: 0.1 → 10x) and under-billing
// premium ones. This enforces the "add a model → price it" contract so the
// omission fails loudly here instead of silently in production billing.
describe( 'model pricing contract', () => {
	const tierModels = Object.entries( DEFAULT_TIER_MODELS ).flatMap(
		( [ provider, tiers ] ) =>
			Object.entries( tiers ).flatMap( ( [ tier, models ] ) =>
				models.map( ( model ) => ( { provider, tier, model } ) )
			)
	);

	it.each( tierModels )(
		'$provider/$tier model "$model" has a token weight',
		( { model } ) => {
			expect( DEFAULT_MODEL_TOKEN_WEIGHT ).toHaveProperty( model );
		}
	);

	// Weight = list input price / Haiku 4.5's $1/MTok: gpt-6-luna $0.10, gpt-6-sol $2.00.
	it( 'weights the OpenAI models by list input price', () => {
		expect( DEFAULT_MODEL_TOKEN_WEIGHT[ 'gpt-6-luna' ] ).toBe( 0.1 );
		expect( DEFAULT_MODEL_TOKEN_WEIGHT[ 'gpt-6-sol' ] ).toBe( 2 );
	} );
} );

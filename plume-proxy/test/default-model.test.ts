/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import {
	DEFAULT_MODEL_BY_TIER,
	DEFAULT_TIER_MODELS,
	getModelForTier,
} from '../src/index';

// Parity pin for the "Plugin default" label in the chat UI. The plugin mirrors
// these ids in includes/Providers/ManagedDefaults.php (tests/Unit/Providers/
// ManagedDefaultsTest.php pins the same literals). Changing a default here must
// fail this test, forcing the PHP mirror and its label to change with it.
describe( 'managed default model per provider and tier', () => {
	const expected = [
		[ 'claude', 'free', 'claude-haiku-4-5-20251001' ],
		[ 'claude', 'pro_managed', 'claude-sonnet-4-6' ],
		[ 'openai', 'free', 'gpt-4.1-nano' ],
		[ 'openai', 'pro_managed', 'gpt-4.1' ],
		[ 'gemini', 'free', 'gemini-3.1-flash-lite' ],
		[ 'gemini', 'pro_managed', 'gemini-3.5-flash' ],
	] as const;

	it.each( expected )(
		'%s/%s defaults to %s when no model is requested',
		( provider, tier, model ) => {
			expect(
				getModelForTier( provider, tier, DEFAULT_TIER_MODELS )
			).toBe( model );
		}
	);

	it( 'every default is in its tier allow-list', () => {
		for ( const [ provider, tiers ] of Object.entries(
			DEFAULT_MODEL_BY_TIER
		) ) {
			for ( const [ tier, model ] of Object.entries( tiers ) ) {
				expect(
					DEFAULT_TIER_MODELS[ provider as 'claude' ][
						tier as 'free'
					]
				).toContain( model );
			}
		}
	} );

	it( 'does not depend on allow-list order', () => {
		const reordered = {
			claude: {
				free: [ 'claude-haiku-4-5-20251001' ],
				pro_managed: [
					'claude-opus-4-6',
					'claude-haiku-4-5-20251001',
					'claude-sonnet-4-6',
				],
			},
		};
		expect(
			getModelForTier( 'claude', 'pro_managed', reordered )
		).toBe( 'claude-sonnet-4-6' );
	} );

	it( 'falls back to the first allowed model if a KV override drops the default', () => {
		const override = {
			claude: { pro_managed: [ 'claude-opus-4-6', 'claude-haiku-4-5-20251001' ] },
		};
		expect(
			getModelForTier( 'claude', 'pro_managed', override )
		).toBe( 'claude-opus-4-6' );
	} );

	it( 'still honours an explicitly requested allowed model', () => {
		expect(
			getModelForTier(
				'claude',
				'pro_managed',
				DEFAULT_TIER_MODELS,
				'claude-opus-4-6'
			)
		).toBe( 'claude-opus-4-6' );
	} );
} );

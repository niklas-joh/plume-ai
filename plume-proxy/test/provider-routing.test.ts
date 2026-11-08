/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import {
	DEFAULT_ENABLED_PROVIDERS,
	DEFAULT_MODEL_TOKEN_WEIGHT,
	DEFAULT_TIER_MODELS,
	FALLBACK_PROVIDER,
	isProviderServed,
	resolveChatRoute,
	sanitiseEnabledProviders,
} from '../src/index';
import type { Provider, ResolvedModelConfig } from '../src/types';

// Explicit Claude-only list rather than DEFAULT_ENABLED_PROVIDERS, so these
// tests keep passing when the compiled default changes.
function makeCfg(
	enabledProviders: Provider[] = [ 'claude' ],
	tierModels: ResolvedModelConfig[ 'tierModels' ] = DEFAULT_TIER_MODELS
): ResolvedModelConfig {
	return {
		tierModels,
		tokenWeights: DEFAULT_MODEL_TOKEN_WEIGHT,
		enabledProviders,
	};
}

const ALL: Provider[] = [ 'claude', 'openai', 'gemini' ];

const EMPTY_CLAUDE: ResolvedModelConfig[ 'tierModels' ] = {
	...DEFAULT_TIER_MODELS,
	claude: { free: [], pro_managed: [] },
};

function catchError( fn: () => unknown ): unknown {
	try {
		fn();
	} catch ( error ) {
		return error;
	}
	return undefined;
}

describe( 'sanitiseEnabledProviders', () => {
	it.each( [
		[ 'undefined', undefined ],
		[ 'a string', 'openai' ],
		[ 'an object', { openai: true } ],
		[ 'null', null ],
	] )( 'falls back to the compiled default for %s', ( _label, value ) => {
		expect( sanitiseEnabledProviders( value ) ).toEqual(
			DEFAULT_ENABLED_PROVIDERS
		);
	} );

	it( 'always keeps Claude, even for an empty list', () => {
		expect( sanitiseEnabledProviders( [] ) ).toEqual( [ 'claude' ] );
	} );

	it( 'adds Claude to a list that omits it', () => {
		expect( sanitiseEnabledProviders( [ 'gemini' ] ) ).toEqual( [
			'claude',
			'gemini',
		] );
	} );

	it( 'drops unknown slugs and duplicates', () => {
		expect(
			sanitiseEnabledProviders( [ 'openai', 'bogus', 'openai', 7 ] )
		).toEqual( [ 'claude', 'openai' ] );
	} );

	it( 'returns providers in canonical order', () => {
		expect(
			sanitiseEnabledProviders( [ 'gemini', 'openai', 'claude' ] )
		).toEqual( ALL );
	} );

	it( 'returns a copy, so callers cannot mutate the compiled default', () => {
		const before = [ ...DEFAULT_ENABLED_PROVIDERS ];
		const result = sanitiseEnabledProviders( undefined );
		result.push( 'openai' );
		expect( DEFAULT_ENABLED_PROVIDERS ).toEqual( before );
	} );
} );

describe( 'isProviderServed', () => {
	it( 'requires the provider to be enabled', () => {
		expect( isProviderServed( 'openai', 'free', makeCfg() ) ).toBe( false );
		expect( isProviderServed( 'openai', 'free', makeCfg( ALL ) ) ).toBe(
			true
		);
	} );

	it( 'requires a non-empty allow-list for the tier', () => {
		const cfg = makeCfg( ALL, {
			...DEFAULT_TIER_MODELS,
			openai: { free: [], pro_managed: [ 'gpt-4.1' ] },
		} );
		expect( isProviderServed( 'openai', 'free', cfg ) ).toBe( false );
		expect( isProviderServed( 'openai', 'pro_managed', cfg ) ).toBe( true );
	} );

	it( 'treats a non-array allow-list as not served', () => {
		const cfg = makeCfg( ALL, {
			...DEFAULT_TIER_MODELS,
			openai: { free: 'nope' as unknown as string[] },
		} );
		expect( isProviderServed( 'openai', 'free', cfg ) ).toBe( false );
	} );
} );

describe( 'resolveChatRoute (native)', () => {
	it( 'serves Claude natively with the tier default', () => {
		expect( resolveChatRoute( 'claude', 'free', makeCfg() ) ).toMatchObject( {
			requestedProvider: 'claude',
			provider: 'claude',
			model: 'claude-haiku-4-5-20251001',
		} );
	} );

	it( 'keeps the tier rule for an explicitly requested Claude model', () => {
		expect(
			resolveChatRoute( 'claude', 'free', makeCfg(), 'claude-opus-4-6' )
				.model
		).toBe( 'claude-haiku-4-5-20251001' );
		expect(
			resolveChatRoute(
				'claude',
				'pro_managed',
				makeCfg(),
				'claude-opus-4-6'
			).model
		).toBe( 'claude-opus-4-6' );
	} );

	it( 'serves an enabled provider natively', () => {
		const route = resolveChatRoute(
			'gemini',
			'free',
			makeCfg( [ 'claude', 'gemini' ] )
		);
		expect( route ).toMatchObject( {
			requestedProvider: 'gemini',
			provider: 'gemini',
			model: 'gemini-3.1-flash-lite',
		} );
	} );

	it( 'throws the tagged 400 when Claude itself has an empty allow-list', () => {
		const cfg = makeCfg( [ 'claude' ], EMPTY_CLAUDE );
		expect( catchError( () => resolveChatRoute( 'claude', 'free', cfg ) ) ).toMatchObject( {
			status: 400,
			isValidationError: true,
		} );
	} );
} );

// TEMPORARY(#1020): substitution cases. On removal: delete this block and
// assert a tagged 400 provider_unavailable for a disabled provider instead.
describe( 'TEMPORARY(#1020) substitution', () => {
	it( 'substitutes a disabled provider on free with Claude Haiku', () => {
		expect( resolveChatRoute( 'openai', 'free', makeCfg() ) ).toEqual( {
			requestedProvider: 'openai',
			provider: 'claude',
			model: 'claude-haiku-4-5-20251001',
			substituted: true,
		} );
	} );

	it( 'substitutes a disabled provider on pro_managed with Claude Sonnet (the tier default)', () => {
		expect(
			resolveChatRoute( 'gemini', 'pro_managed', makeCfg() )
		).toEqual( {
			requestedProvider: 'gemini',
			provider: 'claude',
			model: 'claude-sonnet-4-6',
			substituted: true,
		} );
	} );

	it( 'ignores a non-Claude requested model (OpenAIProvider always sends gpt-4.1)', () => {
		expect(
			resolveChatRoute( 'openai', 'free', makeCfg(), 'gpt-4.1' ).model
		).toBe( 'claude-haiku-4-5-20251001' );
		expect(
			resolveChatRoute( 'openai', 'pro_managed', makeCfg(), 'gpt-4.1' )
				.model
		).toBe( 'claude-sonnet-4-6' );
	} );

	it( 'throws the tagged 400 for a disabled provider when Claude has no models to substitute', () => {
		const cfg = makeCfg( [ 'claude' ], EMPTY_CLAUDE );
		expect( catchError( () => resolveChatRoute( 'openai', 'free', cfg ) ) ).toMatchObject( {
			status: 400,
			isValidationError: true,
		} );
	} );

	it( 'substitutes an enabled provider whose allow-list is empty', () => {
		const cfg = makeCfg( ALL, {
			...DEFAULT_TIER_MODELS,
			openai: { free: [], pro_managed: [] },
		} );
		const route = resolveChatRoute( 'openai', 'free', cfg );
		expect( route.provider ).toBe( 'claude' );
		expect( route.substituted ).toBe( true );
	} );
} );

// Parity pin: ManagedCatalogue::FALLBACK_ENABLED in the plugin carries the same
// literal (tests/Unit/Providers/ManagedCatalogueTest.php).
describe( 'enabled-provider defaults', () => {
	// TEMPORARY(#1020): Claude-only default. On removal: pin all providers here
	// and in the PHP parity test.
	it( 'DEFAULT_ENABLED_PROVIDERS is Claude only', () => {
		expect( DEFAULT_ENABLED_PROVIDERS ).toEqual( [ 'claude' ] );
	} );

	it( 'FALLBACK_PROVIDER is Claude', () => {
		expect( FALLBACK_PROVIDER ).toBe( 'claude' );
	} );
} );

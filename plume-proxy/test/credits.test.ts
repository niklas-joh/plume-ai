/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import {
	CACHE_PRICE_MULTIPLIERS,
	chatCredits,
	rawChatCreditUnits,
	getCreditLimit,
	GENERATOR_CREDITS,
	SEO_CREDITS,
	IMAGE_CREDITS,
	MONTHLY_CREDIT_LIMITS,
} from '../src/credits';

describe( 'chatCredits', () => {
	it( 'returns 1 credit for a minimal non-zero call at weight 1', () => {
		expect( chatCredits( 1, 0, 1 ) ).toBe( 1 );
	} );

	it( 'returns the exact integer result when (input+output)*weight is a multiple of 2000', () => {
		// weight=1: (1000+1000)*1 = 2000 → 1
		expect( chatCredits( 1000, 1000, 1 ) ).toBe( 1 );
		// weight=5: (200+200)*5 = 2000 → 1
		expect( chatCredits( 200, 200, 5 ) ).toBe( 1 );
	} );

	it( 'rounds up when (input+output)*weight is not a multiple of 2000', () => {
		// raw=100, weight=3 → 300 → ceil(300/2000) = 1
		expect( chatCredits( 50, 50, 3 ) ).toBe( 1 );
	} );

	it( 'rounds up by exactly 1 at a boundary just over an integer multiple', () => {
		// raw=2001, weight=1 → ceil(2001/2000) = 2
		expect( chatCredits( 2001, 0, 1 ) ).toBe( 2 );
	} );

	it( 'weight=1 scales linearly with token count alone', () => {
		expect( chatCredits( 4000, 0, 1 ) ).toBe( 2 );
		expect( chatCredits( 8000, 0, 1 ) ).toBe( 4 );
	} );

	it( 'handles zero tokens — expect 0 credits, not a forced minimum of 1', () => {
		expect( chatCredits( 0, 0, 1 ) ).toBe( 0 );
	} );

	it( 'handles a heavy model weight (e.g. weight=10) across a realistic token count', () => {
		// raw=15000, weight=10 → ceil(150000/2000) = 75
		expect( chatCredits( 10000, 5000, 10 ) ).toBe( 75 );
	} );

	it( 'handles a fractional weight (gpt-4.1-nano, weight=0.1)', () => {
		// (15000+5000)*0.1 = 2000 → exactly 1 credit, no float drift past the boundary.
		expect( chatCredits( 15000, 5000, 0.1 ) ).toBe( 1 );
		// (3000+1000)*0.1/2000 = 0.2 → rounds up to 1.
		expect( chatCredits( 3000, 1000, 0.1 ) ).toBe( 1 );
		// (300000+100000)*0.1/2000 = 20.
		expect( chatCredits( 300000, 100000, 0.1 ) ).toBe( 20 );
	} );

	it( 'is a pure function — identical inputs always produce identical output', () => {
		const a = chatCredits( 1234, 567, 3 );
		const b = chatCredits( 1234, 567, 3 );
		expect( a ).toBe( b );
	} );

	it( 'GENERATOR_CREDITS equals 10', () => {
		expect( GENERATOR_CREDITS ).toBe( 10 );
	} );

	it( 'SEO_CREDITS equals 1', () => {
		expect( SEO_CREDITS ).toBe( 1 );
	} );

	it( 'IMAGE_CREDITS equals 15', () => {
		expect( IMAGE_CREDITS ).toBe( 15 );
	} );

	it( 'GENERATOR_CREDITS, SEO_CREDITS, and IMAGE_CREDITS are all positive integers', () => {
		for ( const value of [ GENERATOR_CREDITS, SEO_CREDITS, IMAGE_CREDITS ] ) {
			expect( Number.isInteger( value ) ).toBe( true );
			expect( value ).toBeGreaterThan( 0 );
		}
	} );
} );

describe( 'getCreditLimit', () => {
	it( 'returns the free tier limit', () => {
		expect( getCreditLimit( 'free' ) ).toBe( MONTHLY_CREDIT_LIMITS.free );
	} );

	it( 'returns the pro_managed tier limit', () => {
		expect( getCreditLimit( 'pro_managed' ) ).toBe(
			MONTHLY_CREDIT_LIMITS.pro_managed
		);
	} );

	it( 'returns null for the unmetered pro_byok tier', () => {
		expect( getCreditLimit( 'pro_byok' ) ).toBeNull();
	} );
} );

describe( 'rawChatCreditUnits — prompt-cache billing (#946, #859)', () => {
	it( 'is unchanged when no cache usage is passed', () => {
		expect( rawChatCreditUnits( 1500, 500, 3 ) ).toBe( ( 2000 * 3 ) / 2000 );
		expect(
			rawChatCreditUnits( 1500, 500, 3, { provider: 'claude' } )
		).toBe( rawChatCreditUnits( 1500, 500, 3 ) );
		expect(
			rawChatCreditUnits( 1500, 500, 3, {
				provider: 'openai',
				cacheWriteTokens: 0,
				cacheReadTokens: 0,
			} )
		).toBe( rawChatCreditUnits( 1500, 500, 3 ) );
	} );

	it( 'uses the agreed per-provider multipliers', () => {
		expect( CACHE_PRICE_MULTIPLIERS ).toEqual( {
			claude: { write: 1.25, read: 0.1 },
			openai: { write: 0, read: 0.25 },
			gemini: { write: 0, read: 0.1 },
		} );
	} );

	it( 'bills Claude cache writes at 1.25× input', () => {
		// (1000 + 1.25 × 1000) × 1 / 2000 = 1.125
		expect(
			rawChatCreditUnits( 1000, 0, 1, {
				provider: 'claude',
				cacheWriteTokens: 1000,
			} )
		).toBe( 1.125 );
		expect(
			rawChatCreditUnits( 1000, 0, 1, {
				provider: 'claude',
				cacheWriteTokens: 1000,
			} )
		).toBeGreaterThan( rawChatCreditUnits( 1000, 0, 1 ) );
	} );

	it( 'bills Claude cache reads at 0.1× input', () => {
		// 0.1 × 20000 × 1 / 2000 = 1
		expect(
			rawChatCreditUnits( 0, 0, 1, {
				provider: 'claude',
				cacheReadTokens: 20000,
			} )
		).toBeCloseTo( 1, 10 );
	} );

	it( 'bills OpenAI cache reads at 0.25× input and ignores writes', () => {
		expect(
			rawChatCreditUnits( 0, 0, 2, {
				provider: 'openai',
				cacheReadTokens: 4000,
				cacheWriteTokens: 9999,
			} )
		).toBe( 1 ); // 0.25 × 4000 × 2 / 2000
	} );

	it( 'bills Gemini cache reads at 0.1× input and ignores writes', () => {
		expect(
			rawChatCreditUnits( 0, 0, 2, {
				provider: 'gemini',
				cacheReadTokens: 10000,
				cacheWriteTokens: 9999,
			} )
		).toBeCloseTo( 1, 10 ); // 0.1 × 10000 × 2 / 2000
	} );

	it( 'chatCredits() rounds the cache-inclusive raw cost', () => {
		expect(
			chatCredits( 1000, 0, 1, { provider: 'claude', cacheWriteTokens: 1000 } )
		).toBe( 2 );
	} );
} );

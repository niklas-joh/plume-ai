/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import {
	chatCredits,
	GENERATOR_CREDITS,
	SEO_CREDITS,
	IMAGE_CREDITS,
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

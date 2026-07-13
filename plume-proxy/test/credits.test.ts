/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import {
	getCreditLimit,
	GENERATOR_CREDITS,
	SEO_CREDITS,
	IMAGE_CREDITS,
	MONTHLY_CREDIT_LIMITS,
} from '../src/credits';

describe( 'credit constants', () => {
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

/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect, vi, afterEach } from 'vitest';
import {
	BLOB,
	DOUBLE,
	buildChatDataPoint,
	buildRegisterDataPoint,
	classifyChatOutcome,
	errorCodeFromThrown,
	newChatMetric,
	recordMetric,
	siteKeyFromUrl,
} from '../src/metrics';
import { makeEnv } from './helpers/kv-mock';

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'siteKeyFromUrl', () => {
	it.each( [
		[ 'https://www.Example.com', 'example.com' ],
		[ 'https://example.com:8443/', 'example.com' ],
		[ 'https://example.com/blog/', 'example.com/blog' ],
		[ 'https://example.com/blog?x=1#frag', 'example.com/blog' ],
		[ 'https://user:pass@example.com/', 'example.com' ],
		[ 'not a url', '' ],
		[ 'ftp://example.com', '' ],
		[ '', '' ],
		[ undefined, '' ],
	] )( '%s -> %s', ( input, expected ) => {
		expect( siteKeyFromUrl( input as string | undefined ) ).toBe( expected );
	} );

	it( 'truncates to 96 bytes on a character boundary', () => {
		const key = siteKeyFromUrl( 'https://example.com/' + 'é'.repeat( 200 ) );
		const bytes = new TextEncoder().encode( key );
		expect( bytes.length ).toBeLessThanOrEqual( 96 );
		expect( new TextDecoder( 'utf-8', { fatal: true } ).decode( bytes ) ).toBe( key );
	} );

	it( 'normalises IDN hosts to punycode', () => {
		expect( siteKeyFromUrl( 'https://bücher.example/' ) ).toBe( 'xn--bcher-kva.example' );
	} );
} );

describe( 'classifyChatOutcome', () => {
	it.each( [
		[ 200, '', 'ok' ],
		[ 401, '', 'unauthorised' ],
		[ 429, '', 'rate_limited' ],
		[ 413, '', 'too_large' ],
		[ 403, '', 'byok_blocked' ],
		[ 400, 'validation', 'bad_request' ],
		[ 500, 'upstream_502', 'upstream_error' ],
		[ 500, 'internal', 'internal_error' ],
	] )( '%i / %s -> %s', ( status, code, expected ) => {
		expect( classifyChatOutcome( status, code ) ).toBe( expected );
	} );
} );

describe( 'errorCodeFromThrown', () => {
	it( 'never leaks message or body', () => {
		const err = Object.assign( new Error( 'SECRET_IN_MESSAGE' ), {
			status: 502,
			body: 'SECRET_IN_BODY',
		} );
		const code = errorCodeFromThrown( err );
		expect( code ).toBe( 'upstream_502' );
		expect( code ).not.toContain( 'SECRET' );
		expect(
			errorCodeFromThrown( new Error( 'SECRET_IN_MESSAGE' ) )
		).toBe( 'internal' );
		expect(
			errorCodeFromThrown( { isValidationError: true, status: 400 } )
		).toBe( 'validation' );
	} );
} );

describe( 'data point builders', () => {
	it( 'lays out blobs and doubles at the locked positions', () => {
		const dp = buildChatDataPoint( {
			...newChatMetric(),
			siteKey: 'example.com',
			feature: 'chat',
			provider: 'claude',
			requestedProvider: 'openai',
			model: 'm',
			tier: 'free',
			errorCode: '',
			upstreamMs: 7,
			creditsCharged: 2,
			creditsUsedAfter: 9,
			creditLimit: 100,
			inputTokens: 11,
			outputTokens: 12,
			cacheReadTokens: 13,
			cacheWriteTokens: 14,
			toolCalls: 3,
			status: 200,
			latencyMs: 55,
		} );
		expect( dp.indexes ).toEqual( [ 'example.com' ] );
		expect( dp.blobs?.[ BLOB.EVENT ] ).toBe( 'chat' );
		expect( dp.blobs?.[ BLOB.FEATURE ] ).toBe( 'chat' );
		expect( dp.blobs?.[ BLOB.PROVIDER ] ).toBe( 'claude' );
		expect( dp.blobs?.[ BLOB.REQUESTED_PROVIDER ] ).toBe( 'openai' );
		expect( BLOB.REQUESTED_PROVIDER ).toBe( 8 );
		expect( dp.blobs?.[ BLOB.MODEL ] ).toBe( 'm' );
		expect( dp.blobs?.[ BLOB.TIER ] ).toBe( 'free' );
		expect( dp.blobs?.[ BLOB.OUTCOME ] ).toBe( 'ok' );
		expect( dp.doubles ).toEqual( [ 200, 55, 7, 2, 9, 100, 11, 12, 13, 14, 3, 0 ] );
		expect( DOUBLE.IS_NEW ).toBe( 11 );
	} );

	it( 'coerces NaN/undefined numbers to 0', () => {
		const dp = buildChatDataPoint( {
			...newChatMetric(),
			inputTokens: NaN,
			outputTokens: undefined as unknown as number,
			status: 200,
			latencyMs: Infinity,
		} );
		expect( dp.doubles?.[ DOUBLE.INPUT_TOKENS ] ).toBe( 0 );
		expect( dp.doubles?.[ DOUBLE.OUTPUT_TOKENS ] ).toBe( 0 );
		expect( dp.doubles?.[ DOUBLE.LATENCY_MS ] ).toBe( 0 );
	} );

	it( 'builds a register point with site_ref and is_new', () => {
		const dp = buildRegisterDataPoint( {
			startedAt: Date.now(),
			outcome: 'registered',
			status: 201,
			siteKey: 'example.com',
			siteRef: 'abc123def456',
			isNew: true,
		} );
		expect( dp.blobs?.[ BLOB.EVENT ] ).toBe( 'register' );
		expect( dp.blobs?.[ BLOB.OUTCOME ] ).toBe( 'registered' );
		expect( dp.blobs?.[ BLOB.SITE_REF ] ).toBe( 'abc123def456' );
		expect( dp.doubles?.[ DOUBLE.IS_NEW ] ).toBe( 1 );
		expect( dp.doubles?.[ DOUBLE.HTTP_STATUS ] ).toBe( 201 );
	} );
} );

describe( 'recordMetric', () => {
	it( 'is a no-op without the binding', () => {
		const build = vi.fn();
		expect( () => recordMetric( makeEnv(), build ) ).not.toThrow();
		expect( build ).not.toHaveBeenCalled();
	} );

	it( 'swallows a throwing writeDataPoint and logs only a fixed message', () => {
		const warn = vi.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const env = makeEnv( {
			PLUME_METRICS: {
				writeDataPoint: () => {
					throw new Error( 'SECRET_IN_THROW' );
				},
			} as unknown as AnalyticsEngineDataset,
		} );
		expect( () =>
			recordMetric( env, () => ( { indexes: [ 'x' ] } ) )
		).not.toThrow();
		expect( warn ).toHaveBeenCalledWith( 'metrics: write failed' );
		expect( JSON.stringify( warn.mock.calls ) ).not.toContain( 'SECRET' );
	} );
} );

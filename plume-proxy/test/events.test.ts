/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect, vi, afterEach } from 'vitest';
import worker from '../src/index';
import schema from '../src/event-schema.json';
import {
	EVENT_BLOB,
	EVENT_DOUBLE,
	FIELD_SLOTS,
	buildEventDataPoint,
	handleEvents,
	normaliseInstallId,
	readCappedBody,
	validateBatch,
} from '../src/events';
import { makeEnv } from './helpers/kv-mock';
import type { Env } from '../src/types';

const INSTALL_ID = '3f2b6c1e-8d4a-4b7f-9a1c-2e5d7f9b0c13';
const NOW = 1_800_000_000; // Fixed "now" for validateBatch().
const HOUR = 3600;

afterEach( () => {
	vi.restoreAllMocks();
	vi.useRealTimers();
} );

function batch( events: unknown[], overrides: Record< string, unknown > = {} ) {
	return {
		schema: 1,
		install_id: INSTALL_ID,
		plugin_version: '2.3.0',
		events,
		...overrides,
	};
}

function eventsEnv( overrides: Partial< Env > = {} ) {
	const writes: AnalyticsEngineDataPoint[] = [];
	const env = makeEnv( {
		PLUME_EVENTS: {
			writeDataPoint: ( dp?: AnalyticsEngineDataPoint ) => {
				if ( dp ) {
					writes.push( dp );
				}
			},
		} as AnalyticsEngineDataset,
		...overrides,
	} );
	return { env, writes };
}

function post( body: unknown, headers: Record< string, string > = {} ): Request {
	return new Request( 'https://proxy.test/v1/events', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', ...headers },
		body: typeof body === 'string' ? body : JSON.stringify( body ),
	} );
}

function nowTs(): number {
	const now = Math.floor( Date.now() / 1000 );
	return now - ( now % HOUR );
}

// ── Slot lock (append-only schema) ────────────────────────────────────────────

describe( 'slot layout', () => {
	it( 'locks blob positions', () => {
		expect( EVENT_BLOB ).toEqual( {
			EVENT: 0,
			PLUGIN_VERSION: 1,
			FEATURE: 2,
			PROVIDER: 3,
			MODEL: 4,
			ROUTE: 5,
			OUTCOME: 6,
			ERROR_CODE: 7,
			FIRST_ERROR_CODE: 8,
			TURN_ID: 9,
			ORIGIN: 10,
			DETAIL: 11,
			PLAN: 12,
			TIER: 13,
			PROVIDERS: 14,
			WP_VERSION: 15,
			PHP_VERSION: 16,
			LOCALE: 17,
		} );
	} );

	it( 'locks double positions', () => {
		expect( EVENT_DOUBLE ).toEqual( {
			CLIENT_TS: 0,
			SCHEMA_VERSION: 1,
			HTTP_STATUS: 2,
			LATENCY_MS: 3,
			INPUT_TOKENS: 4,
			OUTPUT_TOKENS: 5,
			IMAGE_COUNT: 6,
			ATTEMPTS: 7,
			DAYS_SINCE_ACTIVATION: 8,
			DAYS_SINCE_LAST_AI: 9,
		} );
	} );

	it( 'locks the field-to-slot map', () => {
		const flat = Object.fromEntries(
			Object.entries( FIELD_SLOTS ).map( ( [ ev, fields ] ) => [
				ev,
				Object.fromEntries(
					Object.entries( fields ).map( ( [ f, s ] ) => [
						f,
						`${ s.kind }${ s.index + 1 }`,
					] )
				),
			] )
		);
		expect( flat ).toEqual( {
			consent_given: { source: 'blob11' },
			onboarding_step: { step: 'blob11', action: 'blob12' },
			onboarding_done: { route: 'blob6' },
			ai_request: {
				feature: 'blob3',
				provider: 'blob4',
				model: 'blob5',
				route: 'blob6',
				outcome: 'blob7',
				error_code: 'blob8',
				first_error_code: 'blob9',
				http_status: 'double3',
				latency_ms: 'double4',
				input_tokens: 'double5',
				output_tokens: 'double6',
				image_count: 'double7',
				attempts: 'double8',
				turn_id: 'blob10',
			},
			upgrade_click: {
				placement: 'blob11',
				screen: 'blob12',
				plan: 'blob13',
			},
			heartbeat: {
				tier: 'blob14',
				providers: 'blob15',
				wp_version: 'blob16',
				php_version: 'blob17',
				locale: 'blob18',
				days_since_activation: 'double9',
				days_since_last_ai: 'double10',
			},
			deactivated: {},
		} );
	} );

	it( 'the JSON schema and FIELD_SLOTS cover the same events and fields', () => {
		const fromSchema = Object.fromEntries(
			Object.entries( schema.events ).map( ( [ ev, fields ] ) => [
				ev,
				Object.keys( fields ).sort(),
			] )
		);
		const fromSlots = Object.fromEntries(
			Object.entries( FIELD_SLOTS ).map( ( [ ev, fields ] ) => [
				ev,
				Object.keys( fields ).sort(),
			] )
		);
		expect( fromSlots ).toEqual( fromSchema );
	} );

	it( 'builds a full ai_request datapoint', () => {
		const dp = buildEventDataPoint( INSTALL_ID, '2.3.0', {
			name: 'ai_request',
			ts: 1_799_996_400,
			fields: {
				feature: 'chat',
				provider: 'openai',
				model: 'gpt-4.1',
				route: 'byok',
				outcome: 'error',
				error_code: 'rate_limited',
				first_error_code: 'rate_limited',
				http_status: 429,
				latency_ms: 1200,
				input_tokens: 0,
				output_tokens: 0,
				image_count: 0,
				attempts: 4,
				turn_id: '11111111-2222-4333-8444-555555555555',
			},
		} );
		expect( dp ).toEqual( {
			indexes: [ INSTALL_ID ],
			blobs: [
				'ai_request',
				'2.3.0',
				'chat',
				'openai',
				'gpt-4.1',
				'byok',
				'error',
				'rate_limited',
				'rate_limited',
				'11111111-2222-4333-8444-555555555555',
				'',
				'',
				'',
				'',
				'',
				'',
				'',
				'',
			],
			doubles: [ 1_799_996_400, 1, 429, 1200, 0, 0, 0, 4, 0, 0 ],
		} );
	} );

	it( 'builds a full heartbeat datapoint, with defaults for missing ints', () => {
		const dp = buildEventDataPoint( INSTALL_ID, '2.3.0', {
			name: 'heartbeat',
			ts: 1_799_996_400,
			fields: {
				tier: 'free',
				providers: 'claude,ollama',
				wp_version: '6.7',
				php_version: '8.3',
				locale: 'en_GB',
				days_since_activation: 12,
			},
		} );
		expect( dp.blobs ).toEqual( [
			'heartbeat',
			'2.3.0',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'free',
			'claude,ollama',
			'6.7',
			'8.3',
			'en_GB',
		] );
		expect( dp.doubles ).toEqual( [ 1_799_996_400, 1, 0, 0, 0, 0, 0, 0, 12, -1 ] );
	} );

	it.each( [
		[ 'consent_given', { source: 'settings' }, { 10: 'settings' } ],
		[ 'onboarding_step', { step: 'providers', action: 'viewed' }, { 10: 'providers', 11: 'viewed' } ],
		[ 'onboarding_done', { route: 'managed' }, { 5: 'managed' } ],
		[ 'upgrade_click', { placement: 'onboarding', screen: 'dashboard', plan: 'pricing' }, { 10: 'onboarding', 11: 'dashboard', 12: 'pricing' } ],
		[ 'deactivated', {}, {} ],
	] )( 'builds a %s datapoint', ( name, fields, expectedBlobs ) => {
		const dp = buildEventDataPoint( INSTALL_ID, '2.3.0', {
			name,
			ts: 3600,
			fields,
		} );
		const blobs = new Array( 18 ).fill( '' );
		blobs[ 0 ] = name;
		blobs[ 1 ] = '2.3.0';
		for ( const [ i, v ] of Object.entries( expectedBlobs ) ) {
			blobs[ Number( i ) ] = v;
		}
		expect( dp.blobs ).toEqual( blobs );
		expect( dp.doubles ).toEqual( [ 3600, 1, 0, 0, 0, 0, 0, 0, 0, 0 ] );
		expect( dp.indexes ).toEqual( [ INSTALL_ID ] );
	} );
} );

// ── validateBatch ─────────────────────────────────────────────────────────────

describe( 'validateBatch', () => {
	const ok = { name: 'deactivated', ts: NOW - HOUR };

	it.each( [
		[ 'not an object', 'x' ],
		[ 'wrong schema', batch( [ ok ], { schema: 2 } ) ],
		[ 'invalid install id', batch( [ ok ], { install_id: 'abc' } ) ],
		[ 'non-v4 install id', batch( [ ok ], { install_id: '3f2b6c1e-8d4a-1b7f-9a1c-2e5d7f9b0c13' } ) ],
		[ 'invalid plugin version', batch( [ ok ], { plugin_version: 'latest' } ) ],
		[ 'zero events', batch( [] ) ],
		[ '51 events', batch( new Array( 51 ).fill( ok ) ) ],
		[ 'all events unknown', batch( [ { name: 'page_view', ts: NOW } ] ) ],
	] )( 'rejects %s', ( _label, raw ) => {
		expect( validateBatch( raw, NOW ) ).toEqual( {
			ok: false,
			status: 400,
			error: 'invalid_batch',
		} );
	} );

	it( 'drops and counts unknown events and drops unknown keys', () => {
		const result = validateBatch(
			batch( [
				{ name: 'page_view', ts: NOW },
				{ name: 'onboarding_done', ts: NOW, route: 'managed', email: 'a@example.com' },
			] ),
			NOW
		);
		expect( result.ok ).toBe( true );
		if ( ! result.ok ) {
			return;
		}
		expect( result.dropped ).toBe( 1 );
		expect( result.events ).toHaveLength( 1 );
		expect( result.events[ 0 ].fields ).toEqual( { route: 'managed' } );
	} );

	it( 'turns invalid enums into empty strings and clamps ints', () => {
		const result = validateBatch(
			batch( [
				{
					name: 'ai_request',
					ts: NOW,
					feature: 'hacking',
					http_status: 9999,
					latency_ms: -3,
					attempts: 'many',
					image_count: 1.5,
				},
			] ),
			NOW
		);
		if ( ! result.ok ) {
			throw new Error( 'expected ok' );
		}
		expect( result.events[ 0 ].fields ).toEqual( {
			feature: '',
			http_status: 599,
			latency_ms: 0,
			attempts: 1,
			image_count: 0,
		} );
	} );

	it( 'floors ts to the hour and drops events outside the window', () => {
		const result = validateBatch(
			batch( [
				{ name: 'deactivated', ts: NOW - 125 },
				{ name: 'deactivated', ts: NOW - 31 * 86400 },
				{ name: 'deactivated', ts: NOW + 2 * HOUR },
				{ name: 'deactivated', ts: 'now' },
			] ),
			NOW
		);
		if ( ! result.ok ) {
			throw new Error( 'expected ok' );
		}
		expect( result.events ).toHaveLength( 1 );
		expect( result.events[ 0 ].ts % HOUR ).toBe( 0 );
		expect( result.events[ 0 ].ts ).toBeLessThanOrEqual( NOW - 125 );
		expect( result.dropped ).toBe( 3 );
	} );

	it( 'normalises the providers list', () => {
		const result = validateBatch(
			batch( [ { name: 'heartbeat', ts: NOW, providers: 'openai,claude, openai,acme' } ] ),
			NOW
		);
		if ( ! result.ok ) {
			throw new Error( 'expected ok' );
		}
		expect( result.events[ 0 ].fields.providers ).toBe( 'claude,openai' );
	} );

	it( 'checks patterns and uuid fields', () => {
		const result = validateBatch(
			batch( [
				{
					name: 'ai_request',
					ts: NOW,
					model: 'client.example.com with spaces',
					turn_id: '11111111-2222-4333-8444-555555555555'.toUpperCase(),
				},
				{ name: 'heartbeat', ts: NOW, locale: 'de_DE_formal', wp_version: '6.7.1' },
			] ),
			NOW
		);
		if ( ! result.ok ) {
			throw new Error( 'expected ok' );
		}
		expect( result.events[ 0 ].fields.model ).toBe( '' );
		expect( result.events[ 0 ].fields.turn_id ).toBe( '11111111-2222-4333-8444-555555555555' );
		expect( result.events[ 1 ].fields.locale ).toBe( 'de_DE_formal' );
		expect( result.events[ 1 ].fields.wp_version ).toBe( '' );
	} );

	it( 'lower-cases the install id', () => {
		const result = validateBatch( batch( [ ok ], { install_id: INSTALL_ID.toUpperCase() } ), NOW );
		expect( result.ok && result.installId ).toBe( INSTALL_ID );
		expect( normaliseInstallId( 42 ) ).toBe( '' );
	} );
} );

// ── readCappedBody ────────────────────────────────────────────────────────────

describe( 'readCappedBody', () => {
	it( 'returns null once the stream exceeds the cap', async () => {
		const stream = new ReadableStream< Uint8Array >( {
			start( controller ) {
				controller.enqueue( new Uint8Array( 10 ) );
				controller.enqueue( new Uint8Array( 10 ) );
				controller.close();
			},
		} );
		const request = new Request( 'https://proxy.test/v1/events', {
			method: 'POST',
			body: stream,
			// @ts-expect-error duplex is required by Node for streamed bodies.
			duplex: 'half',
		} );
		expect( await readCappedBody( request, 15 ) ).toBeNull();
	} );

	it( 'returns the text under the cap', async () => {
		expect( await readCappedBody( post( 'hello' ), 15 ) ).toBe( 'hello' );
	} );
} );

// ── handleEvents ──────────────────────────────────────────────────────────────

describe( 'handleEvents', () => {
	it( 'returns 405 for GET through the router', async () => {
		const { env } = eventsEnv();
		const res = await worker.fetch(
			new Request( 'https://proxy.test/v1/events' ),
			env,
			{} as ExecutionContext
		);
		expect( res.status ).toBe( 405 );
	} );

	it( 'routes POST through the router', async () => {
		const { env, writes } = eventsEnv();
		const res = await worker.fetch(
			post( batch( [ { name: 'deactivated', ts: nowTs() } ] ) ),
			env,
			{} as ExecutionContext
		);
		expect( res.status ).toBe( 202 );
		expect( writes ).toHaveLength( 1 );
	} );

	it( 'returns 415 for a non-JSON content type', async () => {
		const { env, writes } = eventsEnv();
		const res = await handleEvents(
			post( batch( [ { name: 'deactivated', ts: nowTs() } ] ), { 'Content-Type': 'text/plain' } ),
			env
		);
		expect( res.status ).toBe( 415 );
		expect( writes ).toHaveLength( 0 );
	} );

	it( 'returns 413 when Content-Length is too large', async () => {
		const { env } = eventsEnv();
		const req = new Request( 'https://proxy.test/v1/events', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'Content-Length': '20000' },
			body: '{}',
		} );
		expect( ( await handleEvents( req, env ) ).status ).toBe( 413 );
	} );

	it( 'returns 413 for an oversized chunked body with no Content-Length', async () => {
		const { env } = eventsEnv();
		const chunk = new TextEncoder().encode( 'x'.repeat( 9000 ) );
		const stream = new ReadableStream< Uint8Array >( {
			start( controller ) {
				controller.enqueue( chunk );
				controller.enqueue( chunk );
				controller.close();
			},
		} );
		const req = new Request( 'https://proxy.test/v1/events', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: stream,
			// @ts-expect-error duplex is required by Node for streamed bodies.
			duplex: 'half',
		} );
		expect( req.headers.get( 'Content-Length' ) ).toBeNull();
		expect( ( await handleEvents( req, env ) ).status ).toBe( 413 );
	} );

	it( 'returns 400 for invalid JSON', async () => {
		const { env } = eventsEnv();
		const res = await handleEvents( post( '{not json' ), env );
		expect( res.status ).toBe( 400 );
		expect( await res.json() ).toEqual( { error: 'invalid_batch' } );
	} );

	it( 'returns 400 for an invalid batch', async () => {
		const { env } = eventsEnv();
		expect( ( await handleEvents( post( batch( [] ) ), env ) ).status ).toBe( 400 );
	} );

	it( 'writes one datapoint per event, indexed by the lower-cased install id', async () => {
		const { env, writes } = eventsEnv();
		const res = await handleEvents(
			post(
				batch(
					[
						{ name: 'deactivated', ts: nowTs() },
						{ name: 'onboarding_done', ts: nowTs(), route: 'skipped' },
						{ name: 'unknown', ts: nowTs() },
					],
					{ install_id: INSTALL_ID.toUpperCase() }
				)
			),
			env
		);
		expect( res.status ).toBe( 202 );
		expect( await res.json() ).toEqual( { accepted: 2, dropped: 1 } );
		expect( writes ).toHaveLength( 2 );
		expect( writes.map( ( w ) => w.indexes ) ).toEqual( [ [ INSTALL_ID ], [ INSTALL_ID ] ] );
	} );

	it( 'returns 429 when the IP limiter refuses', async () => {
		const { env, writes } = eventsEnv( {
			EVENTS_IP_LIMITER: { limit: async () => ( { success: false } ) },
		} );
		const res = await handleEvents(
			post( batch( [ { name: 'deactivated', ts: nowTs() } ] ), { 'CF-Connecting-IP': '203.0.113.9' } ),
			env
		);
		expect( res.status ).toBe( 429 );
		expect( writes ).toHaveLength( 0 );
	} );

	it( 'returns 429 when the install limiter refuses, keyed on the install id', async () => {
		const keys: string[] = [];
		const { env } = eventsEnv( {
			EVENTS_INSTALL_LIMITER: {
				limit: async ( { key } ) => {
					keys.push( key );
					return { success: false };
				},
			},
		} );
		const res = await handleEvents( post( batch( [ { name: 'deactivated', ts: nowTs() } ] ) ), env );
		expect( res.status ).toBe( 429 );
		expect( keys ).toEqual( [ INSTALL_ID ] );
	} );

	it( 'lets the request through when a limiter is missing or throws', async () => {
		const { env, writes } = eventsEnv( {
			EVENTS_IP_LIMITER: {
				limit: async () => {
					throw new Error( 'limiter down' );
				},
			},
		} );
		const res = await handleEvents( post( batch( [ { name: 'deactivated', ts: nowTs() } ] ) ), env );
		expect( res.status ).toBe( 202 );
		expect( writes ).toHaveLength( 1 );
	} );

	it( 'answers 202 without the dataset binding', async () => {
		const env = makeEnv();
		const res = await handleEvents( post( batch( [ { name: 'deactivated', ts: nowTs() } ] ) ), env );
		expect( res.status ).toBe( 202 );
	} );

	it( 'a failing write never throws and logs only a fixed line', async () => {
		const warn = vi.spyOn( console, 'warn' ).mockImplementation( () => {} );
		const env = makeEnv( {
			PLUME_EVENTS: {
				writeDataPoint: () => {
					throw new Error( 'boom' );
				},
			} as AnalyticsEngineDataset,
		} );
		const res = await handleEvents( post( batch( [ { name: 'deactivated', ts: nowTs() } ] ) ), env );
		expect( res.status ).toBe( 202 );
		expect( warn ).toHaveBeenCalledWith( 'events: write failed' );
	} );

	it( 'never echoes input and never passes the body or IP to console', async () => {
		const spies = [ 'log', 'info', 'warn', 'error', 'debug' ].map( ( m ) =>
			vi.spyOn( console, m as 'log' ).mockImplementation( () => {} )
		);
		const { env } = eventsEnv();
		const secret = 'client-secret.example.com';
		const responses = await Promise.all( [
			handleEvents(
				post(
					batch( [ { name: 'ai_request', ts: nowTs(), model: secret, extra: secret } ] ),
					{ 'CF-Connecting-IP': '198.51.100.7' }
				),
				env
			),
			handleEvents( post( `{"broken": "${ secret }"` ), env ),
			handleEvents( post( batch( [ { name: secret, ts: nowTs() } ] ) ), env ),
		] );
		for ( const res of responses ) {
			expect( await res.text() ).not.toContain( secret );
		}
		for ( const spy of spies ) {
			for ( const call of spy.mock.calls ) {
				const text = JSON.stringify( call );
				expect( text ).not.toContain( secret );
				expect( text ).not.toContain( '198.51.100.7' );
			}
		}
	} );
} );

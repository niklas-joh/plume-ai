// src/events.ts
//
// POST /v1/events: opt-in product usage events from the WordPress plugin, written to
// the Workers Analytics Engine dataset `plume_events` (one datapoint per event).
//
// These events are deliberately never linked to plume_metrics: they carry a random
// install ID instead of a site key, plugin-side timestamps are rounded down to the hour,
// and the plugin sends from an hourly cron, not from the request that made the AI call.
// Phase 2 must never join the two datasets on time, tokens, IP or any other field.
//
// SCHEMA IS APPEND-ONLY: never reorder or reuse a slot. A breaking change needs a new
// dataset name. Positions are locked by test/events.test.ts.
//
//   index1  install_id (lower-case v4 UUID, 36 bytes)
//   blob1   event
//   blob2   plugin_version
//   blob3   feature            ai_request
//   blob4   provider           ai_request
//   blob5   model              ai_request
//   blob6   route              ai_request (proxy/byok/ollama), onboarding_done (managed/own_key/skipped/upgrade)
//   blob7   outcome            ai_request
//   blob8   error_code         ai_request
//   blob9   first_error_code   ai_request
//   blob10  turn_id            ai_request
//   blob11  origin             consent_given.source, onboarding_step.step, upgrade_click.placement
//   blob12  detail             onboarding_step.action, upgrade_click.screen
//   blob13  plan               upgrade_click
//   blob14  tier               heartbeat
//   blob15  providers          heartbeat
//   blob16  wp_version         heartbeat
//   blob17  php_version        heartbeat
//   blob18  locale             heartbeat
//   blob19-20 reserved
//   double1 client_ts (hour-rounded; Analytics Engine's own timestamp is the send time)
//   double2 schema_version
//   double3 http_status        ai_request
//   double4 latency_ms         ai_request
//   double5 input_tokens       ai_request (always 0 on the proxy route)
//   double6 output_tokens      ai_request (always 0 on the proxy route)
//   double7 image_count        ai_request
//   double8 attempts           ai_request
//   double9 days_since_activation  heartbeat
//   double10 days_since_last_ai    heartbeat
//   double11-20 reserved

import schema from './event-schema.json';
import type { Env } from './types';

/** Version 4 UUID, any letter case. @since NEXT_VERSION */
export const UUID_V4 =
	/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

const PLUGIN_VERSION =
	/^\d{1,3}\.\d{1,3}\.\d{1,3}([-+][0-9A-Za-z.-]{1,20})?$/;
const MAX_PLUGIN_VERSION_BYTES = 32;
const HOUR = 3600;
const PROVIDER_TYPES = [ 'claude', 'openai', 'gemini', 'ollama' ];

/** Zero-based positions inside `blobs`. @since NEXT_VERSION */
export const EVENT_BLOB = {
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
} as const;

/** Zero-based positions inside `doubles`. @since NEXT_VERSION */
export const EVENT_DOUBLE = {
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
} as const;

const BLOB_COUNT = 18;
const DOUBLE_COUNT = 10;

type Slot = { kind: 'blob' | 'double'; index: number };
const blob = ( index: number ): Slot => ( { kind: 'blob', index } );
const double = ( index: number ): Slot => ( { kind: 'double', index } );

/**
 * Event name => field name => datapoint slot. Must cover exactly the fields in
 * event-schema.json (checked by test/events.test.ts).
 *
 * @since NEXT_VERSION
 */
export const FIELD_SLOTS: Record< string, Record< string, Slot > > = {
	consent_given: { source: blob( EVENT_BLOB.ORIGIN ) },
	onboarding_step: {
		step: blob( EVENT_BLOB.ORIGIN ),
		action: blob( EVENT_BLOB.DETAIL ),
	},
	onboarding_done: { route: blob( EVENT_BLOB.ROUTE ) },
	ai_request: {
		feature: blob( EVENT_BLOB.FEATURE ),
		provider: blob( EVENT_BLOB.PROVIDER ),
		model: blob( EVENT_BLOB.MODEL ),
		route: blob( EVENT_BLOB.ROUTE ),
		outcome: blob( EVENT_BLOB.OUTCOME ),
		error_code: blob( EVENT_BLOB.ERROR_CODE ),
		first_error_code: blob( EVENT_BLOB.FIRST_ERROR_CODE ),
		http_status: double( EVENT_DOUBLE.HTTP_STATUS ),
		latency_ms: double( EVENT_DOUBLE.LATENCY_MS ),
		input_tokens: double( EVENT_DOUBLE.INPUT_TOKENS ),
		output_tokens: double( EVENT_DOUBLE.OUTPUT_TOKENS ),
		image_count: double( EVENT_DOUBLE.IMAGE_COUNT ),
		attempts: double( EVENT_DOUBLE.ATTEMPTS ),
		turn_id: blob( EVENT_BLOB.TURN_ID ),
	},
	upgrade_click: {
		placement: blob( EVENT_BLOB.ORIGIN ),
		screen: blob( EVENT_BLOB.DETAIL ),
		plan: blob( EVENT_BLOB.PLAN ),
	},
	heartbeat: {
		tier: blob( EVENT_BLOB.TIER ),
		providers: blob( EVENT_BLOB.PROVIDERS ),
		wp_version: blob( EVENT_BLOB.WP_VERSION ),
		php_version: blob( EVENT_BLOB.PHP_VERSION ),
		locale: blob( EVENT_BLOB.LOCALE ),
		days_since_activation: double( EVENT_DOUBLE.DAYS_SINCE_ACTIVATION ),
		days_since_last_ai: double( EVENT_DOUBLE.DAYS_SINCE_LAST_AI ),
	},
	deactivated: {},
};

type FieldSpec =
	| { type: 'enum'; values: string[] }
	| { type: 'int'; min: number; max: number; default: number }
	| { type: 'pattern'; regex: string; max_bytes: number }
	| { type: 'uuid4' }
	| { type: 'providers' };

/** The allowlist, from event-schema.json (shared with the plugin's EventSchema::SPEC). */
const EVENTS = schema.events as unknown as Record<
	string,
	Record< string, FieldSpec >
>;

const PATTERNS = new Map< string, RegExp >();

/** One accepted event. @since NEXT_VERSION */
export interface ValidEvent {
	name: string;
	ts: number;
	fields: Record< string, string | number >;
}

/** Outcome of validateBatch(). @since NEXT_VERSION */
export type BatchResult =
	| {
			ok: true;
			installId: string;
			pluginVersion: string;
			events: ValidEvent[];
			dropped: number;
	  }
	| { ok: false; status: 400; error: 'invalid_batch' };

const INVALID: BatchResult = { ok: false, status: 400, error: 'invalid_batch' };

/**
 * Lower-cased install ID, or '' when the value is not a v4 UUID.
 *
 * @since NEXT_VERSION
 * @param {unknown} value Candidate value.
 * @return {string} Normalised install ID or ''.
 */
export function normaliseInstallId( value: unknown ): string {
	return typeof value === 'string' && UUID_V4.test( value )
		? value.toLowerCase()
		: '';
}

function byteLength( value: string ): number {
	return new TextEncoder().encode( value ).length;
}

function isPlainObject( value: unknown ): value is Record< string, unknown > {
	return typeof value === 'object' && value !== null && ! Array.isArray( value );
}

/**
 * Clean one value against its field spec: '' or the default when invalid.
 *
 * @param {FieldSpec} spec  Field spec.
 * @param {unknown}   value Raw value.
 * @return {string|number} Clean value.
 */
function sanitiseValue( spec: FieldSpec, value: unknown ): string | number {
	switch ( spec.type ) {
		case 'enum':
			return typeof value === 'string' && spec.values.includes( value )
				? value
				: '';
		case 'int':
			if (
				typeof value !== 'number' ||
				! Number.isFinite( value ) ||
				! Number.isInteger( value )
			) {
				return spec.default;
			}
			return Math.min( spec.max, Math.max( spec.min, value ) );
		case 'pattern': {
			if ( typeof value !== 'string' || byteLength( value ) > spec.max_bytes ) {
				return '';
			}
			let re = PATTERNS.get( spec.regex );
			if ( ! re ) {
				re = new RegExp( spec.regex );
				PATTERNS.set( spec.regex, re );
			}
			return re.test( value ) ? value : '';
		}
		case 'uuid4':
			return normaliseInstallId( value );
		case 'providers': {
			if ( typeof value !== 'string' ) {
				return '';
			}
			const list = [
				...new Set(
					value
						.split( ',' )
						.map( ( p ) => p.trim() )
						.filter( ( p ) => PROVIDER_TYPES.includes( p ) )
				),
			].sort();
			return list.join( ',' );
		}
	}
	return '';
}

/**
 * Validate a decoded batch against the allowlist.
 *
 * The envelope must be valid as a whole; individual events with an unknown name or a
 * `ts` outside [now - 30 days, now + 1 hour] are dropped and counted. Unknown keys are
 * dropped; invalid known values become '' or the field default.
 *
 * @since NEXT_VERSION
 * @param {unknown} raw        Decoded JSON body.
 * @param {number}  nowSeconds Current Unix time in seconds.
 * @return {BatchResult} The accepted events, or a 400 result.
 */
export function validateBatch( raw: unknown, nowSeconds: number ): BatchResult {
	if ( ! isPlainObject( raw ) || raw.schema !== schema.schema_version ) {
		return INVALID;
	}
	const installId = normaliseInstallId( raw.install_id );
	if ( installId === '' ) {
		return INVALID;
	}
	const pluginVersion = raw.plugin_version;
	if (
		typeof pluginVersion !== 'string' ||
		byteLength( pluginVersion ) > MAX_PLUGIN_VERSION_BYTES ||
		! PLUGIN_VERSION.test( pluginVersion )
	) {
		return INVALID;
	}
	const list = raw.events;
	if (
		! Array.isArray( list ) ||
		list.length < 1 ||
		list.length > schema.max_events
	) {
		return INVALID;
	}

	const earliest = nowSeconds - schema.max_past_seconds;
	const latest = nowSeconds + schema.max_future_seconds;
	const events: ValidEvent[] = [];
	let dropped = 0;

	for ( const item of list ) {
		if ( ! isPlainObject( item ) ) {
			dropped++;
			continue;
		}
		const name = item.name;
		const ts = item.ts;
		if (
			typeof name !== 'string' ||
			! Object.prototype.hasOwnProperty.call( EVENTS, name ) ||
			typeof ts !== 'number' ||
			! Number.isFinite( ts ) ||
			ts < earliest ||
			ts > latest
		) {
			dropped++;
			continue;
		}
		const fields: Record< string, string | number > = {};
		for ( const [ field, spec ] of Object.entries( EVENTS[ name ] ) ) {
			if ( Object.prototype.hasOwnProperty.call( item, field ) ) {
				fields[ field ] = sanitiseValue( spec, item[ field ] );
			}
		}
		const floored = Math.floor( ts );
		events.push( { name, ts: floored - ( floored % HOUR ), fields } );
	}

	if ( events.length === 0 ) {
		return INVALID;
	}
	return { ok: true, installId, pluginVersion, events, dropped };
}

/**
 * Build the Analytics Engine datapoint for one event.
 *
 * Slots an event does not use stay '' (blobs) or 0 (doubles); fields the event uses
 * but did not send get '' or their schema default.
 *
 * @since NEXT_VERSION
 * @param {string}     installId     Lower-case install ID.
 * @param {string}     pluginVersion Plugin version from the batch.
 * @param {ValidEvent} ev            Validated event.
 * @return {AnalyticsEngineDataPoint} The datapoint.
 */
export function buildEventDataPoint(
	installId: string,
	pluginVersion: string,
	ev: ValidEvent
): AnalyticsEngineDataPoint {
	const blobs: string[] = new Array( BLOB_COUNT ).fill( '' );
	const doubles: number[] = new Array( DOUBLE_COUNT ).fill( 0 );

	blobs[ EVENT_BLOB.EVENT ] = ev.name;
	blobs[ EVENT_BLOB.PLUGIN_VERSION ] = pluginVersion;
	doubles[ EVENT_DOUBLE.CLIENT_TS ] = ev.ts;
	doubles[ EVENT_DOUBLE.SCHEMA_VERSION ] = schema.schema_version;

	const specs = EVENTS[ ev.name ] ?? {};
	const slots = FIELD_SLOTS[ ev.name ] ?? {};
	for ( const [ field, slot ] of Object.entries( slots ) ) {
		const spec = specs[ field ];
		const value =
			field in ev.fields
				? ev.fields[ field ]
				: spec?.type === 'int'
				? spec.default
				: '';
		if ( slot.kind === 'blob' ) {
			blobs[ slot.index ] = String( value );
		} else {
			doubles[ slot.index ] = typeof value === 'number' ? value : 0;
		}
	}

	return { indexes: [ installId ], blobs, doubles };
}

/**
 * Read a request body, stopping as soon as it exceeds maxBytes.
 *
 * Chunked bodies have no Content-Length, so the header check alone cannot bound them.
 *
 * @since NEXT_VERSION
 * @param {Request} request  Incoming request.
 * @param {number}  maxBytes Byte limit.
 * @return {Promise<string|null>} The body text, or null when it is too large.
 */
export async function readCappedBody(
	request: Request,
	maxBytes: number
): Promise< string | null > {
	if ( ! request.body ) {
		return '';
	}
	const reader = request.body.getReader();
	const chunks: Uint8Array[] = [];
	let total = 0;
	for (;;) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}
		total += value.byteLength;
		if ( total > maxBytes ) {
			await reader.cancel().catch( () => {} );
			return null;
		}
		chunks.push( value );
	}
	const bytes = new Uint8Array( total );
	let offset = 0;
	for ( const chunk of chunks ) {
		bytes.set( chunk, offset );
		offset += chunk.byteLength;
	}
	return new TextDecoder().decode( bytes );
}

/**
 * Write one datapoint. Never throws, no-op without the binding, logs no payload.
 *
 * @since NEXT_VERSION
 * @param {Env}      env   Worker environment.
 * @param {Function} build Lazy datapoint builder.
 */
export function recordEvent(
	env: Env,
	build: () => AnalyticsEngineDataPoint
): void {
	if ( ! env.PLUME_EVENTS ) {
		return;
	}
	try {
		env.PLUME_EVENTS.writeDataPoint( build() );
	} catch {
		// eslint-disable-next-line no-console
		console.warn( 'events: write failed' );
	}
}

/**
 * Ask a rate limiter; a missing or failing limiter lets the request through.
 *
 * @param {RateLimit|undefined} limiter Binding.
 * @param {string}              key     Limiter key.
 * @return {Promise<boolean>} False only when the limiter says the key is over its limit.
 */
async function allowed(
	limiter: RateLimit | undefined,
	key: string
): Promise< boolean > {
	if ( ! limiter ) {
		return true;
	}
	try {
		const { success } = await limiter.limit( { key } );
		return success !== false;
	} catch {
		return true;
	}
}

function reply( data: unknown, status: number ): Response {
	return new Response( JSON.stringify( data ), {
		status,
		headers: { 'Content-Type': 'application/json' },
	} );
}

/**
 * Handle POST /v1/events.
 *
 * No auth and no CORS. The input is never echoed and neither the body nor the IP is
 * logged.
 *
 * @since NEXT_VERSION
 * @param {Request} request Incoming request (method already checked by the router).
 * @param {Env}     env     Worker environment.
 * @return {Promise<Response>} 202 { accepted, dropped } or an error status.
 */
export async function handleEvents(
	request: Request,
	env: Env
): Promise< Response > {
	// Requiring JSON forces a CORS preflight (which fails), so web pages cannot make
	// visitors' browsers post "simple" text/plain events.
	const contentType = ( request.headers.get( 'Content-Type' ) ?? '' ).toLowerCase();
	if ( ! contentType.startsWith( 'application/json' ) ) {
		return reply( { error: 'unsupported_media_type' }, 415 );
	}

	const ip = request.headers.get( 'CF-Connecting-IP' ) ?? 'unknown';
	if ( ! ( await allowed( env.EVENTS_IP_LIMITER, ip ) ) ) {
		return reply( { error: 'rate_limited' }, 429 );
	}

	const declared = Number( request.headers.get( 'Content-Length' ) ?? '0' );
	if ( Number.isFinite( declared ) && declared > schema.max_bytes ) {
		return reply( { error: 'too_large' }, 413 );
	}
	const text = await readCappedBody( request, schema.max_bytes );
	if ( text === null ) {
		return reply( { error: 'too_large' }, 413 );
	}

	let raw: unknown;
	try {
		raw = JSON.parse( text );
	} catch {
		return reply( { error: 'invalid_batch' }, 400 );
	}
	const result = validateBatch( raw, Math.floor( Date.now() / 1000 ) );
	if ( ! result.ok ) {
		return reply( { error: result.error }, result.status );
	}

	if ( ! ( await allowed( env.EVENTS_INSTALL_LIMITER, result.installId ) ) ) {
		return reply( { error: 'rate_limited' }, 429 );
	}

	for ( const ev of result.events ) {
		recordEvent( env, () =>
			buildEventDataPoint( result.installId, result.pluginVersion, ev )
		);
	}

	return reply(
		{ accepted: result.events.length, dropped: result.dropped },
		202
	);
}

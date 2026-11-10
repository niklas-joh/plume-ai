// src/metrics.ts
//
// Usage metrics written to Workers Analytics Engine (one datapoint per request).
//
// SCHEMA IS APPEND-ONLY: never reorder or reuse a slot. A breaking change needs
// a new dataset name. Positions are locked by test/metrics.test.ts.
//
//   index1  site_key (normalised host[/path], '' when unknown or untrusted)
//   blob1   event        chat | register
//   blob2   feature      chat | generator | seo | images | ''
//   blob3   provider     claude | openai | gemini | ''
//   blob4   model        resolved model only (never the client-supplied one)
//   blob5   tier         free | pro_managed | pro_byok | ''
//   blob6   outcome      see ChatOutcome / RegisterOutcome
//   blob7   error_code   upstream_<status> | validation | internal | verification reason
//   blob8   site_ref     register only: first 12 hex chars of sha256(site_url)
//   blob9   requested_provider  provider the client asked for (differs from blob3 when
//                        a disabled provider is served by Claude, #1020); chat only
//   double1 http_status
//   double2 latency_ms
//   double3 upstream_ms
//   double4 credits_charged
//   double5 credits_used_after
//   double6 credit_limit
//   double7 input_tokens
//   double8 output_tokens
//   double9 cache_read_tokens
//   double10 cache_write_tokens
//   double11 tool_calls (count only)
//   double12 is_new (register: 1 = new, 0 = existing)

import type { Env, SiteTier } from './types';

/** Zero-based positions inside `blobs`. @since NEXT_VERSION */
export const BLOB = {
	EVENT: 0,
	FEATURE: 1,
	PROVIDER: 2,
	MODEL: 3,
	TIER: 4,
	OUTCOME: 5,
	ERROR_CODE: 6,
	SITE_REF: 7,
	REQUESTED_PROVIDER: 8,
} as const;

/** Zero-based positions inside `doubles`. @since NEXT_VERSION */
export const DOUBLE = {
	HTTP_STATUS: 0,
	LATENCY_MS: 1,
	UPSTREAM_MS: 2,
	CREDITS_CHARGED: 3,
	CREDITS_USED_AFTER: 4,
	CREDIT_LIMIT: 5,
	INPUT_TOKENS: 6,
	OUTPUT_TOKENS: 7,
	CACHE_READ_TOKENS: 8,
	CACHE_WRITE_TOKENS: 9,
	TOOL_CALLS: 10,
	IS_NEW: 11,
} as const;

const MAX_INDEX_BYTES = 96;
const MAX_BLOB_BYTES = 128;
const BLOB_COUNT = 9;
const DOUBLE_COUNT = 12;

export type ChatOutcome =
	| 'ok'
	| 'unauthorised'
	| 'rate_limited'
	| 'too_large'
	| 'bad_request'
	| 'byok_blocked'
	| 'upstream_error'
	| 'internal_error';

/**
 * Outcomes recorded for a /register attempt. Requests rejected before the
 * challenge check (invalid JSON, invalid site_url, missing challenge_token)
 * are deliberately not recorded: see handleRegistration. Adding a label here
 * is an append-only schema change.
 *
 * @since NEXT_VERSION
 */
export type RegisterOutcome =
	| 'registered'
	| 'existing'
	| 'verify_failed'
	| 'reg_rate_limited'
	| 'bad_challenge';

/**
 * Allowlisted fields for a chat datapoint. Deliberately NOT a SiteRecord or
 * Request, so a secret cannot flow into a datapoint by accident.
 *
 * @since NEXT_VERSION
 */
export interface ChatMetric {
	startedAt: number;
	siteKey: string;
	feature: string;
	provider: string;
	/** Provider the client asked for; differs from `provider` when substituted. */
	requestedProvider: string;
	model: string;
	tier: SiteTier | '';
	errorCode: string;
	upstreamMs: number;
	creditsCharged: number;
	creditsUsedAfter: number;
	creditLimit: number;
	inputTokens: number;
	outputTokens: number;
	cacheReadTokens: number;
	cacheWriteTokens: number;
	toolCalls: number;
}

/**
 * Create an empty chat metric with a start timestamp.
 *
 * @since NEXT_VERSION
 * @return {ChatMetric} Blank metric.
 */
export function newChatMetric(): ChatMetric {
	return {
		startedAt: Date.now(),
		siteKey: '',
		feature: '',
		provider: '',
		requestedProvider: '',
		model: '',
		tier: '',
		errorCode: '',
		upstreamMs: 0,
		creditsCharged: 0,
		creditsUsedAfter: 0,
		creditLimit: 0,
		inputTokens: 0,
		outputTokens: 0,
		cacheReadTokens: 0,
		cacheWriteTokens: 0,
		toolCalls: 0,
	};
}

/** Allowlisted fields for a register datapoint. @since NEXT_VERSION */
export interface RegisterMetric {
	startedAt: number;
	outcome: RegisterOutcome;
	status: number;
	/** Only ever a trusted/verified URL; '' for failed or unverified attempts. */
	siteKey: string;
	errorCode?: string;
	siteRef?: string;
	isNew?: boolean;
}

/**
 * Truncate to a UTF-8 byte budget without splitting a character.
 *
 * @param {string} value    Input.
 * @param {number} maxBytes Byte budget.
 * @return {string} Truncated string.
 */
function truncateBytes( value: string, maxBytes: number ): string {
	const encoder = new TextEncoder();
	if ( encoder.encode( value ).length <= maxBytes ) {
		return value;
	}
	let out = '';
	let used = 0;
	for ( const ch of value ) {
		const size = encoder.encode( ch ).length;
		if ( used + size > maxBytes ) {
			break;
		}
		out += ch;
		used += size;
	}
	return out;
}

/**
 * Normalise a site URL to `host[/path]`: lowercase, `www.` stripped, no scheme,
 * port, credentials, query or fragment, max 96 bytes. Only call this with a
 * trusted URL (the KV `site_url` of an authenticated token, or a URL that
 * passed the registration callback). Returns '' on failure.
 *
 * @since NEXT_VERSION
 * @param {string|undefined} siteUrl Site URL.
 * @return {string} Normalised key or ''.
 */
export function siteKeyFromUrl( siteUrl?: string ): string {
	if ( ! siteUrl ) {
		return '';
	}
	try {
		const url = new URL( siteUrl );
		if ( url.protocol !== 'http:' && url.protocol !== 'https:' ) {
			return '';
		}
		const host = url.hostname.toLowerCase().replace( /^www\./, '' );
		if ( ! host ) {
			return '';
		}
		const path = url.pathname.replace( /\/+$/, '' );
		return truncateBytes( host + path, MAX_INDEX_BYTES );
	} catch {
		return '';
	}
}

/**
 * Map a chat response status (and error code) to an outcome label.
 *
 * @since NEXT_VERSION
 * @param {number} status    HTTP status returned to the client.
 * @param {string} errorCode Error code from errorCodeFromThrown(), if any.
 * @return {ChatOutcome} Outcome label.
 */
export function classifyChatOutcome(
	status: number,
	errorCode = ''
): ChatOutcome {
	if ( status >= 200 && status < 300 ) {
		return 'ok';
	}
	switch ( status ) {
		case 401:
			return 'unauthorised';
		case 429:
			return 'rate_limited';
		case 413:
			return 'too_large';
		// Assumes 403 is only ever the BYOK block in chat (the single 403 path
		// today). Revisit this mapping if another 403 is added to the handler.
		case 403:
			return 'byok_blocked';
		case 400:
			return 'bad_request';
	}
	return errorCode.startsWith( 'upstream_' )
		? 'upstream_error'
		: 'internal_error';
}

/**
 * Derive a safe error code from a thrown value. Reads only `status` and
 * `isValidationError`, never `message` or `body`.
 *
 * @since NEXT_VERSION
 * @param {unknown} error Thrown value.
 * @return {string} validation | upstream_<status> | internal
 */
export function errorCodeFromThrown( error: unknown ): string {
	const tagged = error as
		| { status?: unknown; isValidationError?: unknown }
		| null
		| undefined;
	if ( tagged?.isValidationError === true ) {
		return 'validation';
	}
	if (
		typeof tagged?.status === 'number' &&
		Number.isInteger( tagged.status )
	) {
		return `upstream_${ tagged.status }`;
	}
	return 'internal';
}

function num( value: unknown ): number {
	return typeof value === 'number' && Number.isFinite( value ) ? value : 0;
}

function blob( value: string | undefined ): string {
	return truncateBytes( value ?? '', MAX_BLOB_BYTES );
}

/**
 * Build the chat datapoint from an allowlisted metric.
 *
 * @since NEXT_VERSION
 * @param {ChatMetric & {status:number, latencyMs:number}} m Metric plus final status and latency.
 * @return {AnalyticsEngineDataPoint} Datapoint.
 */
export function buildChatDataPoint(
	m: ChatMetric & { status: number; latencyMs: number }
): AnalyticsEngineDataPoint {
	const blobs: string[] = new Array( BLOB_COUNT ).fill( '' );
	const doubles: number[] = new Array( DOUBLE_COUNT ).fill( 0 );
	blobs[ BLOB.EVENT ] = 'chat';
	blobs[ BLOB.FEATURE ] = blob( m.feature );
	blobs[ BLOB.PROVIDER ] = blob( m.provider );
	blobs[ BLOB.REQUESTED_PROVIDER ] = blob( m.requestedProvider );
	blobs[ BLOB.MODEL ] = blob( m.model );
	blobs[ BLOB.TIER ] = blob( m.tier );
	blobs[ BLOB.OUTCOME ] = classifyChatOutcome( m.status, m.errorCode );
	blobs[ BLOB.ERROR_CODE ] = blob( m.errorCode );
	doubles[ DOUBLE.HTTP_STATUS ] = num( m.status );
	doubles[ DOUBLE.LATENCY_MS ] = num( m.latencyMs );
	doubles[ DOUBLE.UPSTREAM_MS ] = num( m.upstreamMs );
	doubles[ DOUBLE.CREDITS_CHARGED ] = num( m.creditsCharged );
	doubles[ DOUBLE.CREDITS_USED_AFTER ] = num( m.creditsUsedAfter );
	doubles[ DOUBLE.CREDIT_LIMIT ] = num( m.creditLimit );
	doubles[ DOUBLE.INPUT_TOKENS ] = num( m.inputTokens );
	doubles[ DOUBLE.OUTPUT_TOKENS ] = num( m.outputTokens );
	doubles[ DOUBLE.CACHE_READ_TOKENS ] = num( m.cacheReadTokens );
	doubles[ DOUBLE.CACHE_WRITE_TOKENS ] = num( m.cacheWriteTokens );
	doubles[ DOUBLE.TOOL_CALLS ] = num( m.toolCalls );
	return {
		indexes: [ truncateBytes( m.siteKey, MAX_INDEX_BYTES ) ],
		blobs,
		doubles,
	};
}

/**
 * Build the register datapoint from an allowlisted metric.
 *
 * @since NEXT_VERSION
 * @param {RegisterMetric} m Register metric.
 * @return {AnalyticsEngineDataPoint} Datapoint.
 */
export function buildRegisterDataPoint(
	m: RegisterMetric
): AnalyticsEngineDataPoint {
	const blobs: string[] = new Array( BLOB_COUNT ).fill( '' );
	const doubles: number[] = new Array( DOUBLE_COUNT ).fill( 0 );
	blobs[ BLOB.EVENT ] = 'register';
	blobs[ BLOB.OUTCOME ] = m.outcome;
	blobs[ BLOB.ERROR_CODE ] = blob( m.errorCode );
	blobs[ BLOB.SITE_REF ] = blob( m.siteRef );
	doubles[ DOUBLE.HTTP_STATUS ] = num( m.status );
	doubles[ DOUBLE.LATENCY_MS ] = num( Date.now() - m.startedAt );
	doubles[ DOUBLE.IS_NEW ] = m.isNew ? 1 : 0;
	return {
		indexes: [ truncateBytes( m.siteKey, MAX_INDEX_BYTES ) ],
		blobs,
		doubles,
	};
}

/**
 * Write one datapoint. Never throws, never awaits, no-op without the binding.
 * The log line deliberately carries no payload.
 *
 * @since NEXT_VERSION
 * @param {Env}      env   Worker environment.
 * @param {Function} build Lazy datapoint builder.
 */
export function recordMetric(
	env: Env,
	build: () => AnalyticsEngineDataPoint
): void {
	if ( ! env.PLUME_METRICS ) {
		return;
	}
	try {
		env.PLUME_METRICS.writeDataPoint( build() );
	} catch {
		// eslint-disable-next-line no-console
		console.warn( 'metrics: write failed' );
	}
}

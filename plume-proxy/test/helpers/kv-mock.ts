import type { Env } from '../../src/types';

/**
 * Create an in-memory KVNamespace stub for unit tests.
 *
 * @return {KVNamespace} Minimal KV implementation backed by a Map.
 */
export function makeKV(): KVNamespace {
	const store = new Map< string, string >();
	return {
		async get( key: string, opts?: unknown ): Promise< unknown > {
			const raw = store.get( key ) ?? null;
			if ( opts === 'json' && raw !== null ) {
				return JSON.parse( raw );
			}
			return raw;
		},
		async put( key: string, value: string | object ): Promise< void > {
			store.set(
				key,
				typeof value === 'string' ? value : JSON.stringify( value )
			);
		},
		async delete( key: string ): Promise< void > {
			store.delete( key );
		},
		async list(): Promise< {
			keys: [];
			list_complete: true;
			cursor: string;
			cacheStatus: null;
		} > {
			return {
				keys: [],
				list_complete: true,
				cursor: '',
				cacheStatus: null,
			};
		},
		async getWithMetadata(): Promise< { value: null; metadata: null } > {
			return { value: null, metadata: null };
		},
	} as unknown as KVNamespace;
}

/**
 * Build a test Env object with default stub values.
 *
 * @param {Partial<Env>} overrides Optional overrides for specific bindings.
 * @return {Env} Complete Env fixture.
 */
export function makeEnv( overrides: Partial< Env > = {} ): Env {
	return {
		USAGE_KV: makeKV(),
		ANTHROPIC_API_KEY: 'sk-test',
		OPENAI_API_KEY: 'sk-openai-test',
		GEMINI_API_KEY: 'gemini-test',
		LS_WEBHOOK_SECRET: 'test-secret',
		LS_PRO_MONTHLY_VARIANT_ID: '1550505',
		LS_PRO_ANNUAL_VARIANT_ID: '1550477',
		...overrides,
	} as Env;
}

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

/**
 * Enable every managed provider in the KV `config:models` value, so a test can
 * exercise the native OpenAI/Gemini adapters despite the compiled Claude-only
 * default (#1020).
 *
 * Merges into any `config:models` value the test has already seeded, because a
 * KV put replaces the whole JSON value and would otherwise drop the test's own
 * `tier_models` / `model_token_weight` overrides.
 *
 * TEMPORARY(#1020): only needed while the compiled default is Claude only.
 * On removal: drop the calls if DEFAULT_ENABLED_PROVIDERS is all providers again.
 *
 * @since NEXT_VERSION
 * @param {Env} env Test Env whose USAGE_KV is updated in place.
 * @return {Promise<void>} Resolves once the merged value is stored.
 */
export async function enableAllProviders( env: Env ): Promise< void > {
	const raw = ( await env.USAGE_KV.get( 'config:models' ) ) as string | null;
	const existing = raw ? ( JSON.parse( raw ) as Record< string, unknown > ) : {};
	await env.USAGE_KV.put(
		'config:models',
		JSON.stringify( {
			...existing,
			enabled_providers: [ 'claude', 'openai', 'gemini' ],
		} )
	);
}

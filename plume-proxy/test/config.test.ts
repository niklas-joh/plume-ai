/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import worker from '../src/index';
import { enableAllProviders, makeEnv } from './helpers/kv-mock';
import type { SiteRecord } from '../src/types';

const TEST_TOKEN =
	'1111aaaa2222bbbb3333cccc4444dddd5555eeee6666ffff1111aaaa2222bbbb';

function makeConfigRequest( authHeader?: string ): Request {
	const headers: Record< string, string > = {};
	if ( authHeader !== undefined ) {
		headers.Authorization = authHeader;
	}
	return new Request( 'https://worker.example.com/v1/config', {
		method: 'GET',
		headers,
	} );
}

function makeCtx(): ExecutionContext {
	return {
		waitUntil: () => {},
		passThroughOnException: () => {},
		props: {},
	} as unknown as ExecutionContext;
}

async function seedSite(
	env: ReturnType< typeof makeEnv >,
	overrides: Partial< SiteRecord > = {}
): Promise< SiteRecord > {
	const record: SiteRecord = {
		site_url: 'https://wp.example.com',
		tier: 'pro_managed',
		created_at: Date.now(),
		tier_sync_secret: 'a'.repeat( 64 ),
		...overrides,
	};
	await env.USAGE_KV.put( `site:${ TEST_TOKEN }`, JSON.stringify( record ) );
	return record;
}

describe( '/v1/config', () => {
	it( 'returns 405 for non-GET requests', async () => {
		const env = makeEnv();
		const res = await worker.fetch(
			new Request( 'https://worker.example.com/v1/config', {
				method: 'POST',
			} ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 405 );
	} );

	it( 'returns 401 when Authorization header is missing', async () => {
		const env = makeEnv();
		await seedSite( env );

		const res = await worker.fetch( makeConfigRequest(), env, makeCtx() );
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 401 for an unknown bearer token', async () => {
		const env = makeEnv();
		await seedSite( env );

		const res = await worker.fetch(
			makeConfigRequest( 'Bearer unknown-token' ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns the pro_managed credit limit for a pro_managed site', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'pro_managed' } );

		const res = await worker.fetch(
			makeConfigRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 200 );

		const data = ( await res.json() ) as {
			credit_limit: number | null;
			tier: string;
		};
		expect( data.credit_limit ).toBe( 500 );
		expect( data.tier ).toBe( 'pro_managed' );
	} );

	it( 'returns the free credit limit for a free site', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'free' } );

		const res = await worker.fetch(
			makeConfigRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		const data = ( await res.json() ) as { credit_limit: number | null };
		expect( data.credit_limit ).toBe( 100 );
	} );

	it( 'returns null credit_limit for the unmetered pro_byok tier', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'pro_byok' } );

		const res = await worker.fetch(
			makeConfigRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		const data = ( await res.json() ) as { credit_limit: number | null };
		expect( data.credit_limit ).toBeNull();
	} );

	it( 'never mutates the SiteRecord (read-only, unlike /rotate-secret)', async () => {
		const env = makeEnv();
		const original = await seedSite( env );

		await worker.fetch(
			makeConfigRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);

		const stored = await env.USAGE_KV.get< SiteRecord >(
			`site:${ TEST_TOKEN }`,
			'json'
		);
		expect( stored?.tier_sync_secret ).toBe( original.tier_sync_secret );
	} );
} );

describe( '/v1/config default_models', () => {
	type ConfigBody = {
		credit_limit: number | null;
		tier: string;
		default_models: Record< string, string > | null;
		enabled_providers: string[] | null;
		models: Record< string, string[] > | null;
	};

	async function getConfig(
		env: ReturnType< typeof makeEnv >
	): Promise< ConfigBody > {
		const res = await worker.fetch(
			makeConfigRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 200 );
		return ( await res.json() ) as ConfigBody;
	}

	it( 'reports the native free defaults once every provider is enabled', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'free' } );
		await enableAllProviders( env );

		const data = await getConfig( env );
		expect( data.default_models ).toEqual( {
			claude: 'claude-haiku-4-5-20251001',
			openai: 'gpt-4.1-nano',
			gemini: 'gemini-3.1-flash-lite',
		} );
	} );

	it( 'reports the native pro defaults once every provider is enabled', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'pro_managed' } );
		await enableAllProviders( env );

		const data = await getConfig( env );
		expect( data.default_models ).toEqual( {
			claude: 'claude-sonnet-4-6',
			openai: 'gpt-4.1',
			gemini: 'gemini-3.6-flash',
		} );
	} );

	it( 'falls back to allowed[0] when a KV override drops the default', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'pro_managed' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( {
				enabled_providers: [ 'claude', 'openai', 'gemini' ],
				tier_models: {
					claude: { pro_managed: [ 'claude-opus-4-6' ] },
				},
			} )
		);

		const data = await getConfig( env );
		expect( data.default_models?.claude ).toBe( 'claude-opus-4-6' );
		expect( data.default_models?.openai ).toBe( 'gpt-4.1' );
	} );

	it( 'keeps the explicit default when a KV override reorders the list', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'pro_managed' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( {
				enabled_providers: [ 'claude', 'openai', 'gemini' ],
				tier_models: {
					claude: {
						pro_managed: [
							'claude-opus-4-6',
							'claude-sonnet-4-6',
							'claude-haiku-4-5-20251001',
						],
					},
				},
			} )
		);

		const data = await getConfig( env );
		expect( data.default_models?.claude ).toBe( 'claude-sonnet-4-6' );
	} );

	it( 'does not throw and leaves out a provider whose allow-list is empty', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'free' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( {
				enabled_providers: [ 'claude', 'openai', 'gemini' ],
				tier_models: { openai: { free: [] } },
			} )
		);

		const data = await getConfig( env );
		expect( data.default_models?.gemini ).toBe( 'gemini-3.1-flash-lite' );
		expect( data.enabled_providers ).toEqual( [ 'claude', 'gemini' ] );
		expect( data.models ).not.toHaveProperty( 'openai' );
		expect( data.credit_limit ).toBe( 100 );
	} );

	it( 'does not throw and leaves out a provider whose allow-list is not an array', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'free' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( {
				enabled_providers: [ 'claude', 'openai', 'gemini' ],
				tier_models: { openai: { free: 'not-an-array' } },
			} )
		);

		// getConfig asserts a 200 response for us.
		const data = await getConfig( env );
		expect( data.enabled_providers ).not.toContain( 'openai' );
		expect( data.models ).not.toHaveProperty( 'openai' );
	} );

	it( 'omits every provider and still answers 200 when Claude itself has no models', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'free' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( {
				enabled_providers: [ 'claude' ],
				tier_models: { claude: { free: [] } },
			} )
		);

		const data = await getConfig( env );
		expect( data.default_models ).toEqual( {} );
		expect( data.enabled_providers ).toEqual( [] );
		expect( data.models ).toEqual( {} );
	} );

	it( 'returns null default_models for the pro_byok tier', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'pro_byok' } );

		const data = await getConfig( env );
		expect( data.default_models ).toBeNull();
		expect( data.enabled_providers ).toBeNull();
		expect( data.models ).toBeNull();
		expect( data.credit_limit ).toBeNull();
		expect( data.tier ).toBe( 'pro_byok' );
	} );
} );

describe( '/v1/config enabled_providers and models', () => {
	type ConfigBody = {
		default_models: Record< string, string > | null;
		enabled_providers: string[] | null;
		models: Record< string, string[] > | null;
	};

	async function getConfig(
		env: ReturnType< typeof makeEnv >
	): Promise< ConfigBody > {
		const res = await worker.fetch(
			makeConfigRequest( `Bearer ${ TEST_TOKEN }` ),
			env,
			makeCtx()
		);
		expect( res.status ).toBe( 200 );
		return ( await res.json() ) as ConfigBody;
	}

	it( 'reports only Claude, with the free allow-list, when only Claude is enabled', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'free' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( { enabled_providers: [ 'claude' ] } )
		);

		const data = await getConfig( env );
		expect( data.enabled_providers ).toEqual( [ 'claude' ] );
		expect( data.models ).toEqual( {
			claude: [ 'claude-haiku-4-5-20251001' ],
		} );
	} );

	it( 'reports the pro allow-list for a pro_managed site when only Claude is enabled', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'pro_managed' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( { enabled_providers: [ 'claude' ] } )
		);

		const data = await getConfig( env );
		expect( data.enabled_providers ).toEqual( [ 'claude' ] );
		expect( data.models ).toEqual( {
			claude: [
				'claude-haiku-4-5-20251001',
				'claude-sonnet-4-6',
				'claude-opus-4-6',
			],
		} );
	} );

	it( 'lists a provider and its native default once KV enables it', async () => {
		const env = makeEnv();
		await seedSite( env, { tier: 'free' } );
		await env.USAGE_KV.put(
			'config:models',
			JSON.stringify( { enabled_providers: [ 'gemini' ] } )
		);

		const data = await getConfig( env );
		expect( data.enabled_providers ).toEqual( [ 'claude', 'gemini' ] );
		expect( data.models ).toEqual( {
			claude: [ 'claude-haiku-4-5-20251001' ],
			gemini: [ 'gemini-3.1-flash-lite' ],
		} );
		expect( data.default_models?.gemini ).toBe( 'gemini-3.1-flash-lite' );
	} );

	// TEMPORARY(#1020): substituted defaults. On removal: delete this block;
	// a disabled provider is then simply absent from default_models.
	describe( 'TEMPORARY(#1020) substitution', () => {
		async function claudeOnly( env: ReturnType< typeof makeEnv > ) {
			await env.USAGE_KV.put(
				'config:models',
				JSON.stringify( { enabled_providers: [ 'claude' ] } )
			);
		}

		it( 'reports the Claude substitute as the default of a disabled provider on free', async () => {
			const env = makeEnv();
			await seedSite( env, { tier: 'free' } );
			await claudeOnly( env );

			const data = await getConfig( env );
			expect( data.default_models ).toEqual( {
				claude: 'claude-haiku-4-5-20251001',
				openai: 'claude-haiku-4-5-20251001',
				gemini: 'claude-haiku-4-5-20251001',
			} );
		} );

		it( 'reports Sonnet as the substitute default on pro_managed', async () => {
			const env = makeEnv();
			await seedSite( env, { tier: 'pro_managed' } );
			await claudeOnly( env );

			const data = await getConfig( env );
			expect( data.default_models ).toEqual( {
				claude: 'claude-sonnet-4-6',
				openai: 'claude-sonnet-4-6',
				gemini: 'claude-sonnet-4-6',
			} );
		} );

		it.each( [
			[ 'empty', [] ],
			[ 'not an array', 'not-an-array' ],
		] )(
			'reports the Claude substitute for an enabled provider whose allow-list is %s',
			async ( _label, openaiFree ) => {
				const env = makeEnv();
				await seedSite( env, { tier: 'free' } );
				await env.USAGE_KV.put(
					'config:models',
					JSON.stringify( {
						enabled_providers: [ 'claude', 'openai', 'gemini' ],
						tier_models: { openai: { free: openaiFree } },
					} )
				);

				const data = await getConfig( env );
				expect( data.default_models?.openai ).toBe(
					'claude-haiku-4-5-20251001'
				);
			}
		);
	} );
} );

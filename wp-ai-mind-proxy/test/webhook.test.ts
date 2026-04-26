/// <reference types="@cloudflare/workers-types" />

import { createHmac } from 'crypto';
import { describe, it, expect } from 'vitest';
import { handleWebhook } from '../src/webhook';
import { makeEnv } from './helpers/kv-mock';
import type { SiteRecord, LicenceRecord } from '../src/types';

const TEST_TOKEN = 'aaaa1111bbbb2222cccc3333dddd4444eeee5555ffff6666aaaa1111bbbb2222';
const TEST_LICENCE_KEY = 'LICENCE-TEST-001';

function sign( body: string, secret: string ): string {
	return createHmac( 'sha256', secret ).update( body ).digest( 'hex' );
}

function makeRequest( body: unknown, secret = 'test-secret' ): Request {
	const bodyText = JSON.stringify( body );
	return new Request( 'https://worker.example.com/webhook', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-Signature': sign( bodyText, secret ),
		},
		body: bodyText,
	} );
}

function makeRequestRaw( bodyText: string, signature: string ): Request {
	return new Request( 'https://worker.example.com/webhook', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			'X-Signature': signature,
		},
		body: bodyText,
	} );
}

async function makePrepopulatedEnv( tier: SiteRecord['tier'] = 'free' ) {
	const env = makeEnv();
	const record: SiteRecord = {
		site_url: 'https://example.com',
		tier,
		created_at: Date.now(),
	};
	await env.USAGE_KV.put( `site:${ TEST_TOKEN }`, JSON.stringify( record ) );
	return env;
}

// --- subscription_created payload helpers ---

function subscriptionCreatedPayload( variantId: string, siteToken: string ) {
	return {
		meta: {
			event_name: 'subscription_created',
			custom_data: { site_token: siteToken },
		},
		data: {
			attributes: {
				variant_id: variantId,
				identifier: TEST_LICENCE_KEY,
			},
		},
	};
}

function deactivationPayload( eventName: string, licenceKey: string ) {
	return {
		meta: { event_name: eventName },
		data: {
			attributes: {
				licence_key: licenceKey,
			},
		},
	};
}

function licenceKeyCreatedPayload( key: string, status: string, variantId: string, siteToken: string ) {
	return {
		meta: {
			event_name: 'licence_key_created',
			custom_data: { site_token: siteToken },
		},
		data: {
			attributes: {
				key,
				status,
				variant_id: variantId,
			},
		},
	};
}

describe( 'handleWebhook', () => {
	it( 'returns 405 for a GET request', async () => {
		const env = makeEnv();
		const req = new Request( 'https://worker.example.com/webhook', { method: 'GET' } );
		const res = await handleWebhook( req, env );
		expect( res.status ).toBe( 405 );
	} );

	it( 'returns 401 for wrong HMAC signature', async () => {
		const env = makeEnv();
		const bodyText = JSON.stringify( { meta: { event_name: 'test' } } );
		const req = makeRequestRaw( bodyText, sign( bodyText, 'wrong-secret' ) );
		const res = await handleWebhook( req, env );
		expect( res.status ).toBe( 401 );
	} );

	it( 'returns 400 for invalid JSON body (with valid signature)', async () => {
		const env = makeEnv();
		const bodyText = 'not-json';
		const sig = sign( bodyText, 'test-secret' );
		const req = makeRequestRaw( bodyText, sig );
		const res = await handleWebhook( req, env );
		expect( res.status ).toBe( 400 );
	} );

	it( 'upgrades site tier to pro_managed on subscription_created with a known variant ID', async () => {
		const env = await makePrepopulatedEnv( 'free' );

		// Use the monthly variant ID configured in makeEnv (1550505)
		const payload = subscriptionCreatedPayload( '1550505', TEST_TOKEN );
		const req = makeRequest( payload );
		const res = await handleWebhook( req, env );

		expect( res.status ).toBe( 200 );

		const updated = await env.USAGE_KV.get<SiteRecord>( `site:${ TEST_TOKEN }`, 'json' );
		expect( updated?.tier ).toBe( 'pro_managed' );
	} );

	it.each( [
		[ 'subscription_cancelled' ],
		[ 'subscription_expired' ],
		[ 'subscription_paused' ],
	] )( 'downgrades tier and deletes licence record on %s', async ( eventName ) => {
		const env = await makePrepopulatedEnv( 'pro_managed' );

		// Pre-populate a licence record linking back to the site token
		const licenceRecord: LicenceRecord = {
			tier: 'pro_managed',
			site_token: TEST_TOKEN,
			activated_at: Date.now(),
		};
		await env.USAGE_KV.put( `licence:${ TEST_LICENCE_KEY }`, JSON.stringify( licenceRecord ) );

		const payload = deactivationPayload( eventName, TEST_LICENCE_KEY );
		const req = makeRequest( payload );
		const res = await handleWebhook( req, env );

		expect( res.status ).toBe( 200 );

		const updated = await env.USAGE_KV.get<SiteRecord>( `site:${ TEST_TOKEN }`, 'json' );
		expect( updated?.tier ).toBe( 'free' );

		const deletedLicence = await env.USAGE_KV.get( `licence:${ TEST_LICENCE_KEY }` );
		expect( deletedLicence ).toBeNull();
	} );

	it( 'creates a LicenceRecord in KV on licence_key_created with status=active', async () => {
		const env = await makePrepopulatedEnv( 'free' );

		const payload = licenceKeyCreatedPayload( TEST_LICENCE_KEY, 'active', '1550505', TEST_TOKEN );
		const req = makeRequest( payload );
		const res = await handleWebhook( req, env );

		expect( res.status ).toBe( 200 );

		const record = await env.USAGE_KV.get<LicenceRecord>( `licence:${ TEST_LICENCE_KEY }`, 'json' );
		expect( record ).not.toBeNull();
		expect( record?.site_token ).toBe( TEST_TOKEN );
		expect( record?.tier ).toBe( 'pro_managed' );
	} );

	it( 'returns 200 and makes no KV changes for an unknown event type', async () => {
		const env = await makePrepopulatedEnv( 'free' );

		const payload = {
			meta: { event_name: 'some_unknown_event' },
			data: {},
		};
		const req = makeRequest( payload );
		const res = await handleWebhook( req, env );

		expect( res.status ).toBe( 200 );

		// Tier should be unchanged
		const record = await env.USAGE_KV.get<SiteRecord>( `site:${ TEST_TOKEN }`, 'json' );
		expect( record?.tier ).toBe( 'free' );
	} );

	it( 'returns 200 OK and does not error when site_token is missing from custom_data', async () => {
		const env = makeEnv();

		const payload = {
			meta: {
				event_name: 'subscription_created',
				// no custom_data
			},
			data: {
				attributes: {
					variant_id: '1550505',
				},
			},
		};
		const req = makeRequest( payload );
		const res = await handleWebhook( req, env );

		expect( res.status ).toBe( 200 );
	} );
} );

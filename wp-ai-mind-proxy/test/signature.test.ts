/// <reference types="@cloudflare/workers-types" />

import { createHmac } from 'crypto';
import { describe, it, expect } from 'vitest';
import { verifyLsSignature } from '../src/signature';
import { makeEnv } from './helpers/kv-mock';

function signBody( body: string, secret: string ): string {
	return createHmac( 'sha256', secret ).update( body ).digest( 'hex' );
}

describe( 'verifyLsSignature', () => {
	it( 'resolves true for a valid signature', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: 'test-secret' } );
		const body = '{"event":"test"}';
		const sig = signBody( body, 'test-secret' );
		expect( await verifyLsSignature( body, sig, env ) ).toBe( true );
	} );

	it( 'resolves false for a wrong signature', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: 'test-secret' } );
		const body = '{"event":"test"}';
		const sig = signBody( body, 'wrong-secret' );
		expect( await verifyLsSignature( body, sig, env ) ).toBe( false );
	} );

	it( 'resolves false for an empty signature string', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: 'test-secret' } );
		expect( await verifyLsSignature( '{"event":"test"}', '', env ) ).toBe( false );
	} );

	it( 'resolves false when LS_WEBHOOK_SECRET is empty', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: '' } );
		const body = '{"event":"test"}';
		// Even a correctly formed sig with empty secret should fail importKey (zero-length key)
		const sig = signBody( body, '' );
		expect( await verifyLsSignature( body, sig, env ) ).toBe( false );
	} );

	it( 'resolves false when body is changed after signing', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: 'test-secret' } );
		const originalBody = '{"event":"test"}';
		const sig = signBody( originalBody, 'test-secret' );
		const tamperedBody = '{"event":"tampered"}';
		expect( await verifyLsSignature( tamperedBody, sig, env ) ).toBe( false );
	} );
} );

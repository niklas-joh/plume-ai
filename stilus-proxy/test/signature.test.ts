/// <reference types="@cloudflare/workers-types" />

import { describe, it, expect } from 'vitest';
import { verifyLsSignature } from '../src/signature';
import { makeEnv } from './helpers/kv-mock';
import { signBody } from './helpers/sign';

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
		expect( await verifyLsSignature( '{"event":"test"}', '', env ) ).toBe(
			false
		);
	} );

	it( 'resolves false when LS_WEBHOOK_SECRET is empty', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: '' } );
		const body = '{"event":"test"}';
		// Empty string coerces to falsy — same guard as undefined, different coercion path
		const sig = signBody( body, '' );
		expect( await verifyLsSignature( body, sig, env ) ).toBe( false );
	} );

	it( 'resolves false when secret is undefined', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: undefined as unknown as string } );
		const body = '{"event":"test"}';
		// Any signature — doesn't matter, the guard fires before HMAC computation
		const sig = signBody( body, 'undefined' ); // what would happen without the fix
		expect( await verifyLsSignature( body, sig, env ) ).toBe( false );
	} );

	it( 'resolves false when body is changed after signing', async () => {
		const env = makeEnv( { LS_WEBHOOK_SECRET: 'test-secret' } );
		const originalBody = '{"event":"test"}';
		const sig = signBody( originalBody, 'test-secret' );
		const tamperedBody = '{"event":"tampered"}';
		expect( await verifyLsSignature( tamperedBody, sig, env ) ).toBe(
			false
		);
	} );
} );

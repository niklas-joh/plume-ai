/// <reference types="node" />
/**
 * Permanent URL-contract guard: Worker source ↔ WordPress REST namespace.
 *
 * Verifies that every TypeScript source file in plume-proxy/src/ uses the
 * correct /plume/v1 REST namespace and contains no legacy /wp-ai-mind/
 * references. Run with: npm test -- namespace-contract
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'fs';
import { resolve } from 'path';
import { fileURLToPath } from 'url';

const SRC_DIR = resolve( fileURLToPath( new URL( '../src', import.meta.url ) ) );

function readSrc( filename: string ): string {
	return readFileSync( resolve( SRC_DIR, filename ), 'utf-8' );
}

describe( 'Worker → WordPress REST namespace contract', () => {
	it( 'registration.ts uses /wp-json/plume/v1/activation-verify', () => {
		const source = readSrc( 'registration.ts' );
		expect( source ).toContain( '/wp-json/plume/v1/activation-verify' );
	} );

	it( 'all Worker source files are free of legacy wp-ai-mind namespace', () => {
		const files = readdirSync( SRC_DIR ).filter( f => f.endsWith( '.ts' ) );
		expect( files.length ).toBeGreaterThan( 0 );
		for ( const file of files ) {
			expect(
				readFileSync( resolve( SRC_DIR, file ), 'utf-8' ),
				`${ file } must not contain /wp-json/wp-ai-mind/`
			).not.toContain( '/wp-json/wp-ai-mind/' );
		}
	} );
} );

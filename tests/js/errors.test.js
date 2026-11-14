/**
 * Unit tests for the shared error-message helper.
 *
 * @see src/shared/errors.js
 */
import { getErrorMessage } from '../../src/shared/errors';

describe( 'getErrorMessage', () => {
	it( 'returns the REST `message` field when present', () => {
		expect( getErrorMessage( { message: 'Forbidden.' }, 'Fallback' ) ).toBe(
			'Forbidden.'
		);
	} );

	it( 'returns the `error` field used by the Generator, SEO and Images routes', () => {
		expect(
			getErrorMessage( { error: 'Post not found.' }, 'Fallback' )
		).toBe( 'Post not found.' );
	} );

	it( 'prefers `message` over `error` when both are present', () => {
		expect(
			getErrorMessage( { message: 'A', error: 'B' }, 'Fallback' )
		).toBe( 'A' );
	} );

	it( 'returns the fallback for an empty, missing or non-string value', () => {
		expect( getErrorMessage( null, 'Fallback' ) ).toBe( 'Fallback' );
		expect( getErrorMessage( {}, 'Fallback' ) ).toBe( 'Fallback' );
		expect( getErrorMessage( { message: '' }, 'Fallback' ) ).toBe(
			'Fallback'
		);
		expect( getErrorMessage( { error: { nested: true } }, 'Fallback' ) ).toBe(
			'Fallback'
		);
	} );
} );

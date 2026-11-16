/**
 * Unit tests for GeneratorWizard's error handling.
 *
 * @see src/generator/components/GeneratorWizard.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import GeneratorWizard from '../../src/generator/components/GeneratorWizard';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

/**
 * Types a title and clicks Generate.
 *
 * @param {HTMLElement} container Rendered wizard root.
 */
async function submitBrief( container ) {
	const titleInput = container.querySelector( 'input' );
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	await act( async () => {
		setter.call( titleInput, 'Ten tips for better sleep' );
		titleInput.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	} );
	const generateButton = Array.from(
		container.querySelectorAll( 'button' )
	).find( ( b ) => b.textContent.includes( 'Generate Post' ) );
	await act( async () => {
		generateButton.click();
	} );
}

describe( 'GeneratorWizard — error handling', () => {
	let container;
	let root;

	beforeEach( () => {
		apiFetch.mockReset();
		global.window.plumeData = {
			websiteUrl: 'https://wpaimind.com',
			settingsUrl: '',
		};
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		delete global.window.plumeData;
	} );

	it( 'shows the server error text sent in the `error` field', async () => {
		// The shape /plume/v1/generate really sends on failure.
		apiFetch.mockRejectedValue( {
			error: 'No API key configured for Claude.',
		} );

		await act( async () => {
			root.render( <GeneratorWizard /> );
		} );
		await submitBrief( container );

		expect( container.textContent ).toContain(
			'No API key configured for Claude.'
		);
		expect( container.textContent ).not.toContain(
			'Generation failed. Please try again.'
		);
	} );

	it( 'shows the out-of-credits notice when credits are exhausted', async () => {
		apiFetch.mockRejectedValue( {
			error: 'Monthly usage limit reached.',
			message: 'Monthly usage limit reached.',
			code: 'rate_limit_exceeded',
		} );

		await act( async () => {
			root.render( <GeneratorWizard /> );
		} );
		await submitBrief( container );

		expect(
			container.querySelector( '.plume-out-of-credits-banner' )
		).not.toBeNull();
	} );

	it( 'falls back to a generic message when the server sends none', async () => {
		apiFetch.mockRejectedValue( {} );

		await act( async () => {
			root.render( <GeneratorWizard /> );
		} );
		await submitBrief( container );

		expect( container.textContent ).toContain(
			'Generation failed. Please try again.'
		);
	} );

	it( 'shows one h1 and no inline styles on the brief and generating steps', async () => {
		// Never resolves: leaves the wizard on the generating step.
		apiFetch.mockReturnValue( new Promise( () => {} ) );

		await act( async () => {
			root.render( <GeneratorWizard /> );
		} );
		expect( container.querySelectorAll( 'h1' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '[style]' ) ).toHaveLength( 0 );

		await submitBrief( container );
		expect(
			container.querySelector( '.plume-generator__status' )
		).not.toBeNull();
		expect( container.querySelectorAll( 'h1' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '[style]' ) ).toHaveLength( 0 );
	} );

	it( 'shows one h1 and no inline styles on the done step', async () => {
		apiFetch.mockResolvedValue( {
			post_id: 7,
			edit_url: 'https://example.test/edit',
			content: '<p>Draft</p>',
			credits_used: 3,
		} );

		await act( async () => {
			root.render( <GeneratorWizard /> );
		} );
		await submitBrief( container );

		expect( container.textContent ).toContain( 'Post Generated!' );
		expect( container.querySelectorAll( 'h1' ) ).toHaveLength( 1 );
		expect( container.querySelectorAll( '[style]' ) ).toHaveLength( 0 );
	} );

	it( 'renders a generation error through the Notice component', async () => {
		apiFetch.mockRejectedValue( { error: 'Boom' } );

		await act( async () => {
			root.render( <GeneratorWizard /> );
		} );
		await submitBrief( container );

		expect(
			container.querySelector( '[role="alert"][data-status="error"]' )
				.textContent
		).toBe( 'Boom' );
	} );
} );

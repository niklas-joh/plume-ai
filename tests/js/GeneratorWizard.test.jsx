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
} );

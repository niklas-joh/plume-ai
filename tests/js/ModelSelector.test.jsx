/**
 * Unit tests for ModelSelector — provider/model dropdown in the right panel.
 *
 * Model selection is never tier-gated (WP.org Guideline 5) — the managed
 * proxy enforces each plan's model catalogue service-side, so the Advanced
 * toggle must be usable on every tier.
 *
 * @see src/admin/components/RightPanel/ModelSelector.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import ModelSelector from '../../src/admin/components/RightPanel/ModelSelector';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

const PROVIDERS = [
	{ slug: 'claude', is_available: true, models: { 'claude-sonnet-4-6': 'Sonnet' } },
];

describe( 'ModelSelector', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'enables the Advanced toggle unconditionally', async () => {
		await act( async () => {
			root.render(
				<ModelSelector
					providers={ PROVIDERS }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
				/>
			);
		} );

		const toggle = container.querySelector(
			'.plume-model-advanced-toggle'
		);
		expect( toggle.disabled ).toBe( false );
	} );

	it( 'switches to advanced mode and lists providers when toggled', async () => {
		await act( async () => {
			root.render(
				<ModelSelector
					providers={ PROVIDERS }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
				/>
			);
		} );

		const toggle = container.querySelector(
			'.plume-model-advanced-toggle'
		);
		await act( async () => {
			toggle.click();
		} );

		const providerSelect = container.querySelector(
			'select[aria-label="AI provider"]'
		);
		expect( providerSelect ).not.toBeNull();
		expect( providerSelect.querySelectorAll( 'option' ).length ).toBe(
			PROVIDERS.length
		);
	} );

	it( 'shows a "Saved" hint only when justSaved is true', async () => {
		await act( async () => {
			root.render(
				<ModelSelector
					providers={ PROVIDERS }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
					justSaved={ false }
				/>
			);
		} );
		expect( container.querySelector( '.plume-model-saved' ) ).toBeNull();

		await act( async () => {
			root.render(
				<ModelSelector
					providers={ PROVIDERS }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
					justSaved={ true }
				/>
			);
		} );
		expect(
			container.querySelector( '.plume-model-saved' )
		).not.toBeNull();
	} );
	it( 'renders a single provider and the Worker-provided models when only Claude is listed', async () => {
		// Advanced mode is persisted by earlier tests; pin it rather than toggling.
		window.localStorage.setItem( 'plume_advanced_model', '1' );
		const workerProviders = [
			{
				slug: 'claude',
				is_available: true,
				models: { 'claude-haiku-4-5-20251001': 'Claude Haiku 4.5' },
			},
		];
		await act( async () => {
			root.render(
				<ModelSelector
					providers={ workerProviders }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
				/>
			);
		} );

		const providerSelect = container.querySelector(
			'select[aria-label="AI provider"]'
		);
		expect( providerSelect.querySelectorAll( 'option' ).length ).toBe( 1 );
		const modelSelect = container.querySelector(
			'select[aria-label="Model"]'
		);
		const ids = Array.from( modelSelect.querySelectorAll( 'option' ) ).map(
			( o ) => o.value
		);
		expect( ids ).toContain( 'claude-haiku-4-5-20251001' );
		expect( ids ).not.toContain( 'claude-opus-4-6' );
	} );
} );

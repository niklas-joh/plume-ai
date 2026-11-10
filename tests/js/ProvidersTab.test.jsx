/**
 * Unit tests for ProvidersTab — default provider select and managed-provider notices.
 *
 * @see src/admin/settings/ProvidersTab.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import ProvidersTab from '../../src/admin/settings/ProvidersTab';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

// Real-enough sprintf so the provider name substitution can be asserted.
jest.mock( '@wordpress/i18n', () => ( {
	__: ( str ) => str,
	sprintf: ( fmt, ...args ) =>
		fmt.replace( /%(\d+\$)?s/g, ( match, pos ) =>
			pos ? args[ parseInt( pos, 10 ) - 1 ] : args.shift()
		),
} ) );

// The shared stub does not render per-option `disabled`; this one does.
jest.mock( '@wordpress/components', () => {
	const mockReact = require( 'react' );
	return {
		Button: ( { children, onClick, disabled } ) =>
			mockReact.createElement( 'button', { onClick, disabled }, children ),
		SelectControl: ( { label, value, onChange, options = [] } ) =>
			mockReact.createElement(
				'select',
				{
					'aria-label': label,
					value,
					onChange: ( e ) => onChange( e.target.value ),
				},
				options.map( ( o ) =>
					mockReact.createElement(
						'option',
						{ key: o.value, value: o.value, disabled: o.disabled },
						o.label
					)
				)
			),
		TextControl: ( { label, value, onChange } ) =>
			mockReact.createElement( 'input', {
				'aria-label': label,
				value,
				onChange: ( e ) => onChange( e.target.value ),
			} ),
		Notice: ( { children, status } ) =>
			mockReact.createElement(
				'div',
				{ role: 'status', 'data-status': status },
				children
			),
	};
} );

const NO_KEYS = { claude: false, openai: false, gemini: false };

describe( 'ProvidersTab', () => {
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

	async function renderTab( settings ) {
		await act( async () => {
			root.render(
				<ProvidersTab
					settings={ settings }
					saveSettings={ jest.fn() }
					isSaving={ false }
				/>
			);
		} );
	}

	function option( selectLabel, value ) {
		const select = container.querySelector(
			`select[aria-label="${ selectLabel }"]`
		);
		return select.querySelector( `option[value="${ value }"]` );
	}

	it( 'disables and labels managed-disabled providers that have no own key', async () => {
		await renderTab( {
			default_provider: 'claude',
			managed_providers: [ 'claude' ],
			api_keys: NO_KEYS,
		} );

		const openai = option( 'Default AI Provider', 'openai' );
		const gemini = option( 'Default AI Provider', 'gemini' );
		expect( openai.disabled ).toBe( true );
		expect( openai.textContent ).toBe( 'OpenAI (needs your own API key)' );
		expect( gemini.disabled ).toBe( true );
		expect( gemini.textContent ).toBe( 'Gemini (needs your own API key)' );
		expect( option( 'Default AI Provider', 'claude' ).disabled ).toBe(
			false
		);
		expect( option( 'Default AI Provider', 'ollama' ).disabled ).toBe(
			false
		);
	} );

	it( 'enables a managed-disabled provider once the site has its own key', async () => {
		await renderTab( {
			default_provider: 'claude',
			managed_providers: [ 'claude' ],
			api_keys: { ...NO_KEYS, openai: '••••••' },
		} );

		const openai = option( 'Default AI Provider', 'openai' );
		expect( openai.disabled ).toBe( false );
		expect( openai.textContent ).toBe( 'OpenAI' );
		expect( option( 'Default AI Provider', 'gemini' ).disabled ).toBe(
			true
		);
	} );

	it( 'enables every provider when managed_providers is undefined', async () => {
		await renderTab( { default_provider: 'claude', api_keys: NO_KEYS } );

		[ 'claude', 'openai', 'gemini', 'ollama' ].forEach( ( id ) => {
			expect( option( 'Default AI Provider', id ).disabled ).toBe(
				false
			);
		} );
		expect( container.querySelector( '[role="status"]' ) ).toBeNull();
	} );

	it( 'enables every provider when managed_providers is null (pro_byok)', async () => {
		await renderTab( {
			default_provider: 'openai',
			managed_providers: null,
			api_keys: NO_KEYS,
		} );

		expect( option( 'Default AI Provider', 'openai' ).disabled ).toBe(
			false
		);
		expect( container.querySelector( '[role="status"]' ) ).toBeNull();
	} );

	it( 'leaves the Image Provider select unchanged', async () => {
		await renderTab( {
			default_provider: 'claude',
			managed_providers: [ 'claude' ],
			api_keys: NO_KEYS,
		} );

		const openai = option( 'Image Provider', 'openai' );
		const gemini = option( 'Image Provider', 'gemini' );
		expect( openai.disabled ).toBe( false );
		expect( openai.textContent ).toBe( 'OpenAI (DALL·E)' );
		expect( gemini.disabled ).toBe( false );
		expect( gemini.textContent ).toBe( 'Google Gemini' );
	} );

	// TEMPORARY(#1020): the "Chats use Claude" notice is only true while the Worker
	// substitutes Claude for keyless sites.
	// On removal: delete this describe block with the temporary notice variant.
	describe( 'TEMPORARY(#1020) notice when the site has no own key', () => {
		it( 'tells the user chats use Claude', async () => {
			await renderTab( {
				default_provider: 'openai',
				managed_providers: [ 'claude' ],
				api_keys: NO_KEYS,
			} );

			const notice = container.querySelector( '[role="status"]' );
			expect( notice ).not.toBeNull();
			expect( notice.dataset.status ).toBe( 'info' );
			expect( notice.textContent ).toBe(
				'OpenAI is temporarily unavailable through the Plume service. Chats use Claude until you add your own OpenAI API key below.'
			);
		} );
	} );

	describe( 'notice when the site has an own key for another provider', () => {
		it( 'shows the permanent "not available" text without promising Claude', async () => {
			await renderTab( {
				default_provider: 'openai',
				managed_providers: [ 'claude' ],
				api_keys: { ...NO_KEYS, claude: '••••••' },
			} );

			const notice = container.querySelector( '[role="status"]' );
			expect( notice ).not.toBeNull();
			expect( notice.textContent ).toBe(
				'OpenAI is not available through the Plume service. Add your own OpenAI API key or choose a different provider.'
			);
			expect( notice.textContent ).not.toContain( 'Chats use Claude' );
		} );
	} );

	it( 'shows no notice when the stored default is served by the Plume service', async () => {
		await renderTab( {
			default_provider: 'claude',
			managed_providers: [ 'claude' ],
			api_keys: NO_KEYS,
		} );

		expect( container.querySelector( '[role="status"]' ) ).toBeNull();
	} );
} );

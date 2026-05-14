/**
 * Unit tests for ChatApp — the root chat shell component.
 *
 * @see src/admin/components/Chat/ChatApp.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import ChatApp from '../../src/admin/components/Chat/ChatApp';

// @wordpress/element re-exports React hooks. Mock it to forward to React so
// useState/useEffect/useRef are available in the jsdom environment without
// needing the full WordPress build pipeline.
jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

// lucide-react icons — stub every named export as a no-op span so the component
// can render without importing the full SVG bundle.
jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

// Storage utility used by ChatApp to persist sidebar collapse state.
jest.mock( '../../src/admin/utils/storage', () => ( {
	storageGet: jest.fn( () => null ),
	storageSet: jest.fn(),
} ) );

// Provide the global wpAiMindData the component reads on initialisation
// (ChatApp.jsx line 36).
beforeAll( () => {
	global.window.wpAiMindData = {
		isPro: false,
		defaultProvider: 'claude',
		restUrl: 'http://localhost/wp-json/wp-ai-mind/v1',
		nonce: 'test-nonce',
	};
} );

afterAll( () => {
	delete global.window.wpAiMindData;
} );

describe( 'ChatApp', () => {
	let container;
	let root;

	beforeEach( () => {
		// apiFetch resolves with empty arrays by default so the component does
		// not enter an error state during mount.
		const apiFetch = require( '@wordpress/api-fetch' );
		apiFetch.mockResolvedValue( [] );

		container = document.createElement( 'div' );
		document.body.appendChild( container );
		// React 18 createRoot — avoids the deprecated ReactDOM.render warning
		// that @wordpress/jest-console treats as a test failure.
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'renders without throwing', async () => {
		// The component makes apiFetch calls on mount; wrap in act so React
		// flushes effects and state updates before we make assertions.
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		// .wpaim-shell is the root CSS class (ChatApp.jsx line 297).
		expect( container.querySelector( '.wpaim-shell' ) ).not.toBeNull();
	} );

	it( 'renders the composer input in the DOM', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		// The launch view's Composer always renders .wpaim-composer__input
		// (Composer.jsx line 98 — a <textarea>).
		const input = container.querySelector( '.wpaim-composer__input' );
		expect( input ).not.toBeNull();
		expect( input.tagName.toLowerCase() ).toBe( 'textarea' );
	} );

	it( 'renders the sidebar element', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		// ChatApp.jsx line 301 — <aside className="wpaim-sidebar">.
		expect( container.querySelector( '.wpaim-sidebar' ) ).not.toBeNull();
	} );
} );

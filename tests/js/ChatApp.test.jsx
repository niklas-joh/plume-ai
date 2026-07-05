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

// Provide the global plumeData the component reads on initialisation
// (ChatApp.jsx line 36). Note: this previously stubbed window.plumeindData —
// a typo that never matched the component's actual window.plumeData read, so
// the isPro/features stub below was silently inert until this fix.
beforeAll( () => {
	global.window.plumeData = {
		features: { model_selection: false },
		defaultProvider: 'claude',
		restUrl: 'http://localhost/wp-json/plume/v1',
		nonce: 'test-nonce',
	};
	// jsdom does not implement scrollIntoView; MessageList.jsx calls it whenever
	// the message list re-renders, which only the tests below actually trigger.
	window.HTMLElement.prototype.scrollIntoView = jest.fn();
} );

afterAll( () => {
	delete global.window.plumeData;
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

		// .plume-shell is the root CSS class (ChatApp.jsx line 297).
		expect( container.querySelector( '.plume-shell' ) ).not.toBeNull();
	} );

	it( 'renders the composer input in the DOM', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		// The launch view's Composer always renders .plume-composer__input
		// (Composer.jsx line 98 — a <textarea>).
		const input = container.querySelector( '.plume-composer__input' );
		expect( input ).not.toBeNull();
		expect( input.tagName.toLowerCase() ).toBe( 'textarea' );
	} );

	it( 'renders the sidebar element', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		// ChatApp.jsx line 301 — <aside className="plume-sidebar">.
		expect( container.querySelector( '.plume-sidebar' ) ).not.toBeNull();
	} );

	it( 'renders the full quick-actions list without an isPro split', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		const { QUICK_ACTIONS } = require( '../../src/admin/components/Chat/actions' );
		const buttons = container.querySelectorAll( '.plume-quick-action' );
		expect( buttons.length ).toBe( QUICK_ACTIONS.length );
		expect( container.querySelector( '.plume-pro-teaser' ) ).toBeNull();
	} );

	it( 'disables the model Advanced toggle when features.model_selection is false', async () => {
		await act( async () => {
			root.render( <ChatApp /> );
		} );

		const toggle = container.querySelector(
			'.plume-model-advanced-toggle'
		);
		expect( toggle.disabled ).toBe( true );
	} );

	describe( 'registration-retry on send', () => {
		// Controlled <textarea> — React tracks its own value setter, so a plain
		// `textarea.value = …` is invisible to it. Bypassing via the native
		// prototype setter and dispatching `input` is the standard workaround
		// for driving a React-controlled field without @testing-library.
		function typeAndSubmit( container, text ) {
			const textarea = container.querySelector(
				'.plume-composer__input'
			);
			const setter = Object.getOwnPropertyDescriptor(
				window.HTMLTextAreaElement.prototype,
				'value'
			).set;
			setter.call( textarea, text );
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			textarea.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
		}

		afterEach( () => {
			jest.useRealTimers();
		} );

		it( 'silently retries once on a not_registered error and shows no error bubble when the retry succeeds', async () => {
			jest.useFakeTimers();
			const apiFetch = require( '@wordpress/api-fetch' );
			let messageCalls = 0;
			apiFetch.mockImplementation( ( { path, method } ) => {
				if ( path === '/plume/v1/conversations' && method === 'POST' ) {
					return Promise.resolve( {
						id: 1,
						title: 'New conversation',
					} );
				}
				if (
					path === '/plume/v1/conversations/1/messages' &&
					method === 'POST'
				) {
					messageCalls += 1;
					if ( 1 === messageCalls ) {
						return Promise.reject( {
							code: 'not_registered',
							message: 'Connecting…',
						} );
					}
					return Promise.resolve( {
						content: 'Hello back',
						model: 'claude',
						credits: 1,
					} );
				}
				return Promise.resolve( [] );
			} );

			await act( async () => {
				root.render( <ChatApp /> );
			} );

			await act( async () => {
				typeAndSubmit( container, 'Hi there' );
			} );

			// The first attempt has failed and the 3s retry timer is pending —
			// advance it and let the retried apiFetch promise settle.
			await act( async () => {
				jest.advanceTimersByTime( 3000 );
				await Promise.resolve();
				await Promise.resolve();
			} );

			expect( messageCalls ).toBe( 2 );
			expect(
				container.querySelector( '.plume-bubble--error' )
			).toBeNull();
			expect( container.textContent ).toContain( 'Hello back' );
		} );

		it( 'shows exactly one error bubble when the retry also fails, without retrying a second time', async () => {
			jest.useFakeTimers();
			const apiFetch = require( '@wordpress/api-fetch' );
			let messageCalls = 0;
			apiFetch.mockImplementation( ( { path, method } ) => {
				if ( path === '/plume/v1/conversations' && method === 'POST' ) {
					return Promise.resolve( {
						id: 1,
						title: 'New conversation',
					} );
				}
				if (
					path === '/plume/v1/conversations/1/messages' &&
					method === 'POST'
				) {
					messageCalls += 1;
					return Promise.reject( {
						code: 'not_registered',
						message: 'Connecting this site to Plume AI.',
					} );
				}
				return Promise.resolve( [] );
			} );

			await act( async () => {
				root.render( <ChatApp /> );
			} );

			await act( async () => {
				typeAndSubmit( container, 'Hi there' );
			} );

			await act( async () => {
				jest.advanceTimersByTime( 3000 );
				await Promise.resolve();
				await Promise.resolve();
			} );

			expect( messageCalls ).toBe( 2 );
			expect(
				container.querySelectorAll( '.plume-bubble--error' ).length
			).toBe( 1 );
		} );
	} );
} );

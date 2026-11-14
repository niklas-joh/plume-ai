/**
 * Unit tests for MessageBubble's structured revision-request rendering.
 *
 * @see src/admin/components/Chat/MessageBubble.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import MessageBubble from '../../src/admin/components/Chat/MessageBubble';
import { buildRevisionMessage } from '../../src/admin/components/Chat/revisionMessage';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'MessageBubble revision request', () => {
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

	function render( content ) {
		act( () => {
			root.render( <MessageBubble message={ { role: 'user', content } } /> );
		} );
	}

	it( 'shows each comment under its quoted anchor, plus the note', () => {
		render(
			buildRevisionMessage(
				[ { selectedText: 'Opening line', text: 'Make it punchier' } ],
				'Keep it short'
			)
		);
		const item = container.querySelector( 'ul > li' );
		expect( item.querySelector( 'blockquote' ).textContent ).toBe(
			'Opening line'
		);
		expect( item.textContent ).toContain( 'Make it punchier' );
		expect( container.textContent ).toContain( 'Keep it short' );
	} );

	it( 'renders markup in comments as text, not HTML', () => {
		render(
			buildRevisionMessage( [ { selectedText: 'a', text: '<b>x</b>' } ], '' )
		);
		expect( container.querySelector( 'b' ) ).toBeNull();
		expect( container.textContent ).toContain( '<b>x</b>' );
	} );

	it( 'leaves ordinary user messages as plain text', () => {
		render( 'Hello there' );
		expect( container.querySelector( 'ul' ) ).toBeNull();
		expect( container.querySelector( 'p' ).textContent ).toBe(
			'Hello there'
		);
	} );
} );

describe( 'MessageBubble actions', () => {
	let container;
	let root;

	beforeEach( () => {
		jest.useFakeTimers();
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
		jest.useRealTimers();
		delete navigator.clipboard;
	} );

	const button = ( label ) =>
		Array.from( container.querySelectorAll( 'button' ) ).find( ( b ) =>
			b.textContent.includes( label )
		);

	it( 'copies a reply as its original Markdown', async () => {
		const writeText = jest.fn( () => Promise.resolve() );
		Object.defineProperty( navigator, 'clipboard', {
			configurable: true,
			value: { writeText },
		} );
		act( () => {
			root.render(
				<MessageBubble
					message={ { role: 'assistant', content: '**Bold** idea' } }
				/>
			);
		} );

		await act( async () => {
			button( 'Copy' ).click();
		} );

		expect( writeText ).toHaveBeenCalledWith( '**Bold** idea' );
		expect( button( 'Copied' ) ).toBeDefined();
	} );

	it( 'offers no Copy button where the clipboard is unavailable', () => {
		// navigator.clipboard is undefined on plain-HTTP admin sites.
		act( () => {
			root.render(
				<MessageBubble
					message={ { role: 'assistant', content: 'Reply' } }
				/>
			);
		} );

		expect( button( 'Copy' ) ).toBeUndefined();
	} );

	it( 'offers Retry on a failed message when a retry handler is given', () => {
		const onRetry = jest.fn();
		act( () => {
			root.render(
				<MessageBubble
					message={ {
						role: 'assistant',
						content: 'Upstream unavailable',
						isError: true,
					} }
					onRetry={ onRetry }
				/>
			);
		} );

		act( () => {
			button( 'Try again' ).click();
		} );

		expect( onRetry ).toHaveBeenCalled();
	} );

	it( 'offers no Retry without a handler', () => {
		act( () => {
			root.render(
				<MessageBubble
					message={ {
						role: 'assistant',
						content: 'Upstream unavailable',
						isError: true,
					} }
				/>
			);
		} );

		expect( button( 'Try again' ) ).toBeUndefined();
	} );
} );

describe( 'MessageBubble post plan', () => {
	let container;
	let root;

	const createPlan = {
		id: 'abc123',
		plan_type: 'create',
		title: 'Spring herbs',
		outline: 'Basil, mint, chives.',
		post_status: 'draft',
		post_type: 'post',
	};

	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( {} );
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

	it( 'tells the server when a plan is dismissed, so it does not come back on reload', async () => {
		act( () => {
			root.render(
				<MessageBubble
					message={ {
						role: 'assistant',
						content: 'Here is a plan.',
						pending_plan: createPlan,
					} }
				/>
			);
		} );

		await act( async () => {
			container
				.querySelector( '.plume-plan-card__dismiss' )
				.click();
		} );

		expect( container.querySelector( '.plume-plan-card' ) ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/plume/v1/plans/abc123',
			method: 'DELETE',
		} );
	} );

	it( 'still hides a dismissed plan when the server call fails', async () => {
		apiFetch.mockRejectedValue( { message: 'offline' } );
		act( () => {
			root.render(
				<MessageBubble
					message={ {
						role: 'assistant',
						content: 'Here is a plan.',
						pending_plan: createPlan,
					} }
				/>
			);
		} );

		await act( async () => {
			container
				.querySelector( '.plume-plan-card__dismiss' )
				.click();
		} );

		expect( container.querySelector( '.plume-plan-card' ) ).toBeNull();
	} );

	it( 'shows a plan that arrives after the message was first rendered', () => {
		act( () => {
			root.render(
				<MessageBubble
					message={ { role: 'assistant', content: 'Here is a plan.' } }
				/>
			);
		} );
		expect( container.querySelector( '.plume-plan-card' ) ).toBeNull();

		act( () => {
			root.render(
				<MessageBubble
					message={ {
						role: 'assistant',
						content: 'Here is a plan.',
						pending_plan: createPlan,
					} }
				/>
			);
		} );

		expect( container.querySelector( '.plume-plan-card' ) ).not.toBeNull();
	} );
} );


/**
 * Unit tests for BlockActions — AI actions on the selected block in the editor sidebar.
 *
 * @see src/editor/components/BlockActions.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import apiFetch from '@wordpress/api-fetch';
import BlockActions from '../../src/editor/components/BlockActions';

jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

const mockSelectedBlock = { current: null };
const mockUpdateBlockAttributes = jest.fn();
const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();

jest.mock(
	'@wordpress/data',
	() => ( {
		useSelect: ( callback ) =>
			callback( () => ( {
				getSelectedBlock: () => mockSelectedBlock.current,
			} ) ),
		useDispatch: ( store ) =>
			store === 'core/notices'
				? {
						createSuccessNotice: mockCreateSuccessNotice,
						createErrorNotice: mockCreateErrorNotice,
				  }
				: { updateBlockAttributes: mockUpdateBlockAttributes },
	} ),
	{ virtual: true }
);

/**
 * Routes the mocked apiFetch by path and method.
 *
 * @param {Function} onMessage Returns the promise for the chat request.
 */
function mockApi( onMessage ) {
	apiFetch.mockImplementation( ( { path, method } ) => {
		if ( path === '/plume/v1/conversations' && method === 'POST' ) {
			return Promise.resolve( { id: 9 } );
		}
		if ( path === '/plume/v1/conversations/9/messages' ) {
			return onMessage();
		}
		return Promise.resolve( {} );
	} );
}

const callsTo = ( path, method ) =>
	apiFetch.mock.calls.filter(
		( [ args ] ) => args.path === path && args.method === method
	);

describe( 'BlockActions', () => {
	let container;
	let root;

	beforeEach( () => {
		apiFetch.mockReset();
		mockUpdateBlockAttributes.mockReset();
		mockCreateSuccessNotice.mockReset();
		mockCreateErrorNotice.mockReset();
		mockSelectedBlock.current = {
			clientId: 'block-1',
			attributes: { content: 'Old text' },
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
	} );

	const buttons = () => Array.from( container.querySelectorAll( 'button' ) );

	it( 'shows that an action is running and ignores further clicks until it ends', async () => {
		let finish;
		mockApi(
			() =>
				new Promise( ( resolve ) => {
					finish = resolve;
				} )
		);
		await act( async () => {
			root.render( <BlockActions /> );
		} );

		await act( async () => {
			buttons()[ 0 ].click();
		} );

		expect( buttons()[ 0 ].getAttribute( 'aria-busy' ) ).toBe( 'true' );
		buttons().forEach( ( b ) =>
			expect( b.getAttribute( 'aria-disabled' ) ).toBe( 'true' )
		);

		await act( async () => {
			buttons()[ 1 ].click();
		} );
		expect( callsTo( '/plume/v1/conversations', 'POST' ).length ).toBe( 1 );

		await act( async () => {
			finish( { content: 'New text' } );
		} );
		expect( buttons()[ 0 ].getAttribute( 'aria-busy' ) ).toBe( 'false' );
	} );

	it( 'replaces the block text and offers an undo that restores it', async () => {
		mockApi( () => Promise.resolve( { content: 'New text' } ) );
		await act( async () => {
			root.render( <BlockActions /> );
		} );

		await act( async () => {
			buttons()[ 0 ].click();
		} );

		expect( mockUpdateBlockAttributes ).toHaveBeenCalledWith( 'block-1', {
			content: 'New text',
		} );
		const [ , options ] = mockCreateSuccessNotice.mock.calls[ 0 ];
		expect( options.type ).toBe( 'snackbar' );

		options.actions[ 0 ].onClick();
		expect( mockUpdateBlockAttributes ).toHaveBeenLastCalledWith(
			'block-1',
			{ content: 'Old text' }
		);
	} );

	it( 'writes back to `value` for blocks that keep their text there', async () => {
		mockSelectedBlock.current = {
			clientId: 'block-2',
			attributes: { value: 'Old quote' },
		};
		mockApi( () => Promise.resolve( { content: 'New quote' } ) );
		await act( async () => {
			root.render( <BlockActions /> );
		} );

		await act( async () => {
			buttons()[ 0 ].click();
		} );

		expect( mockUpdateBlockAttributes ).toHaveBeenCalledWith( 'block-2', {
			value: 'New quote',
		} );
	} );

	it( 'reports a failure in the editor and leaves the block untouched', async () => {
		mockApi( () =>
			Promise.reject( { message: 'Monthly usage limit reached.' } )
		);
		await act( async () => {
			root.render( <BlockActions /> );
		} );

		await act( async () => {
			buttons()[ 0 ].click();
		} );

		expect( mockUpdateBlockAttributes ).not.toHaveBeenCalled();
		expect( mockCreateErrorNotice.mock.calls[ 0 ][ 0 ] ).toBe(
			'Monthly usage limit reached.'
		);
		expect( buttons()[ 0 ].getAttribute( 'aria-disabled' ) ).toBe( 'false' );
	} );

	it( 'tells the model to return only the new text', async () => {
		mockApi( () => Promise.resolve( { content: 'New text' } ) );
		await act( async () => {
			root.render( <BlockActions /> );
		} );

		await act( async () => {
			buttons()[ 0 ].click();
		} );

		const [ [ request ] ] = callsTo(
			'/plume/v1/conversations/9/messages',
			'POST'
		);
		expect( request.data.content ).toMatch( /only the/i );
		expect( request.data.content ).toMatch( /Markdown/ );
		expect( request.data.content ).toContain( 'Old text' );
	} );

	it( 'deletes its throwaway conversation, whether the action worked or not', async () => {
		mockApi( () => Promise.resolve( { content: 'New text' } ) );
		await act( async () => {
			root.render( <BlockActions /> );
		} );
		await act( async () => {
			buttons()[ 0 ].click();
		} );
		expect( callsTo( '/plume/v1/conversations/9', 'DELETE' ).length ).toBe(
			1
		);

		apiFetch.mockClear();
		mockApi( () => Promise.reject( { message: 'Nope.' } ) );
		await act( async () => {
			buttons()[ 0 ].click();
		} );
		expect( callsTo( '/plume/v1/conversations/9', 'DELETE' ).length ).toBe(
			1
		);
	} );
} );


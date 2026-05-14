/**
 * Unit tests for PostListTable — the shared tabbed post list component.
 *
 * @see src/shared/PostListTable.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import PostListTable from '../../src/shared/PostListTable';

// @wordpress/element re-exports React hooks. Mock it to forward to React so
// useState/useEffect are available in the jsdom environment without needing the
// full WordPress build pipeline.
jest.mock( '@wordpress/element', () => ( {
	...jest.requireActual( 'react' ),
} ) );

// DOMPurify is used to sanitise post title HTML in PostRow (PostListTable.jsx
// line 267). In jsdom there is no real DOM sanitiser, so return the input as-is.
jest.mock( 'dompurify', () => ( {
	sanitize: ( html ) => html,
} ) );

/** Minimal WorkArea stub used as the expandable row component. */
const StubWorkArea = () => <div className="stub-work-area" />;

/** Tab definitions that mirror the real SEO_TABS shape. */
const STUB_TABS = [
	{ id: 'all', label: 'All Posts', filter: () => true },
	{ id: 'published', label: 'Published', filter: ( p ) => p.status === 'publish' },
];

/** Sample posts returned by the mocked apiFetch. */
const FIXTURE_POSTS = [
	{
		id: 1,
		title: { rendered: 'Hello World' },
		type: 'post',
		status: 'publish',
		modified: new Date().toISOString(),
		wpaim_seo_status: null,
	},
	{
		id: 2,
		title: { rendered: 'About Page' },
		type: 'page',
		status: 'publish',
		modified: new Date().toISOString(),
		wpaim_seo_status: null,
	},
];

describe( 'PostListTable', () => {
	let container;
	let root;

	beforeEach( () => {
		// PostListTable fetches /wp/v2/posts and /wp/v2/pages using apiFetch with
		// parse:false, so the mock must return a Response-like object that exposes
		// .json() and .headers.get() (PostListTable.jsx lines 45–53).
		const makeResponse = ( data ) => ( {
			json: () => Promise.resolve( data ),
			headers: { get: () => '1' }, // X-WP-TotalPages = 1
		} );

		const apiFetch = require( '@wordpress/api-fetch' );
		apiFetch.mockImplementation( ( { path } ) => {
			if ( path && path.includes( '/wp/v2/posts' ) ) {
				return Promise.resolve( makeResponse( FIXTURE_POSTS.filter( ( p ) => p.type === 'post' ) ) );
			}
			if ( path && path.includes( '/wp/v2/pages' ) ) {
				return Promise.resolve( makeResponse( FIXTURE_POSTS.filter( ( p ) => p.type === 'page' ) ) );
			}
			return Promise.resolve( makeResponse( [] ) );
		} );

		container = document.createElement( 'div' );
		document.body.appendChild( container );
		// React 18 createRoot — avoids the deprecated ReactDOM.render warning.
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'renders a table element after posts load', async () => {
		await act( async () => {
			root.render( <PostListTable tabs={ STUB_TABS } WorkArea={ StubWorkArea } /> );
		} );

		// PostListTable renders .wpaim-list-loading while fetching (line 125).
		// After the mocked fetch resolves, it renders a <table> (line 156).
		const table = container.querySelector( 'table.wp-list-table' );
		expect( table ).not.toBeNull();
	} );

	it( 'renders a tab button for each entry in the tabs prop', async () => {
		await act( async () => {
			root.render( <PostListTable tabs={ STUB_TABS } WorkArea={ StubWorkArea } /> );
		} );

		// Tab buttons are rendered in .wpaim-list-tabs (PostListTable.jsx line 134).
		const tabButtons = container.querySelectorAll( '.wpaim-list-tabs .wpaim-tab' );
		expect( tabButtons.length ).toBe( STUB_TABS.length );

		const labels = Array.from( tabButtons ).map( ( btn ) => btn.textContent );
		expect( labels ).toContain( 'All Posts' );
		expect( labels ).toContain( 'Published' );
	} );

	it( 'renders a row for each post after data loads', async () => {
		await act( async () => {
			root.render( <PostListTable tabs={ STUB_TABS } WorkArea={ StubWorkArea } /> );
		} );

		// Each post becomes a <tr> inside <tbody> (PostListTable.jsx line 188).
		// The fixture has 2 posts (1 post + 1 page), merged in fetchAll.
		const rows = container.querySelectorAll( 'tbody tr' );
		expect( rows.length ).toBeGreaterThanOrEqual( FIXTURE_POSTS.length );
	} );

	it( 'renders the search input', async () => {
		await act( async () => {
			root.render( <PostListTable tabs={ STUB_TABS } WorkArea={ StubWorkArea } /> );
		} );

		// .wpaim-list-search is the <input type="search"> (PostListTable.jsx line 147).
		const searchInput = container.querySelector( '.wpaim-list-search' );
		expect( searchInput ).not.toBeNull();
		expect( searchInput.getAttribute( 'type' ) ).toBe( 'search' );
	} );
} );

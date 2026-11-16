/**
 * Unit tests for the shared screen shell: PageHeader, Screen and PlumeLogo.
 *
 * @see src/shared/PageHeader.jsx
 * @see src/shared/Screen.jsx
 * @see src/shared/PlumeLogo.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import fs from 'fs';
import path from 'path';
import PageHeader from '../../src/shared/PageHeader';
import PlumeLogo from '../../src/shared/PlumeLogo';
import Screen from '../../src/shared/Screen';

describe( 'shared screen shell', () => {
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

	/**
	 * Renders an element into the test container.
	 *
	 * @param {ReactElement} element Element to render.
	 */
	async function render( element ) {
		await act( async () => {
			root.render( element );
		} );
	}

	describe( 'PageHeader', () => {
		it( 'renders exactly one h1 containing only the screen name', async () => {
			await render(
				<PageHeader title="SEO" description="One line about SEO." />
			);

			const headings = container.querySelectorAll( 'h1' );
			expect( headings ).toHaveLength( 1 );
			expect( headings[ 0 ].textContent ).toBe( 'SEO' );
		} );

		it( 'keeps the Plume brand text outside the h1', async () => {
			await render( <PageHeader title="SEO" /> );

			const brand = container.querySelector(
				'.plume-page-header__brand'
			);
			expect( brand.textContent ).toBe( 'Plume' );
			expect( brand.closest( 'h1' ) ).toBeNull();
		} );

		it( 'hides the decorative logo from assistive technology', async () => {
			await render( <PageHeader title="SEO" /> );

			expect(
				container
					.querySelector( '.plume-logo' )
					.getAttribute( 'aria-hidden' )
			).toBe( 'true' );
		} );

		it( 'renders the description and actions only when given', async () => {
			await render( <PageHeader title="SEO" /> );
			expect(
				container.querySelector( '.plume-page-header__description' )
			).toBeNull();
			expect(
				container.querySelector( '.plume-page-header__actions' )
			).toBeNull();

			await render(
				<PageHeader
					title="SEO"
					description="Describe."
					actions={ <button type="button">Act</button> }
				/>
			);
			expect(
				container.querySelector( '.plume-page-header__description' )
					.textContent
			).toBe( 'Describe.' );
			expect(
				container.querySelector( '.plume-page-header__actions button' )
					.textContent
			).toBe( 'Act' );
		} );

		it( 'adds the compact modifier', async () => {
			await render( <PageHeader title="Chat" compact /> );

			expect(
				container.querySelector( '.plume-page-header--compact' )
			).not.toBeNull();
		} );
	} );

	describe( 'Screen', () => {
		it( 'renders the header above the body in a plume-screen wrapper', async () => {
			await render(
				<Screen header={ <PageHeader title="Usage" /> }>
					<p id="body-content">Body</p>
				</Screen>
			);

			const screen = container.querySelector( '.plume-screen' );
			expect( screen.classList.contains( 'plume-screen--page' ) ).toBe(
				true
			);
			expect( screen.firstElementChild.tagName ).toBe( 'HEADER' );
			expect(
				screen.querySelector( '.plume-screen__body #body-content' )
			).not.toBeNull();
		} );

		it( 'supports the app layout and extra class names', async () => {
			await render(
				<Screen
					layout="app"
					className="plume-page"
					header={ <PageHeader title="Chat" /> }
				>
					x
				</Screen>
			);

			const screen = container.querySelector( '.plume-screen' );
			expect( screen.classList.contains( 'plume-screen--app' ) ).toBe(
				true
			);
			expect( screen.classList.contains( 'plume-page' ) ).toBe( true );
		} );
	} );

	describe( 'PlumeLogo', () => {
		it( 'mono variant is flat: one path, no gradients, currentColor', async () => {
			await render( <PlumeLogo mono /> );

			const svg = container.querySelector( 'svg' );
			expect( svg.querySelectorAll( 'linearGradient' ) ).toHaveLength(
				0
			);
			expect( svg.querySelectorAll( 'path' ) ).toHaveLength( 1 );
			expect( svg.getAttribute( 'fill' ) ).toBe( 'currentColor' );
			expect( svg.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		} );

		it( 'colour variant gives every instance unique gradient ids', async () => {
			await render(
				<>
					<PlumeLogo />
					<PlumeLogo />
				</>
			);

			const ids = Array.from(
				container.querySelectorAll( 'linearGradient' )
			).map( ( g ) => g.getAttribute( 'id' ) );
			expect( ids ).toHaveLength( 4 );
			expect( new Set( ids ).size ).toBe( 4 );
			// Every fill url(#id) must point at an id that exists.
			container.querySelectorAll( 'path' ).forEach( ( p ) => {
				const ref = p.getAttribute( 'fill' ).match( /#([^)]+)/ )[ 1 ];
				expect( ids ).toContain( ref );
			} );
		} );
	} );

	describe( 'reduced motion', () => {
		const read = ( file ) =>
			fs.readFileSync(
				path.resolve( __dirname, '../../src/styles', file ),
				'utf8'
			);

		it( 'zeroes the motion tokens once on :root', () => {
			const css = read( 'tokens.css' );
			const block = css.match(
				/@media \(prefers-reduced-motion: reduce\) \{([\s\S]*?)\n\}/
			);
			expect( block ).not.toBeNull();
			expect( block[ 1 ] ).toMatch( /:root/ );
			expect( block[ 1 ] ).toMatch( /--plume-duration-fast:\s*0ms/ );
			expect( block[ 1 ] ).toMatch( /--plume-duration:\s*0ms/ );
		} );

		it( 'slows rather than stops the spinner', () => {
			const css = read( 'animations.css' );
			expect( css ).toMatch( /\.plume-spin\s*\{/ );
			expect( css ).toMatch(
				/prefers-reduced-motion: reduce[\s\S]*animation-duration:\s*2s/
			);
		} );
	} );
} );

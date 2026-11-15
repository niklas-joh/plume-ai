/**
 * Determinism and isolation helpers for the PR screenshot capture.
 *
 * `prepare()` installs, before the first navigation:
 *  - a fixed clock (Date only; timers keep running so React and apiFetch work),
 *  - `plumeData` / `plumeDashboard` overrides (see SERVER_STATE in fixtures.js),
 *  - a freeze stylesheet (no motion, no environment-driven notices),
 *  - a default-deny network router.
 *
 * `shoot()` waits for fonts and images, removes focus rings, fails with a
 * named message if any Plume route was left unmocked, and writes the PNG.
 */

'use strict';

const path = require( 'path' );
const { expect } = require( '@playwright/test' );
const { FIXED_NOW, PLUME_DATA, DASHBOARD } = require( './fixtures' );

const OUT = path.resolve( __dirname, '../../../../.artifacts/screenshots/ci' );

const FREEZE_CSS = `
*, *::before, *::after {
	transition: none !important;
	animation: none !important;
	caret-color: transparent !important;
}
/* Environment-driven notices (core nags; Plume's site-level
   SiteUnreachable/TierSyncBackfill/Activation notices, whose state depends on
   whether wp-env reached the Worker). Inline notices inside a screen stay. */
#wpbody-content .notice:not(.inline),
#wpbody-content .update-nag,
#wpbody-content > .updated,
#wpbody-content > .error,
#wp-admin-bar-updates,
#adminmenu .update-plugins {
	display: none !important;
}
`;

/**
 * Returns the REST route of a request URL, or null for non-REST requests.
 *
 * Handles both pretty (`/wp-json/plume/v1/x`) and plain (`?rest_route=/plume/v1/x`)
 * permalinks, so the router does not depend on the permalink setting.
 *
 * @param {URL} url Request URL.
 * @return {?string} Route such as `/plume/v1/conversations`, without query string.
 */
function restRoute( url ) {
	const at = url.pathname.indexOf( '/wp-json/' );
	if ( at !== -1 ) {
		return url.pathname
			.slice( at + '/wp-json'.length )
			.replace( /\/$/, '' );
	}
	const param = url.searchParams.get( 'rest_route' );
	return param ? param.replace( /\/$/, '' ) : null;
}

/**
 * Builds a JSON response descriptor for a mock handler.
 *
 * @param {number} status  HTTP status.
 * @param {unknown} body    JSON-serialisable body.
 * @param {Object} headers Extra response headers.
 * @return {{status: number, body: string, headers: Object}} Response descriptor.
 */
function json( status, body, headers = {} ) {
	return {
		status,
		body: JSON.stringify( body ),
		headers: { 'content-type': 'application/json', ...headers },
	};
}

/**
 * Prepares a fresh page for a deterministic, offline-safe capture.
 *
 * Default-deny is the safety guarantee: any cross-origin request is aborted,
 * and any `plume/v1` request without a matching fixture gets a 599 and is
 * recorded, which makes `shoot()` fail with a named message. No Plume REST
 * call ever reaches PHP, so the capture cannot trigger an AI provider or
 * Worker call server-side.
 *
 * @param {import('@playwright/test').Page} page
 * @param {Object}  opts
 * @param {string}  opts.baseURL      Site origin; every other origin is aborted.
 * @param {Array<{method: string, route: RegExp, reply: Object}>} [opts.handlers] Plume
 *        mocks; `route` is matched against the part after `/plume/v1`.
 * @param {Object}  [opts.plumeData]  Extra `plumeData` overrides for this screen.
 * @param {?Object} [opts.postLists]  `{ posts, pages }` to serve for the shared post list.
 * @return {Promise<{seen: string[], violations: string[], aborted: string[]}>} Live
 *         request log used by `shoot()` and by screen-specific waits.
 */
async function prepare(
	page,
	{ baseURL, handlers = [], plumeData = {}, postLists = null }
) {
	const log = { seen: [], violations: [], aborted: [] };
	const origin = new URL( baseURL ).origin;

	await page.clock.setFixedTime( new Date( FIXED_NOW ) );

	await page.addInitScript(
		( { data, dashboard, css } ) => {
			// Getter/setter proxy (as in journeys/tier-gating.spec.js): PHP's
			// inline `var plumeData = …` still supplies restUrl and nonce.
			const proxy = ( name, extra ) => {
				let value;
				Object.defineProperty( window, name, {
					get() {
						return value;
					},
					set( next ) {
						value = { ...next, ...extra };
					},
					configurable: false,
				} );
			};
			proxy( 'plumeData', data );
			proxy( 'plumeDashboard', dashboard );

			const addStyle = () => {
				const style = document.createElement( 'style' );
				style.setAttribute( 'data-screens-freeze', '' );
				style.textContent = css;
				document.head.appendChild( style );
			};
			if ( document.head ) {
				addStyle();
			} else {
				document.addEventListener( 'DOMContentLoaded', addStyle );
			}
		},
		{
			data: { ...PLUME_DATA, ...plumeData },
			dashboard: DASHBOARD,
			css: FREEZE_CSS,
		}
	);

	await page.route( '**/*', async ( route ) => {
		const request = route.request();
		const url = new URL( request.url() );

		if ( url.origin !== origin ) {
			log.aborted.push( url.origin );
			return route.abort( 'blockedbyclient' );
		}

		const rest = restRoute( url );
		if ( ! rest ) {
			return route.continue();
		}

		// apiFetch's http-v1 middleware sends PATCH/PUT/DELETE as POST.
		const method = (
			request.headers()[ 'x-http-method-override' ] || request.method()
		).toUpperCase();

		if ( rest.startsWith( '/plume/v1/' ) ) {
			const sub = rest.slice( '/plume/v1'.length );
			const key = `${ method } /plume/v1${ sub }`;
			log.seen.push( key );
			const handler = handlers.find(
				( h ) => h.method === method && h.route.test( sub )
			);
			if ( handler ) {
				return route.fulfill( handler.reply );
			}
			log.violations.push( key );
			return route.fulfill(
				json( 599, {
					code: 'unmocked_route',
					message: `Screenshot capture: no fixture for ${ key }`,
				} )
			);
		}

		if (
			postLists &&
			method === 'GET' &&
			/^\/wp\/v2\/(posts|pages)$/.test( rest )
		) {
			const items = rest.endsWith( 'posts' )
				? postLists.posts
				: postLists.pages;
			return route.fulfill(
				json( 200, items, {
					'x-wp-total': String( items.length ),
					'x-wp-totalpages': '1',
				} )
			);
		}

		return route.continue();
	} );

	return log;
}

/**
 * Waits until the page is visually settled and writes the screenshot.
 *
 * @param {import('@playwright/test').Page}     page
 * @param {import('@playwright/test').TestInfo} testInfo
 * @param {Object}  log                Request log returned by `prepare()`.
 * @param {Object}  opts
 * @param {string}  opts.id            Screen id from screens.json (file name).
 * @param {boolean} [opts.fullPage]    Whole document (true) or the viewport only.
 * @param {boolean} [opts.networkIdle] Wait for network idle first (off for the block editor, which rarely idles).
 * @return {Promise<void>}
 */
async function shoot(
	page,
	testInfo,
	log,
	{ id, fullPage = true, networkIdle = true }
) {
	if ( networkIdle ) {
		await page.waitForLoadState( 'networkidle' );
	}
	await page.evaluate( async () => {
		await document.fonts.ready;
		await Promise.all(
			Array.from( document.images )
				.filter( ( img ) => ! img.complete )
				.map(
					( img ) =>
						new Promise( ( resolve ) => {
							img.addEventListener( 'load', resolve );
							img.addEventListener( 'error', resolve );
						} )
				)
		);
		// Runs in the page, where there is no node ref to read ownerDocument from.
		// eslint-disable-next-line @wordpress/no-global-active-element
		document.activeElement?.blur?.();
		// Clicks and focus scroll the document; reset so every shot starts at
		// the top (inner app scrollers, e.g. the chat thread, are left alone).
		window.scrollTo( 0, 0 );
	} );

	expect(
		log.violations,
		`unmocked Plume route(s) on "${ id }": ${ log.violations.join(
			', '
		) } — add a fixture in screens.spec.js`
	).toEqual( [] );

	await page.screenshot( {
		path: path.join( OUT, testInfo.project.name, `${ id }.png` ),
		fullPage,
		animations: 'disabled',
		caret: 'hide',
		mask: [ page.locator( '#footer-upgrade, .plume-dash-version' ) ],
		maskColor: '#c3c4c7',
	} );
}

module.exports = { prepare, shoot, json, restRoute, OUT };

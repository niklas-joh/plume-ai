/**
 * Global setup for the PR screenshot capture (playwright.config.visual.js).
 *
 * 1. Removes the previous run's PNGs, so a stale local shot is never published.
 * 2. Normalises the server-rendered state that would otherwise differ between
 *    a PR run (after the full suite) and a baseline run on `main` (no suite),
 *    in one WP-CLI call. See SERVER_STATE in fixtures.js for the full list.
 * 3. Logs in once and saves the session as storageState for every shot.
 *
 * Deliberately does NOT inject an API key or read CLAUDE_API_KEY: every Plume
 * REST call is answered by a fixture, so the capture never needs a provider.
 */

'use strict';

/* eslint-disable no-console -- setup progress is reported on the CI log. */

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { chromium } = require( '@playwright/test' );

const OUT = path.resolve( __dirname, '../../../../.artifacts/screenshots/ci' );
const DEFAULT_PASS = 'C8IcqAWJu8F3dOw6E4ndWhIe'; // Same test-only value as global-setup.js.

/**
 * Builds the shell script run once inside the wp-env CLI container.
 *
 * @param {string} user Test user login (validated by the caller).
 * @param {string} pass Test user password (validated by the caller).
 * @return {string} POSIX sh script.
 */
function buildCliScript( user, pass ) {
	return [
		'set -e',
		'wp() { command wp "$@" --allow-root; }',
		// Pretty permalinks: the route predicates read /wp-json/ paths first.
		'wp rewrite structure /%postname%/ --hard',
		'wp rewrite flush --hard',
		`wp user get ${ user } --field=login >/dev/null 2>&1 || wp user create ${ user } ${ user }@example.com --role=administrator --user_pass=${ pass }`,
		// The baseline job runs no suite, so global-setup.js never sets the tier
		// there; without this every desktop shot would differ by the free-tier
		// "Upgrade" menu entry (AdminMenu.php).
		'wp option update plume_site_tier pro_managed',
		'wp option update plume_onboarding_seen 1',
		// Gravatar is cross-origin (aborted) and would leave a broken avatar.
		'wp option update show_avatars 0',
		`wp user meta update ${ user } admin_color fresh`,
	].join( '\n' );
}

/**
 * Playwright global setup entry point.
 *
 * @param {import('@playwright/test').FullConfig} config Resolved Playwright config.
 * @return {Promise<void>}
 */
async function visualSetup( config ) {
	const started = Date.now();
	const baseURL =
		config.projects[ 0 ]?.use?.baseURL ||
		process.env.WP_BASE_URL ||
		'http://localhost:8888';
	const user = process.env.WP_TEST_USER || 'nj_agent';
	const pass = process.env.WP_TEST_PASS || DEFAULT_PASS;

	// Interpolated into a shell script, so only plain tokens are accepted.
	if ( ! /^[a-z0-9_]+$/.test( user ) || ! /^[A-Za-z0-9]+$/.test( pass ) ) {
		throw new Error(
			'[screens setup] WP_TEST_USER/WP_TEST_PASS must be alphanumeric.'
		);
	}

	for ( const entry of [ 'desktop', 'phone', 'results.json', 'env.json' ] ) {
		fs.rmSync( path.join( OUT, entry ), { recursive: true, force: true } );
	}
	fs.mkdirSync( OUT, { recursive: true } );

	if ( /:(8888|8889)\b/.test( baseURL ) ) {
		execFileSync(
			'npx',
			[
				'wp-env',
				'run',
				'cli',
				'--',
				'sh',
				'-c',
				buildCliScript( user, pass ),
			],
			{ stdio: 'inherit' }
		);
	} else {
		// Never rewrite options on a non-wp-env site (e.g. the blog Docker).
		console.warn(
			`[screens setup] ${ baseURL } is not wp-env: skipping state normalisation. Shots will not match CI.`
		);
	}

	const browser = await chromium.launch();
	try {
		const page = await browser.newPage( { baseURL } );
		await page.goto( '/wp-login.php' );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await page.click( '#wp-submit' );
		await page.waitForURL( '**/wp-admin/**' );
		await page
			.context()
			.storageState( { path: path.join( OUT, '.auth.json' ) } );
	} finally {
		await browser.close();
	}

	console.log(
		`[screens setup] Ready in ${ Math.round(
			( Date.now() - started ) / 1000
		) }s.`
	);
}

module.exports = visualSetup;

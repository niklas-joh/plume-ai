/**
 * Playwright global setup.
 *
 * Ensures the nj_agent test user exists in the WordPress environment
 * before any E2E tests run. Safe to run repeatedly — exits silently if the
 * user already exists.
 *
 * Supports two environments:
 *  - wp-env (port 8888): uses `npx wp-env run cli wp …` (WP-CLI lives in the
 *    separate cli container, not the WordPress container)
 *  - Blog Docker (port 8080): uses `docker exec blognjohanssoneu-wordpress-1 wp …`
 */

'use strict';

const { execSync } = require( 'child_process' );

async function globalSetup() {
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';
	const isWpEnv = baseURL.includes( ':8888' ) || baseURL.includes( ':8889' );

	const wpCli = ( cmd, opts = {} ) => {
		const fullCmd = isWpEnv
			? `npx wp-env run cli wp ${ cmd } --allow-root`
			: `docker exec blognjohanssoneu-wordpress-1 wp ${ cmd } --allow-root`;
		return execSync( fullCmd, opts );
	};

	try {
		wpCli( 'user get nj_agent --field=login', { stdio: 'pipe' } );
		console.log( '[E2E setup] nj_agent user already exists — skipping creation.' );
	} catch {
		// User does not exist — create it.
		console.log( '[E2E setup] Creating nj_agent test user...' );
		wpCli(
			'user create nj_agent nj_agent@example.com ' +
			'--role=administrator ' +
			'--user_pass=C8IcqAWJu8F3dOw6E4ndWhIe',
			{ stdio: 'inherit' }
		);
		console.log( '[E2E setup] nj_agent created.' );
	}

	// Mark onboarding as seen so the dashboard renders normally.
	// On a fresh install the wizard blocks the dashboard and chat views.
	wpCli( 'option set wp_ai_mind_onboarding_seen 1', { stdio: 'inherit' } );
	console.log( '[E2E setup] Onboarding marked as seen.' );
}

module.exports = globalSetup;

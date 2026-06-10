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

	// Set pretty permalinks so REST API uses /wp-json/ paths — without this,
	// wp-env defaults to plain URLs (?rest_route=...) which don't match the
	// **/wp-json/** glob patterns used in Playwright route intercepts.
	wpCli( 'rewrite structure /%postname%/ --hard', { stdio: 'inherit' } );
	wpCli( 'rewrite flush --hard', { stdio: 'inherit' } );
	console.log( '[E2E setup] Permalink structure set to /%postname%/.' );

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

	// Set site tier to pro_managed so all authenticated requests resolve to a
	// Pro tier. pro_managed includes model_selection=true, which enables the
	// provider select in ProvidersTab (trial has model_selection=false, leaving
	// the fieldset disabled and preventing the settings save test from working).
	// Tier-gating tests that need to simulate free tier override isPro client-side
	// via addInitScript, so this site-level setting does not interfere with them.
	wpCli( 'option set plume_site_tier pro_managed', { stdio: 'inherit' } );
	console.log( '[E2E setup] Site tier set to pro_managed.' );

	// Mark onboarding as seen so the dashboard renders normally.
	// On a fresh install the wizard blocks the dashboard and chat views.
	wpCli( 'option set plume_onboarding_seen 1', { stdio: 'inherit' } );
	console.log( '[E2E setup] Onboarding marked as seen.' );

	// ── Real API key injection (optional) ────────────────────────────────────
	// When CLAUDE_API_KEY is set (CI secret), store it encrypted in WordPress DB
	// via ProviderSettings and switch site tier to pro_byok so real-api-chat
	// spec can make live Anthropic calls without any route intercepts.
	// Silently skipped in local dev when the key is absent.
	const claudeApiKey = process.env.CLAUDE_API_KEY;
	if ( claudeApiKey ) {
		// Validate format before injecting — prevents shell/PHP injection if the
		// env value is ever unexpected. Anthropic keys are always sk-ant-api03-…
		if ( ! /^sk-ant-[A-Za-z0-9_-]+$/.test( claudeApiKey ) ) {
			throw new Error( '[E2E setup] CLAUDE_API_KEY does not match expected format (sk-ant-…). Aborting.' );
		}
		console.log( '[E2E setup] Storing Claude API key for real-API tests.' );
		wpCli(
			`eval "( new \\\\Plume\\\\Settings\\\\ProviderSettings() )->set_api_key( 'claude', '${ claudeApiKey }' );"`,
			{ stdio: 'inherit' }
		);
		// Switch to pro_byok so the direct Claude path is used, not the proxy.
		// Mocked E2E tests override tier state client-side via addInitScript so
		// this site-level change does not break them.
		wpCli( 'option set plume_site_tier pro_byok', { stdio: 'inherit' } );
		console.log( '[E2E setup] Site tier set to pro_byok for real-API tests.' );
	}
}

module.exports = globalSetup;

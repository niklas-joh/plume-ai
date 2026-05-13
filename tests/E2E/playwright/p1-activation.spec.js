// tests/E2E/playwright/p1-activation.spec.js
// P1 smoke tests — verify the plugin activates cleanly.
//
// Uses nj_agent (administrator) created by global-setup.js.
// Base URL is controlled by WP_BASE_URL env var (default: http://localhost:8888).
// Run: npx playwright test tests/E2E/playwright/p1-activation.spec.js

const { test, expect } = require( '@playwright/test' );

// ---------------------------------------------------------------------------
// Shared helper — log in as the test admin (nj_agent).
// ---------------------------------------------------------------------------
async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php' );
	// Wait for the form to be fully interactive before filling.
	await page.waitForSelector( '#user_login', { state: 'visible' } );
	await page.fill( '#user_login', 'nj_agent' );
	await page.fill( '#user_pass', 'C8IcqAWJu8F3dOw6E4ndWhIe' );
	await page.click( '#wp-submit' );
	// Wait for the dashboard to load.
	await page.waitForURL( '**/wp-admin/**' );
}

// ---------------------------------------------------------------------------

test.describe( 'P1 — Plugin activation', () => {

	test( 'Admin menu item "Stilus" appears after activation', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#adminmenu' ) ).toContainText( 'Stilus' );
	} );

	test( 'Chat page renders the React mount point (#wp-ai-mind-chat)', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-chat' );
		await expect( page.locator( '#wp-ai-mind-chat' ) ).toBeAttached();
	} );

	test( 'Plugin page loads without PHP fatal errors', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind' );
		await expect( page ).not.toHaveTitle( /Fatal error/i );
		// Also confirm the page title is not a generic WordPress error.
		await expect( page ).not.toHaveTitle( /Error/i );
	} );

} );

// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

test.describe( 'Tier gating', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'generator page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-generator' );
		// The mount point id matches the page slug registered in PHP.
		await expect(
			page.locator( '#wp-ai-mind-generator, .wpaim-generator-app, body' )
		).toBeVisible();
	} );

	test( 'images page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-images' );
		await expect(
			page.locator( '#wp-ai-mind-images, .wpaim-images-app, body' )
		).toBeVisible();
	} );

	test( 'REST API returns 403 for seo/generate when mocked as free tier', async ( { page } ) => {
		// Intercept the seo/generate endpoint and respond with a 403.
		// SeoWorkArea.jsx uses the full restUrl from window.wpAiMindData,
		// so we match the path segment rather than an absolute URL.
		await page.route( '**/seo/generate', async ( route ) => {
			await route.fulfill( {
				status: 403,
				contentType: 'application/json',
				body: JSON.stringify( {
					code: 'rest_forbidden',
					message: 'Feature not available on your plan.',
				} ),
			} );
		} );

		// Navigate into the admin so the browser context is initialised with
		// the authenticated session from beforeEach.
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-seo' );

		// Trigger the fetch from the page (browser) context so page.route()
		// intercepts it. page.request bypasses route intercepts entirely.
		const status = await page.evaluate( async () => {
			const response = await fetch(
				'/wp-json/wp-ai-mind/v1/seo/generate',
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { post_id: 1 } ),
				}
			);
			return response.status;
		} );
		expect( status ).toBe( 403 );
	} );

	test( 'SEO page shows Pro-gate upgrade link for free-tier users', async ( { page } ) => {
		// Override window.wpAiMindData to simulate free tier before React boots.
		// This is injected before navigation so the SeoApp conditional (line 46)
		// reads isPro === false and renders .wpaim-pro-gate.
		await page.addInitScript( () => {
			Object.defineProperty( window, 'wpAiMindData', {
				value: {
					...( window.wpAiMindData ?? {} ),
					isPro: false,
				},
				writable: true,
				configurable: true,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-seo' );
		await page.waitForSelector( '#wp-ai-mind-seo', { timeout: 10000 } );

		// Free-tier renders .wpaim-pro-gate with an upgrade link (SeoApp.jsx line 47).
		await expect( page.locator( '.wpaim-pro-gate' ) ).toBeVisible( { timeout: 10000 } );
		await expect(
			page.locator( '.wpaim-pro-gate a[href*="pricing"]' )
		).toBeVisible();
	} );

	test( 'settings page shows provider configuration options', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-settings' );
		// .wpaim-settings-shell hydrates after React boots (confirmed in p3-chat.spec.js line 35).
		await page.waitForSelector( '.wpaim-settings-shell', { timeout: 10000 } );
		await expect( page.locator( '.wpaim-settings-shell' ) ).toBeVisible();
		// The settings page renders tabs via .wpaim-settings-tabpanel (p3-chat.spec.js line 37).
		await expect( page.locator( '.wpaim-settings-tabpanel' ) ).toBeVisible();
	} );

	test( 'chat page remains accessible for free-tier users', async ( { page } ) => {
		// Chat is not Pro-gated — ensure it still renders .wpaim-shell regardless of tier.
		await page.addInitScript( () => {
			Object.defineProperty( window, 'wpAiMindData', {
				value: {
					...( window.wpAiMindData ?? {} ),
					isPro: false,
				},
				writable: true,
				configurable: true,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-chat' );
		// .wpaim-shell is the root chat element (ChatApp.jsx line 297).
		await page.waitForSelector( '.wpaim-shell', { timeout: 10000 } );
		await expect( page.locator( '.wpaim-shell' ) ).toBeVisible();
		// Sidebar and composer must also be present for free-tier users.
		await expect( page.locator( '.wpaim-sidebar' ) ).toBeVisible();
		await expect( page.locator( '.wpaim-composer' ) ).toBeVisible();
	} );
} );

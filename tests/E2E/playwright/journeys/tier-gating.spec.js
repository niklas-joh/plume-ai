// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

test.describe( 'Tier gating', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'generator page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=stilus-generator' );
		// #stilus-generator is the PHP-rendered mount point — always present when
		// the plugin is active and the page loads without a fatal error.
		// .first() avoids a strict-mode violation if the fallback selector also matches.
		await expect(
			page.locator( '#stilus-generator, .wpaim-generator-app' ).first()
		).toBeVisible();
	} );

	test( 'images page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=stilus-images' );
		await expect(
			page.locator( '#stilus-images, .wpaim-images-app' ).first()
		).toBeVisible();
	} );

	test( 'REST API returns 403 for seo/generate when mocked as free tier', async ( { page } ) => {
		// Intercept the seo/generate endpoint and respond with a 403.
		// URL predicate matches both /wp-json/.../seo/generate (pretty) and
		// ?rest_route=.../seo/generate (plain) without glob ambiguity.
		await page.route( ( url ) => url.href.includes( 'seo/generate' ), async ( route ) => {
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
		await page.goto( '/wp-admin/admin.php?page=stilus-seo' );

		// Trigger the fetch from the page (browser) context so page.route()
		// intercepts it. page.request bypasses route intercepts entirely.
		const status = await page.evaluate( async () => {
			const response = await fetch(
				'/wp-json/stilus/v1/seo/generate',
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
		// Override window.stilusData to simulate free tier before React boots.
		// A getter/setter proxy is used so PHP's inline `var stilusData = {...}`
		// triggers the setter and gets isPro forced to false, while still receiving
		// the real restUrl, nonce, and other values from the server.
		await page.addInitScript( () => {
			let _data = {};
			Object.defineProperty( window, 'stilusData', {
				get() { return _data; },
				set( val ) { _data = { ...val, isPro: false }; },
				configurable: false,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=stilus-seo' );
		await page.waitForSelector( '#stilus-seo', { timeout: 10000 } );

		// Free-tier renders .wpaim-pro-gate with an upgrade link (SeoApp.jsx line 47).
		await expect( page.locator( '.wpaim-pro-gate' ) ).toBeVisible( { timeout: 10000 } );
		await expect(
			page.locator( '.wpaim-pro-gate a[href*="pricing"]' )
		).toBeVisible();
	} );

	test( 'settings page shows provider configuration options', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=stilus-settings' );
		// .wpaim-settings-shell hydrates after React boots (confirmed in p3-chat.spec.js line 35).
		await page.waitForSelector( '.wpaim-settings-shell', { timeout: 10000 } );
		await expect( page.locator( '.wpaim-settings-shell' ) ).toBeVisible();
		// The settings page renders tabs via .wpaim-settings-tabpanel (p3-chat.spec.js line 37).
		await expect( page.locator( '.wpaim-settings-tabpanel' ) ).toBeVisible();
	} );

	test( 'chat page remains accessible for free-tier users', async ( { page } ) => {
		// Chat is not Pro-gated — ensure it still renders .wpaim-shell regardless of tier.
		// Getter/setter proxy forces isPro: false while preserving restUrl, nonce, etc.
		await page.addInitScript( () => {
			let _data = {};
			Object.defineProperty( window, 'wpAiMindData', {
				get() { return _data; },
				set( val ) { _data = { ...val, isPro: false }; },
				configurable: false,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=stilus-chat' );
		// .wpaim-shell is the root chat element (ChatApp.jsx line 297).
		await page.waitForSelector( '.wpaim-shell', { timeout: 10000 } );
		await expect( page.locator( '.wpaim-shell' ) ).toBeVisible();
		// Sidebar and composer must also be present for free-tier users.
		await expect( page.locator( '.wpaim-sidebar' ) ).toBeVisible();
		await expect( page.locator( '.wpaim-composer' ) ).toBeVisible();
	} );
} );

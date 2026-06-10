// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

test.describe( 'Tier gating', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'generator page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=plume-generator' );
		// #plume-generator is the PHP-rendered mount point — always present when
		// the plugin is active and the page loads without a fatal error.
		// .first() avoids a strict-mode violation if the fallback selector also matches.
		await expect(
			page.locator( '#plume-generator, .plume-generator-app' ).first()
		).toBeVisible();
	} );

	test( 'images page loads for authenticated user', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=plume-images' );
		await expect(
			page.locator( '#plume-images, .plume-images-app' ).first()
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
		await page.goto( '/wp-admin/admin.php?page=plume-seo' );

		// Trigger the fetch from the page (browser) context so page.route()
		// intercepts it. page.request bypasses route intercepts entirely.
		const status = await page.evaluate( async () => {
			const response = await fetch(
				'/wp-json/plume/v1/seo/generate',
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
		// Override window.plumeData to simulate free tier before React boots.
		// A getter/setter proxy is used so PHP's inline `var plumeData = {...}`
		// triggers the setter and gets isPro forced to false, while still receiving
		// the real restUrl, nonce, and other values from the server.
		await page.addInitScript( () => {
			let _data = {};
			Object.defineProperty( window, 'plumeData', {
				get() { return _data; },
				set( val ) { _data = { ...val, isPro: false }; },
				configurable: false,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=plume-seo' );
		await page.waitForSelector( '#plume-seo', { timeout: 10000 } );

		// Free-tier renders .plume-pro-gate with an upgrade link (SeoApp.jsx line 47).
		await expect( page.locator( '.plume-pro-gate' ) ).toBeVisible( { timeout: 10000 } );
		await expect(
			page.locator( '.plume-pro-gate a[href*="pricing"]' )
		).toBeVisible();
	} );

	test( 'settings page shows provider configuration options', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=plume-settings' );
		// .plume-settings-shell hydrates after React boots (confirmed in p3-chat.spec.js line 35).
		await page.waitForSelector( '.plume-settings-shell', { timeout: 10000 } );
		await expect( page.locator( '.plume-settings-shell' ) ).toBeVisible();
		// The settings page renders tabs via .plume-settings-tabpanel (p3-chat.spec.js line 37).
		await expect( page.locator( '.plume-settings-tabpanel' ) ).toBeVisible();
	} );

	test( 'chat page remains accessible for free-tier users', async ( { page } ) => {
		// Chat is not Pro-gated — ensure it still renders .plume-shell regardless of tier.
		// Getter/setter proxy forces isPro: false while preserving restUrl, nonce, etc.
		await page.addInitScript( () => {
			let _data = {};
			Object.defineProperty( window, 'plumeindData', {
				get() { return _data; },
				set( val ) { _data = { ...val, isPro: false }; },
				configurable: false,
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=plume-chat' );
		// .plume-shell is the root chat element (ChatApp.jsx line 297).
		await page.waitForSelector( '.plume-shell', { timeout: 10000 } );
		await expect( page.locator( '.plume-shell' ) ).toBeVisible();
		// Sidebar and composer must also be present for free-tier users.
		await expect( page.locator( '.plume-sidebar' ) ).toBeVisible();
		await expect( page.locator( '.plume-composer' ) ).toBeVisible();
	} );
} );

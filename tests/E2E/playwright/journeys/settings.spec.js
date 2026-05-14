// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

// URL predicate — matches both /wp-json/wp-ai-mind/v1/settings (pretty) and
// ?rest_route=.../settings (plain) without relying on glob pattern matching.
const isSettingsUrl = ( url ) => url.href.includes( 'wp-ai-mind/v1/settings' );

test.describe( 'Settings journey', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'loads settings and shows the current provider', async ( { page } ) => {
		// SettingsApp.jsx fetches GET /wp-ai-mind/v1/settings on mount (line 30)
		// and passes settings down to ProvidersTab, which renders a SelectControl
		// for "Default AI Provider" using settings.default_provider as the value.
		await page.route( isSettingsUrl, async ( route ) => {
			if ( route.request().method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						default_provider: 'claude',
						image_provider: 'openai',
						api_keys: {},
					} ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-settings' );

		// .wpaim-settings-shell is the root element (SettingsApp.jsx line 69).
		await page.waitForSelector( '.wpaim-settings-shell', { timeout: 10000 } );

		// ProvidersTab renders a "Default AI Provider" SelectControl. The selected
		// option value 'claude' corresponds to the label 'Claude' in PROVIDER_OPTIONS
		// (ProvidersTab.jsx line 12). The <select> will show 'Claude' as its visible text.
		await expect(
			page.locator( '.wpaim-providers-tab select' ).first()
		).toHaveValue( 'claude', { timeout: 10000 } );
	} );

	test( 'saves a setting change and shows a success notice', async ( { page } ) => {
		// SettingsApp.jsx calls POST /wp-ai-mind/v1/settings in saveSettings (line 40)
		// and on success sets saveResult to 'success', rendering a <Notice> with
		// the text "Saved successfully" (SettingsApp.jsx line 85).
		await page.route( isSettingsUrl, async ( route ) => {
			if ( route.request().method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						default_provider: 'claude',
						image_provider: 'openai',
						api_keys: {},
					} ),
				} );
			} else if ( route.request().method() === 'POST' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { success: true } ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-settings' );
		await page.waitForSelector( '.wpaim-settings-shell', { timeout: 10000 } );

		// Wait for settings to load (the loading state div disappears).
		// SettingsApp renders .wpaim-settings-loading while settings === null (line 95).
		await page.waitForSelector( '.wpaim-settings-loading', { state: 'hidden', timeout: 10000 } );

		// ProvidersTab renders Save buttons for each API key provider (ProvidersTab.jsx
		// line 153). To trigger a POST without needing a dirty key, change the
		// Default AI Provider select — its onChange calls saveSettings directly
		// (ProvidersTab.jsx line 98).
		const providerSelect = page.locator( '.wpaim-providers-tab select' ).first();
		await providerSelect.selectOption( 'openai' );

		// SettingsApp renders a <Notice> with "Saved successfully" on success
		// (SettingsApp.jsx line 85). @wordpress/components renders Notice as
		// role="alert" or a div; match the text content reliably.
		await expect(
			page.locator( '.wpaim-settings-shell' )
		).toContainText( 'Saved successfully', { timeout: 10000 } );
	} );

	test( 'settings page renders tab navigation and the providers tab by default', async ( { page } ) => {
		// SettingsApp renders a TabPanel with TABS (SettingsApp.jsx line 9–13).
		// The tab panel container has className="wpaim-settings-tabpanel" (line 91).
		// The default (first) tab is "Providers", which renders ProvidersTab containing
		// .wpaim-providers-tab (ProvidersTab.jsx line 72).
		await page.route( isSettingsUrl, async ( route ) => {
			if ( route.request().method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						default_provider: 'claude',
						image_provider: 'openai',
						api_keys: {},
					} ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-settings' );
		await page.waitForSelector( '.wpaim-settings-shell', { timeout: 10000 } );

		// Tab panel must be visible.
		await expect(
			page.locator( '.wpaim-settings-tabpanel' )
		).toBeVisible( { timeout: 10000 } );

		// All three tab buttons — Providers, Voice, Features — should be present
		// (SettingsApp.jsx TABS constant, lines 9–13).
		await expect( page.locator( 'button[role="tab"]', { hasText: 'Providers' } ) ).toBeVisible();
		await expect( page.locator( 'button[role="tab"]', { hasText: 'Voice' } ) ).toBeVisible();
		await expect( page.locator( 'button[role="tab"]', { hasText: 'Features' } ) ).toBeVisible();

		// After settings load, the Providers tab content is active by default.
		await page.waitForSelector( '.wpaim-settings-loading', { state: 'hidden', timeout: 10000 } );
		await expect( page.locator( '.wpaim-providers-tab' ) ).toBeVisible( { timeout: 10000 } );
	} );
} );

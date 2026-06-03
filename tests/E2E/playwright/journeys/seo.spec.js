// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

test.describe( 'SEO journey', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'SEO page renders its root container', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-seo' );
		// SeoApp renders either .stilus-pro-gate (free) or .stilus-page (Pro).
		await expect(
			page.locator( '#wp-ai-mind-seo' )
		).toBeVisible( { timeout: 10000 } );
	} );

	test( 'Pro SEO page shows post list table when Pro is active', async ( { page } ) => {
		// This test is conditional: if the site is free-tier, the gate renders instead.
		// We check for one of the two valid states rather than assuming Pro.
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-seo' );
		await page.waitForSelector( '#wp-ai-mind-seo', { timeout: 10000 } );

		const isProGate = await page.locator( '.stilus-pro-gate' ).isVisible();
		if ( isProGate ) {
			// Free-tier: gate renders with an upgrade link (SeoApp.jsx line 47).
			await expect( page.locator( '.stilus-pro-gate a[href*="pricing"]' ) ).toBeVisible();
		} else {
			// Pro: .stilus-page wraps the table header and PostListTable (SeoApp.jsx line 65).
			await expect( page.locator( '.stilus-page' ) ).toBeVisible();
			// PostListTable renders a .stilus-post-list container (PostListTable.jsx line 132).
			await expect( page.locator( '.stilus-post-list' ) ).toBeVisible( { timeout: 10000 } );
		}
	} );

	test( 'generates SEO suggestions and renders specific fixture response text', async ( { page } ) => {
		// Mock the seo/generate endpoint — uses the full restUrl path built in SeoWorkArea.jsx.
		// The URL is constructed as `${restUrl}/seo/generate` where restUrl comes from
		// window.stilusData, so we match on the path segment.
		// URL predicate matches both pretty (/wp-json/.../seo/generate) and plain
		// (?rest_route=.../seo/generate) REST permalink formats.
		await page.route( ( url ) => url.href.includes( 'seo/generate' ), async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					meta_title: 'How to Build Comprehensive WordPress Plugin Tests | Dev Guide',
					og_description: 'A definitive guide to adding integration tests using wp-env and Playwright for WordPress plugins.',
					excerpt: 'Learn to add integration and E2E tests to your WordPress plugin.',
					alt_text: 'Developer at laptop writing PHP integration tests',
				} ),
			} );
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-seo' );
		await page.waitForSelector( '#wp-ai-mind-seo', { timeout: 10000 } );

		// Skip this test if we hit the Pro gate — generation requires Pro.
		const isProGate = await page.locator( '.stilus-pro-gate' ).isVisible();
		if ( isProGate ) {
			test.skip();
		}

		// Wait for the post list to finish loading.
		// PostListTable shows .stilus-list-loading while fetching (PostListTable.jsx line 125).
		await page.waitForSelector( '.stilus-list-loading', { state: 'hidden', timeout: 15000 } );

		// The expand button text is "Generate ▼" (PostListTable.jsx line 283).
		// Click the first row's expand button to open SeoWorkArea.
		const expandButton = page.locator( 'button.button-small', { hasText: 'Generate' } ).first();
		if ( ! await expandButton.isVisible( { timeout: 5000 } ) ) {
			// No posts in the test environment — verify the empty state renders instead.
			await expect( page.locator( '.stilus-post-list' ) ).toBeVisible();
			return;
		}
		await expandButton.click();

		// SeoWorkArea renders inside .stilus-work-area (SeoWorkArea.jsx line 113).
		await page.waitForSelector( '.stilus-work-area', { timeout: 5000 } );

		// Click "✦ Generate SEO" — the primary button inside .stilus-work-header
		// (SeoWorkArea.jsx line 124).
		await page.locator( '.stilus-work-header button.button-primary' ).click();

		// After generation, the meta title input (#seo-meta-title) is populated
		// with the fixture value (SeoWorkArea.jsx line 172).
		await expect(
			page.locator( '#seo-meta-title' )
		).toHaveValue( 'How to Build Comprehensive WordPress Plugin Tests | Dev Guide', { timeout: 10000 } );

		// The OG description input (#seo-og-desc) should also carry the fixture text.
		await expect(
			page.locator( '#seo-og-desc' )
		).toHaveValue( 'A definitive guide to adding integration tests using wp-env and Playwright for WordPress plugins.' );
	} );

	test( 'SEO field inputs are present after work area is expanded', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-seo' );
		await page.waitForSelector( '#wp-ai-mind-seo', { timeout: 10000 } );

		const isProGate = await page.locator( '.stilus-pro-gate' ).isVisible();
		if ( isProGate ) {
			test.skip();
		}

		await page.waitForSelector( '.stilus-list-loading', { state: 'hidden', timeout: 15000 } );

		const expandButton = page.locator( 'button.button-small', { hasText: 'Generate' } ).first();
		if ( ! await expandButton.isVisible( { timeout: 5000 } ) ) {
			await expect( page.locator( '.stilus-post-list' ) ).toBeVisible();
			return;
		}
		await expandButton.click();

		await page.waitForSelector( '.stilus-work-area', { timeout: 5000 } );

		// Verify all four SEO field inputs are rendered (SeoWorkArea.jsx lines 172, 189, 202, 220).
		await expect( page.locator( '#seo-meta-title' ) ).toBeVisible();
		await expect( page.locator( '#seo-og-desc' ) ).toBeVisible();
		await expect( page.locator( '#seo-excerpt' ) ).toBeVisible();
		await expect( page.locator( '#seo-alt-text' ) ).toBeVisible();
	} );
} );

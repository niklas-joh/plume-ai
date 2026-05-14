// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

test.describe( 'Generator journey', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'generates content and renders it in the output area', async ( { page } ) => {
		// Route the generate endpoint to return a verifiable fixture post.
		// GeneratorWizard.jsx posts to /wp-ai-mind/v1/generate and on success
		// transitions to step 3, rendering result.content in .wpaim-generator__preview.
		// URL predicate is used instead of a glob — wp-env may serve REST via
		// /?rest_route= (plain permalinks) or /wp-json/ (pretty), and both forms
		// contain the same path segment so the predicate matches either.
		await page.route(
			( url ) => url.href.includes( 'wp-ai-mind/v1/generate' ),
			async ( route ) => {
				if ( route.request().method() === 'POST' ) {
					await route.fulfill( {
						status: 200,
						contentType: 'application/json',
						body: JSON.stringify( {
							post_id: 42,
							edit_url: '/wp-admin/post.php?post=42&action=edit',
							content: 'This is a uniquely identifiable generator test output for validation.',
							tokens_used: 150,
						} ),
					} );
				} else {
					await route.continue();
				}
			}
		);

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-generator' );

		// Wait for React to hydrate — #wp-ai-mind-generator is the mount point
		// (GeneratorPage.php line 33, generator/index.js line 6).
		await page.waitForSelector( '#wp-ai-mind-generator', { timeout: 10000 } );

		// The title TextControl is the first input inside .wpaim-generator__card
		// (GeneratorWizard.jsx line 193 — "Post title *" label).
		// @wordpress/components renders TextControl as a labelled <input>.
		await page.fill( '.wpaim-generator__card input[type="text"]:first-of-type', 'Test Post Title' );

		// The submit button text is "Generate Post" (GeneratorWizard.jsx line 246).
		await page.locator( 'button', { hasText: 'Generate Post' } ).click();

		// On success, step 3 renders .wpaim-generator__preview with the HTML
		// content from the fixture (GeneratorWizard.jsx line 132).
		await expect(
			page.locator( '.wpaim-generator__preview' )
		).toContainText( 'uniquely identifiable generator test output for validation', { timeout: 10000 } );
	} );

	test( 'shows error state on API failure', async ( { page } ) => {
		// Return a 500 WP REST error — GeneratorWizard catches the rejection,
		// sets the error state, and renders it above the form (line 174–189).
		await page.route(
			( url ) => url.href.includes( 'wp-ai-mind/v1/generate' ),
			async ( route ) => {
				if ( route.request().method() === 'POST' ) {
					await route.fulfill( {
						status: 500,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'api_error',
							message: 'Uniquely identifiable error message from generator test',
						} ),
					} );
				} else {
					await route.continue();
				}
			}
		);

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-generator' );
		await page.waitForSelector( '#wp-ai-mind-generator', { timeout: 10000 } );

		// Fill the required title field so the submit button is enabled
		// (GeneratorWizard.jsx line 238 — disabled when title is empty).
		await page.fill( '.wpaim-generator__card input[type="text"]:first-of-type', 'Error Test Title' );

		await page.locator( 'button', { hasText: 'Generate Post' } ).click();

		// The error div renders the e.message from the caught rejection
		// (GeneratorWizard.jsx line 59 / lines 174–189).
		await expect(
			page.locator( '.wpaim-generator' )
		).toContainText( 'Uniquely identifiable error message from generator test', { timeout: 10000 } );
	} );

	test( 'generate button is disabled when the title field is empty', async ( { page } ) => {
		// The generate button is disabled while form.title is blank
		// (GeneratorWizard.jsx line 238: disabled={ !form.title.trim() }).
		// This validates the free-tier guard for users who have not yet entered a prompt.
		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-generator' );
		await page.waitForSelector( '#wp-ai-mind-generator', { timeout: 10000 } );

		// On initial load the title field is empty, so the button must be disabled.
		const generateBtn = page.locator( 'button', { hasText: 'Generate Post' } );
		await expect( generateBtn ).toBeDisabled( { timeout: 10000 } );

		// Entering a title should enable it.
		await page.fill( '.wpaim-generator__card input[type="text"]:first-of-type', 'A title' );
		await expect( generateBtn ).toBeEnabled();
	} );
} );

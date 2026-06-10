// @ts-check
/**
 * Real-API chat E2E journey — zero mocking.
 *
 * Requires CLAUDE_API_KEY. global-setup.js stores the key encrypted in the
 * WordPress DB and sets site tier to pro_byok when the variable is present.
 * Skipped automatically when CLAUDE_API_KEY is absent.
 *
 * Cost: ~$0.001/run (claude-haiku-4-5-20251001, two short turns).
 */
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

test.skip(
	() => ! process.env.CLAUDE_API_KEY,
	'CLAUDE_API_KEY not set — skipping real-API E2E tests.'
);

// Real LLM calls can take up to 30 s; increase timeout for this suite.
test.setTimeout( 90_000 );

test.describe( 'Real-API chat (no mocking)', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'sends a real message and receives a non-empty AI response', async ( { page } ) => {
		// Zero mocking. ChatApp creates the conversation inline on first send.
		await page.goto( '/wp-admin/admin.php?page=plume-chat' );
		await page.waitForSelector( '.plume-shell', { timeout: 15_000 } );

		await page.fill( '.plume-composer__input', 'Reply with only the word "pong".' );
		await page.locator( '.plume-composer__input' ).press( 'Enter' );

		const aiBubble = page.locator( '.plume-bubble--ai .plume-bubble__content' ).last();
		await expect( aiBubble ).not.toBeEmpty( { timeout: 60_000 } );

		const text = await aiBubble.innerText();
		expect( text.trim().length ).toBeGreaterThan( 0 );
	} );

	test( 'consecutive messages maintain conversation context', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=plume-chat' );
		await page.waitForSelector( '.plume-shell', { timeout: 15_000 } );

		await page.fill( '.plume-composer__input', 'My secret number is 42. Acknowledge with just "ack".' );
		await page.locator( '.plume-composer__input' ).press( 'Enter' );
		await expect(
			page.locator( '.plume-bubble--ai .plume-bubble__content' ).last()
		).not.toBeEmpty( { timeout: 60_000 } );

		await page.fill( '.plume-composer__input', 'What was my secret number? Reply with just the number.' );
		await page.locator( '.plume-composer__input' ).press( 'Enter' );

		const bubbles = page.locator( '.plume-bubble--ai .plume-bubble__content' );
		await expect( bubbles ).toHaveCount( 2, { timeout: 60_000 } );
		// Wait for the second bubble to have content before asserting on it.
		await expect( bubbles.last() ).not.toBeEmpty( { timeout: 60_000 } );
		expect( await bubbles.last().innerText() ).toContain( '42' );
	} );
} );

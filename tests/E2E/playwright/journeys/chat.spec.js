// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpLogin } = require( '../helpers/login' );

// URL predicates used for route intercepts — matches both pretty-permalink
// (/wp-json/wp-ai-mind/v1/...) and plain-permalink (?rest_route=...) formats.
const isConversationsUrl = ( url ) =>
	url.href.includes( 'wp-ai-mind/v1/conversations' ) &&
	! url.href.includes( '/messages' );

const isMessagesUrl = ( url ) =>
	url.href.includes( 'wp-ai-mind/v1/conversations' ) &&
	url.href.includes( '/messages' );

test.describe( 'Chat journey', () => {
	test.beforeEach( async ( { page } ) => {
		await wpLogin( page );
	} );

	test( 'sends a message and renders the specific AI response text', async ( { page } ) => {
		// Mock conversation creation (inline — fires when no conversation is active yet).
		await page.route( isConversationsUrl, async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				await route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( {
						id: 1,
						title: 'New conversation',
						created_at: new Date().toISOString(),
					} ),
				} );
			} else {
				await route.continue();
			}
		} );

		// Mock the messages POST to return a specific, verifiable AI response.
		// The GET (loadMessages) should still pass through to the real server.
		await page.route( isMessagesUrl, async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( {
						content: 'This is a uniquely identifiable integration test response about AI automation and testing.',
						model: 'claude-opus-4-6',
						tokens: 42,
					} ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-chat' );
		// Wait for React to hydrate — .wpaim-shell is the root chat element.
		await page.waitForSelector( '.wpaim-shell', { timeout: 10000 } );

		// The composer textarea is .wpaim-composer__input (Composer.jsx line 98).
		// On the launch screen the composer is inside .wpaim-launch; after first
		// message it moves inside .wpaim-main — both render the same class.
		await page.fill( '.wpaim-composer__input', 'Summarise the benefits of integration testing' );

		// Composer submits on Enter (without Shift) — handleKeyDown in Composer.jsx.
		await page.locator( '.wpaim-composer__input' ).press( 'Enter' );

		// AI message bubbles carry .wpaim-bubble--ai (MessageBubble.jsx line 24).
		// The text is rendered inside .wpaim-bubble__content via MarkdownContent.
		await expect(
			page.locator( '.wpaim-bubble--ai .wpaim-bubble__content' ).last()
		).toContainText( 'uniquely identifiable integration test response', { timeout: 10000 } );
	} );

	test( 'conversation list updates after creating a conversation', async ( { page } ) => {
		// Mock both GET and POST on /conversations so the sidebar shows our fixture title.
		await page.route( isConversationsUrl, async ( route ) => {
			if ( route.request().method() === 'POST' ) {
				await route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( {
						id: 99,
						title: 'Journey Test Conversation',
						created_at: new Date().toISOString(),
					} ),
				} );
			} else if ( route.request().method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [
						{
							id: 99,
							title: 'Journey Test Conversation',
							created_at: new Date().toISOString(),
						},
					] ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-chat' );
		await page.waitForSelector( '.wpaim-shell', { timeout: 10000 } );

		// The sidebar is <aside class="wpaim-sidebar"> (ChatApp.jsx line 301).
		// Conversation titles are rendered in .wpaim-conv-item__title
		// (ConversationHistory.jsx line 54) inside the .wpaim-conv-list <nav>.
		await expect(
			page.locator( '.wpaim-sidebar .wpaim-conv-list' )
		).toContainText( 'Journey Test Conversation', { timeout: 10000 } );
	} );

	test( 'delete conversation button is present in the sidebar', async ( { page } ) => {
		// Seed one conversation via the GET mock.
		await page.route( isConversationsUrl, async ( route ) => {
			if ( route.request().method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [
						{
							id: 7,
							title: 'Deletable Conversation',
							updated_at: new Date().toISOString(),
						},
					] ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-chat' );
		await page.waitForSelector( '.wpaim-shell', { timeout: 10000 } );

		// Each .wpaim-conv-item has a .wpaim-conv-item__delete button
		// (ConversationHistory.jsx line 101).
		await expect(
			page.locator( '.wpaim-conv-item .wpaim-conv-item__delete' ).first()
		).toBeVisible( { timeout: 10000 } );
	} );

	test( 'empty state shows when there are no conversations', async ( { page } ) => {
		// Return an empty array so ConversationHistory renders its empty-state div.
		await page.route( isConversationsUrl, async ( route ) => {
			if ( route.request().method() === 'GET' ) {
				await route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( [] ),
				} );
			} else {
				await route.continue();
			}
		} );

		await page.goto( '/wp-admin/admin.php?page=wp-ai-mind-chat' );
		await page.waitForSelector( '.wpaim-shell', { timeout: 10000 } );

		// Empty-state node is .wpaim-sidebar__empty (ConversationHistory.jsx line 34).
		await expect(
			page.locator( '.wpaim-sidebar .wpaim-sidebar__empty' )
		).toBeVisible( { timeout: 10000 } );
	} );
} );

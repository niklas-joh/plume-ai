// @ts-check
/**
 * PR screenshot capture — one test per screen in screens.json, run once per
 * viewport project (desktop, phone). Each screen is its own test, so one
 * broken screen never blocks the others; the test title is the screen id,
 * which the publish script reads back from results.json.
 *
 * This is a review aid: it asserts only what is needed to know the screen is
 * in the intended state before the shot. Run: npm run test:e2e:screens
 */
const { test, expect } = require( '@playwright/test' );
const { prepare, shoot, json } = require( './stabilise' );
const fx = require( './fixtures' );
const { screens } = require( './screens.json' );

const ADMIN = '/wp-admin/admin.php?page=';
const READY_TIMEOUT = 15_000;

/**
 * Chat mocks shared by every chat state; `messageReply` decides the state.
 *
 * @param {?Object} messageReply Response for POST …/messages, or null for none.
 * @return {Array<Object>} Router handlers for prepare().
 */
const chatHandlers = ( messageReply ) => [
	{
		method: 'GET',
		route: /^\/conversations$/,
		reply: json( 200, fx.CONVERSATIONS ),
	},
	{
		method: 'POST',
		route: /^\/conversations$/,
		reply: json( 201, fx.NEW_CONVERSATION ),
	},
	{
		method: 'PATCH',
		route: /^\/conversations\/\d+$/,
		reply: json( 200, { ...fx.NEW_CONVERSATION, title: fx.RENAMED_TITLE } ),
	},
	{
		method: 'GET',
		route: /^\/conversations\/\d+\/messages$/,
		reply: json( 200, [] ),
	},
	{
		method: 'GET',
		route: /^\/conversations\/\d+\/pending-plan$/,
		reply: json( 200, { pending_plan: null } ),
	},
	{ method: 'GET', route: /^\/providers$/, reply: json( 200, fx.PROVIDERS ) },
	...( messageReply
		? [
				{
					method: 'POST',
					route: /^\/conversations\/\d+\/messages$/,
					reply: messageReply,
				},
			]
		: [] ),
];

/**
 * Opens Chat with the fixture conversations and sends the fixture prompt.
 *
 * @param {import('@playwright/test').Page} page
 * @return {Promise<void>}
 */
async function sendPrompt( page ) {
	await page.goto( `${ ADMIN }plume-chat` );
	await expect( page.locator( '.plume-conv-list' ) ).toContainText(
		fx.CONVERSATIONS[ 0 ].title,
		{ timeout: READY_TIMEOUT }
	);
	const input = page.locator( '.plume-composer__input' ).first();
	await input.fill( fx.USER_PROMPT );
	await input.press( 'Enter' );
}

/**
 * Per-screen capture steps, keyed by screen id. Each returns after the
 * screenshot is written.
 *
 * @type {Record<string, (args: {page: import('@playwright/test').Page, baseURL: string, testInfo: import('@playwright/test').TestInfo}) => Promise<void>>}
 */
const CAPTURE = {
	async dashboard( { page, baseURL, testInfo } ) {
		const log = await prepare( page, { baseURL } );
		await page.goto( `${ ADMIN }plume` );
		await expect( page.locator( '.plume-page-header__title' ) ).toBeVisible(
			{
				timeout: READY_TIMEOUT,
			}
		);
		await expect( page.locator( '.plume-usage-widget' ) ).toBeVisible();
		await shoot( page, testInfo, log, { id: 'dashboard' } );
	},

	async chat( { page, baseURL, testInfo } ) {
		const log = await prepare( page, {
			baseURL,
			handlers: chatHandlers( null ),
		} );
		await page.goto( `${ ADMIN }plume-chat` );
		await expect( page.locator( '.plume-conv-list' ) ).toContainText(
			fx.CONVERSATIONS[ 2 ].title,
			{ timeout: READY_TIMEOUT }
		);
		// The 100dvh app layout has no document scroll: viewport shot.
		await shoot( page, testInfo, log, { id: 'chat', fullPage: false } );
	},

	async 'chat-reply'( { page, baseURL, testInfo } ) {
		const log = await prepare( page, {
			baseURL,
			handlers: chatHandlers( json( 200, fx.REPLY ) ),
		} );
		await sendPrompt( page );
		await expect(
			page.locator( '.plume-bubble--ai .plume-bubble__content' ).last()
		).toContainText( 'Launch post outline', { timeout: READY_TIMEOUT } );
		// The title PATCH follows the reply; shoot after the list is renamed.
		await expect(
			page.locator( '.plume-conv-item__title' ).first()
		).toHaveText( fx.RENAMED_TITLE, { timeout: READY_TIMEOUT } );
		await shoot( page, testInfo, log, {
			id: 'chat-reply',
			fullPage: false,
		} );
	},

	async 'chat-error'( { page, baseURL, testInfo } ) {
		// provider_error: retryable, and not one of the codes ChatApp retries
		// silently (not_registered, auth_failed).
		const log = await prepare( page, {
			baseURL,
			handlers: chatHandlers( json( 500, fx.PROVIDER_ERROR ) ),
		} );
		await sendPrompt( page );
		await expect(
			page.getByRole( 'button', { name: 'Try again' } )
		).toBeVisible( { timeout: READY_TIMEOUT } );
		await shoot( page, testInfo, log, {
			id: 'chat-error',
			fullPage: false,
		} );
	},

	async 'chat-out-of-credits'( { page, baseURL, testInfo } ) {
		const log = await prepare( page, {
			baseURL,
			handlers: chatHandlers( json( 429, fx.OUT_OF_CREDITS ) ),
		} );
		await sendPrompt( page );
		await expect(
			page.locator( '.plume-out-of-credits-banner' )
		).toBeVisible( { timeout: READY_TIMEOUT } );
		await shoot( page, testInfo, log, {
			id: 'chat-out-of-credits',
			fullPage: false,
		} );
	},

	async generator( { page, baseURL, testInfo } ) {
		const log = await prepare( page, { baseURL } );
		await page.goto( `${ ADMIN }plume-generator` );
		await expect( page.locator( '.plume-generator__card' ) ).toBeVisible( {
			timeout: READY_TIMEOUT,
		} );
		await shoot( page, testInfo, log, { id: 'generator' } );
	},

	async seo( { page, baseURL, testInfo } ) {
		const log = await prepare( page, {
			baseURL,
			postLists: { posts: fx.POSTS, pages: fx.PAGES },
		} );
		await page.goto( `${ ADMIN }plume-seo` );
		await expect( page.locator( '.plume-post-list' ) ).toBeVisible( {
			timeout: READY_TIMEOUT,
		} );
		await expect( page.locator( '.plume-list-loading' ) ).toHaveCount( 0 );
		await shoot( page, testInfo, log, { id: 'seo' } );
	},

	async images( { page, baseURL, testInfo } ) {
		const log = await prepare( page, {
			baseURL,
			plumeData: fx.IMAGES_DATA,
			postLists: { posts: fx.POSTS, pages: fx.PAGES },
		} );
		await page.goto( `${ ADMIN }plume-images` );
		await expect( page.locator( '.plume-images-key-notice' ) ).toBeVisible(
			{ timeout: READY_TIMEOUT }
		);
		await expect( page.locator( '.plume-post-list' ) ).toBeVisible( {
			timeout: READY_TIMEOUT,
		} );
		await expect( page.locator( '.plume-list-loading' ) ).toHaveCount( 0 );
		await shoot( page, testInfo, log, { id: 'images' } );
	},

	async usage( { page, baseURL, testInfo } ) {
		// UsageDashboard fetches only GET /usage; it has no other REST calls.
		const log = await prepare( page, {
			baseURL,
			handlers: [
				{
					method: 'GET',
					route: /^\/usage$/,
					reply: json( 200, fx.USAGE ),
				},
			],
		} );
		await page.goto( `${ ADMIN }plume-usage` );
		await expect( page.locator( '.plume-usage__stats' ) ).toBeVisible( {
			timeout: READY_TIMEOUT,
		} );
		await expect( page.locator( '.plume-progress' ) ).toBeVisible();
		await shoot( page, testInfo, log, { id: 'usage' } );
	},

	async settings( { page, baseURL, testInfo } ) {
		const log = await prepare( page, {
			baseURL,
			handlers: [
				{
					method: 'GET',
					route: /^\/settings$/,
					reply: json( 200, fx.SETTINGS ),
				},
			],
		} );
		await page.goto( `${ ADMIN }plume-settings` );
		await expect( page.locator( '.plume-settings-shell' ) ).toBeVisible( {
			timeout: READY_TIMEOUT,
		} );
		await expect( page.locator( '.plume-providers-tab' ) ).toBeVisible( {
			timeout: READY_TIMEOUT,
		} );
		await expect( page.locator( '.plume-settings-loading' ) ).toHaveCount(
			0
		);
		await shoot( page, testInfo, log, { id: 'settings' } );
	},

	async 'editor-sidebar'( { page, baseURL, testInfo } ) {
		const log = await prepare( page, { baseURL } );
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() =>
				// @ts-ignore -- wp is the block editor's global.
				!! window.wp?.data
					?.select( 'core/editor' )
					?.getCurrentPostId?.(),
			null,
			{ timeout: READY_TIMEOUT }
		);
		await page.evaluate( () => {
			// @ts-ignore -- wp is the block editor's global.
			const { dispatch } = window.wp.data;
			const prefs = dispatch( 'core/preferences' );
			prefs?.set( 'core/edit-post', 'welcomeGuide', false );
			prefs?.set( 'core', 'enableChoosePatternModal', false );
			// registerPlugin( 'plume' ) + PluginSidebar name="plume-sidebar".
			const target = 'plume/plume-sidebar';
			const editPost = dispatch( 'core/edit-post' );
			if ( editPost?.openGeneralSidebar ) {
				editPost.openGeneralSidebar( target );
			} else {
				dispatch( 'core/interface' ).enableComplementaryArea(
					'core',
					target
				);
			}
		} );
		const sidebar = page.locator( '.plume-editor-sidebar' );
		await expect( sidebar ).toBeVisible( { timeout: READY_TIMEOUT } );
		// Any modal still open (welcome guide, pattern chooser) is dismissed.
		const modal = page.locator( '.components-modal__frame' );
		if ( await modal.count() ) {
			await page.keyboard.press( 'Escape' );
			await expect( modal ).toHaveCount( 0 );
			await expect( sidebar ).toBeVisible();
		}
		// The post canvas is an iframe with its own fonts (block themes).
		const canvas = page.locator( 'iframe[name="editor-canvas"]' );
		if ( await canvas.count() ) {
			const frame = canvas.contentFrame();
			await expect( frame.locator( '.editor-post-title' ) ).toBeVisible( {
				timeout: READY_TIMEOUT,
			} );
			await frame
				.locator( 'body' )
				.evaluate( () => document.fonts.ready.then( () => true ) );
		}
		// The block editor rarely reaches network idle (autosave, heartbeat).
		await shoot( page, testInfo, log, {
			id: 'editor-sidebar',
			fullPage: false,
			networkIdle: false,
		} );
	},
};

for ( const { id } of screens ) {
	test( id, async ( { page, baseURL }, testInfo ) => {
		const capture = CAPTURE[ id ];
		if ( ! capture ) {
			throw new Error(
				`screens.json lists "${ id }" but screens.spec.js has no capture for it.`
			);
		}
		await capture( { page, baseURL: baseURL || '', testInfo } );
	} );
}

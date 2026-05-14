// @ts-check
/**
 * Shared Playwright login helper.
 *
 * Navigates to /wp-login.php and authenticates using the credentials supplied
 * via WP_TEST_USER / WP_TEST_PASS environment variables (see .env.example).
 *
 * @param {import('@playwright/test').Page} page
 */
async function wpLogin( page ) {
	await page.goto( '/wp-login.php' );
	await page.waitForSelector( '#user_login', { state: 'visible' } );
	await page.fill( '#user_login', process.env.WP_TEST_USER ?? 'nj_agent' );
	await page.fill( '#user_pass', process.env.WP_TEST_PASS ?? '' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

module.exports = { wpLogin };

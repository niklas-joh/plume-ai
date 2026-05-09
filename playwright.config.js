// playwright.config.js
// Supports both localhost:8888 (wp-env) and localhost:8080 (blog Docker).
// Override via WP_BASE_URL env var.
const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/E2E/playwright',
	timeout: 60_000,
	retries: process.env.CI ? 2 : 0,
	workers: process.env.CI ? 1 : undefined,
	globalSetup: require.resolve( './tests/E2E/playwright/global-setup.js' ),
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: '.artifacts/playwright-report', open: 'never' } ],
		[ 'junit', { outputFile: '.artifacts/playwright-report/junit.xml' } ],
	],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		headless: true,
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );

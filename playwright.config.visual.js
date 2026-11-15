// playwright.config.visual.js
// PR screenshot capture (review aid, not a pass/fail check). Kept apart from
// playwright.config.js so the normal suite, its globalSetup, retries and
// report folder are untouched. Run with: npm run test:e2e:screens
const { defineConfig, devices } = require( '@playwright/test' );

const OUT = '.artifacts/screenshots/ci';

module.exports = defineConfig( {
	testDir: './tests/E2E/playwright/visual',
	testMatch: 'screens.spec.js',
	timeout: 45_000,
	// A retry would hide flakiness, and a missing shot is reported, not fatal.
	retries: 0,
	workers: process.env.CI ? 2 : undefined,
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	globalSetup:
		require.resolve( './tests/E2E/playwright/visual/visual-setup.js' ),
	outputDir: `${ OUT }/.test-results`,
	reporter: [
		[ 'list' ],
		[ 'json', { outputFile: `${ OUT }/results.json` } ],
	],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		headless: true,
		storageState: `${ OUT }/.auth.json`,
		locale: 'en-GB',
		timezoneId: 'UTC',
		colorScheme: 'light',
		reducedMotion: 'reduce',
		screenshot: 'off',
		video: 'off',
		trace: 'off',
	},
	projects: [
		{
			name: 'desktop',
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 1440, height: 900 },
				deviceScaleFactor: 1,
			},
		},
		{
			// Chromium at an iPhone-13-sized viewport. Not devices['iPhone 13'],
			// which defaults to WebKit — neither cached nor installed in CI.
			name: 'phone',
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 390, height: 844 },
				deviceScaleFactor: 2,
				isMobile: true,
				hasTouch: true,
			},
		},
	],
} );

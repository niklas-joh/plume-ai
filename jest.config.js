// Self-contained Jest configuration for the JS unit suite in tests/js.
// @wordpress/scripts 37 no longer ships a Jest preset (its default runner is
// Vitest), so everything the old preset provided is declared here instead.
module.exports = {
	testEnvironment: 'jsdom',
	testMatch: [ '<rootDir>/tests/js/**/*.test.[jt]s?(x)' ],
	transform: {
		'\\.[jt]sx?$': [
			'babel-jest',
			{ presets: [ '@wordpress/babel-preset-default' ] },
		],
	},
	// Sets IS_REACT_ACT_ENVIRONMENT=true so React's act() works in jsdom
	// without emitting "not configured to support act()" warnings.
	setupFilesAfterEnv: [ '<rootDir>/tests/js/setup.js' ],
	moduleNameMapper: {
		// Components import their stylesheets; Jest cannot parse CSS.
		'\\.(css|scss)$': '<rootDir>/tests/js/__mocks__/style.js',
		// Several @wordpress/* packages are webpack externals — not installed as
		// standalone npm packages. Map them to minimal stubs so Jest can resolve
		// the imports; individual tests may override behaviour via jest.mock().
		'^@wordpress/api-fetch$': '<rootDir>/tests/js/__mocks__/api-fetch.js',
		'^@wordpress/i18n$': '<rootDir>/tests/js/__mocks__/i18n.js',
		'^@wordpress/components$': '<rootDir>/tests/js/__mocks__/components.js',
		// marked ships as pure ESM — Jest (CJS mode) cannot parse it. Redirect to
		// the UMD build which uses CommonJS-compatible module.exports syntax.
		'^marked$': '<rootDir>/node_modules/marked/lib/marked.umd.js',
	},
};

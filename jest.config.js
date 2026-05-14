// Extend the @wordpress/scripts unit-test preset with project-specific overrides.
// The preset provides jsdom, babel-jest transforms, CSS mocking, and
// @wordpress/jest-console matchers.
const base = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...base,
	// Append a project-level setup file after the preset's own setupFilesAfterEnv.
	// Sets IS_REACT_ACT_ENVIRONMENT=true so React 18's act() works in jsdom
	// without emitting warnings that @wordpress/jest-console treats as failures.
	setupFilesAfterEnv: [
		...( base.setupFilesAfterEnv ?? [] ),
		'<rootDir>/tests/js/setup.js',
	],
	moduleNameMapper: {
		...base.moduleNameMapper,
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

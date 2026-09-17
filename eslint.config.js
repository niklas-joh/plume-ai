const wordpress = require( '@wordpress/eslint-plugin' );
const jsdocPlugin = require( 'eslint-plugin-jsdoc' );

module.exports = [
	// Global ignores — an object with ONLY an `ignores` key applies to every
	// entry in this array, replacing .eslintignore. All entries below use
	// `**/` prefixes because flat config's `ignores` anchors to repo root by
	// default, unlike .eslintignore's gitignore-style any-depth matching —
	// this repo has real nested matches (dist/wp-ai-mind/assets/, .worktrees/*/)
	// that depend on any-depth behavior.
	{
		ignores: [
			'**/assets/**',
			'**/vendor/**',
			'**/tests/**',
			'**/plume-proxy/**',
			'**/playwright.config.js',
			// Gitignored sibling git worktrees (see .gitignore) can contain
			// in-progress code from other branches; they must never be linted
			// as part of this repo's own source.
			'**/.worktrees/**',
			// .agents is a git submodule (see .gitmodules) pointing at a
			// separate external repo with its own coding conventions — not
			// this repo's own source, so it must never be linted here.
			'**/.agents/**',
			// node_modules/** is ignored by flat config's built-in defaults —
			// no explicit entry needed.
		],
	},

	// @wordpress/eslint-plugin bundles its own (older, v50.8.0) eslint-plugin-jsdoc
	// copy and registers it under the `jsdoc` plugin key in one isolated entry of
	// `configs.recommended` (name: 'jsdoc/flat/recommended'). Flat config throws if
	// the same plugin key is registered twice with different object instances, so
	// that entry is filtered out here — its role (registering `jsdoc` + baseline
	// severities) is filled instead by our own, newer, directly-installed
	// `eslint-plugin-jsdoc`, whose baseline is appended *after* the whole WP
	// config block below (not spliced in at the removed entry's original index).
	// WP's own jsdoc rule-severity tuning (the entry that carries no `plugins`
	// key) still lives inside that spread and applies first. The net precedence
	// is therefore: WP severity tuning → our v64 `recommended-error` baseline →
	// our own overrides below — the same three-layer stack as the old
	// `.eslintrc.js`'s `extends` order, just re-expressed as flat-config order.
	...wordpress.configs.recommended.filter(
		( config ) => ! ( config.plugins && config.plugins.jsdoc )
	),
	jsdocPlugin.configs[ 'flat/recommended-error' ],

	{
		settings: {
			jsdoc: {
				mode: 'typescript',
				tagNamePreference: {
					returns: 'return',
				},
			},
		},
		rules: {
			'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/' ] } ],
			'jsdoc/require-jsdoc': [
				'error',
				{
					publicOnly: true,
					require: {
						FunctionDeclaration: true,
						ArrowFunctionExpression: true,
						FunctionExpression: false,
						MethodDefinition: false,
					},
					checkConstructors: false,
				},
			],
			'jsdoc/require-returns-description': 'off',
			'jsdoc/require-param-description': 'off',
			'jsdoc/check-tag-names': [ 'error', { definedTags: [ 'throws' ] } ],
			'jsdoc/no-undefined-types': 'off',
			'jsdoc/reject-function-type': 'off',
			'jsdoc/check-param-names': 'off',
		},
	},
];

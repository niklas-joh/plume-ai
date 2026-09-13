const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin/index': path.resolve( __dirname, 'src/admin/index.js' ),
		'editor/index': path.resolve( __dirname, 'src/editor/index.js' ),
		'generator/index': path.resolve( __dirname, 'src/generator/index.js' ),
		'usage/index': path.resolve( __dirname, 'src/usage/index.js' ),
		'seo/index': path.resolve( __dirname, 'src/seo/index.js' ),
		'images/index': path.resolve( __dirname, 'src/images/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets' ),
		clean: {
			keep: /plume-admin-widgets\.css/,
		},
	},
};

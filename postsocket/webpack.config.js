const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		editor: path.resolve( __dirname, 'src/editor/index.tsx' ),
		settings: path.resolve( __dirname, 'src/settings/index.tsx' ),
		'blocks/live-feed/index': path.resolve( __dirname, 'src/blocks/live-feed/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		// wp-scripts cleans build/ before every compile; the reader modules are a tsc emit into build/reader/.
		clean: { keep: /^(fonts|images|reader)\// },
	},
};

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

/**
 * Packages taken from core as `wp.*` globals; every other `@wordpress/*` package is bundled.
 *
 * - DataViews 19 and `@wordpress/ui` need components 40, which core does not ship.
 * - The data stores, the editor and the block APIs stay core's, since a bundled copy would be a second registry.
 */
const FROM_CORE = new Set( [
	'@wordpress/api-fetch',
	'@wordpress/block-editor',
	'@wordpress/blocks',
	'@wordpress/core-data',
	'@wordpress/data',
	'@wordpress/editor',
	'@wordpress/element',
	'@wordpress/hooks',
	'@wordpress/html-entities',
	'@wordpress/i18n',
	'@wordpress/notices',
	'@wordpress/plugins',
	'@wordpress/url',
] );

module.exports = {
	...defaultConfig,
	entry: {
		editor: path.resolve( __dirname, 'src/editor/index.tsx' ),
		settings: path.resolve( __dirname, 'src/settings/index.tsx' ),
		desk: path.resolve( __dirname, 'src/desk/index.tsx' ),
		'blocks/live-feed/index': path.resolve( __dirname, 'src/blocks/live-feed/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		// wp-scripts cleans build/ before every compile; the reader modules are a tsc emit into build/reader/.
		clean: { keep: /^(fonts|images|reader)\// },
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			// These packages declare no side effects, which would drop the stylesheets the bundles import.
			{ test: /@wordpress[\\/](theme|components|dataviews)[\\/].*\.css$/, sideEffects: true },
		],
	},
	plugins: [
		...defaultConfig.plugins.filter( ( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' ),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				if ( request.startsWith( '@wordpress/' ) && ! FROM_CORE.has( request ) ) {
					return false;
				}
				return undefined;
			},
		} ),
	],
};

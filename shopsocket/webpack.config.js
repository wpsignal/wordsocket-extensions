const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

/**
 * Packages the board takes from core as `wp.*` globals. Every other
 * `@wordpress/*` package is bundled: DataViews 19 needs components 40, which
 * core does not ship, along with its own compose, private-apis, and friends.
 */
const FROM_CORE = new Set( [
	'@wordpress/element',
	'@wordpress/i18n',
	'@wordpress/hooks',
	'@wordpress/api-fetch',
	'@wordpress/blocks',
	'@wordpress/block-editor',
] );

module.exports = {
	...defaultConfig,
	entry: {
		board: path.resolve( __dirname, 'src/board/index.tsx' ),
		'blocks/live-stock/index': path.resolve( __dirname, 'src/blocks/live-stock/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		/*
		 * wp-scripts cleans `build/` before every compile (start included), keeping
		 * only fonts and images; keep the storefront module too, which `tsc`
		 * emits there (`tsconfig.storefront.json`) rather than webpack.
		 */
		clean: { keep: /^(fonts|images|storefront)\// },
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				if ( request.startsWith( '@wordpress/' ) && ! FROM_CORE.has( request ) ) {
					return false; // bundle it
				}
				return undefined; // default handling (wp.* globals, react, lodash, ...)
			},
		} ),
	],
};

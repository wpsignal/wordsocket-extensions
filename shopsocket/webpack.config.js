const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const path = require( 'path' );

/**
 * WordPress packages the board takes from core (`wp.*` globals). Everything
 * else under `@wordpress/` is bundled: DataViews 19 needs `@wordpress/components`
 * 40, which core does not ship, and that in turn needs its own `compose`,
 * `private-apis`, `ui`, and friends at matching versions.
 */
const FROM_CORE = new Set( [
	'@wordpress/element',
	'@wordpress/i18n',
	'@wordpress/hooks',
	'@wordpress/api-fetch',
] );

module.exports = {
	...defaultConfig,
	entry: {
		board: path.resolve( __dirname, 'src/board/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		// wp-scripts cleans `build/` before every compile (start included), keeping
		// only fonts and images; keep the storefront module too, which `tsc`
		// emits there (`tsconfig.storefront.json`) rather than webpack.
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

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * External dependencies
 */
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
const rtlcss = require( 'rtlcss' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: './src/js/admin.js',
		'item-form': './src/js/item-form.js',
		'admin-style': './src/css/admin.css',
		'item-form-style': './src/css/item-form.css',
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		filename: 'js/[name].min.js',
	},
	plugins: [
		// Include WP's plugin config, but replace MiniCssExtractPlugin and RtlCssPlugin
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'MiniCssExtractPlugin' &&
				plugin.constructor.name !== 'RtlCssPlugin'
		),
		// Customize MiniCssExtractPlugin to output CSS to css/ folder
		new MiniCssExtractPlugin( {
			filename: ( pathData ) => {
				const entryName = pathData.chunk.name;
				// CSS entry points like 'admin-style' become 'css/admin.min.css'
				if ( entryName.endsWith( '-style' ) ) {
					const cssName = entryName.replace( '-style', '' );
					return `css/${ cssName }.min.css`;
				}
				return 'css/[name].min.css';
			},
		} ),
		// Custom RTL plugin that respects css/ output folder
		{
			apply: ( compiler ) => {
				compiler.hooks.compilation.tap( 'RtlCssPlugin', ( compilation ) => {
					compilation.hooks.processAssets.tap(
						{
							name: 'RtlCssPlugin',
							stage: compiler.webpack.Compilation.PROCESS_ASSETS_STAGE_OPTIMIZE,
						},
						() => {
							const assets = compilation.getAssets();
							assets.forEach( ( asset ) => {
								const filename = asset.name;
								// Process CSS files in css/ folder and generate RTL versions
								if ( filename.endsWith( '.css' ) && filename.startsWith( 'css/' ) ) {
									const cssSource = asset.source.source();
									const rtlCss = rtlcss.process( cssSource );
									// Generate RTL filename: css/admin.min.css -> css/admin-rtl.css
									const rtlFilename = filename.replace( /\.min\.css$/, '-rtl.css' );
									if ( rtlFilename !== filename ) {
										compilation.emitAsset( rtlFilename, {
											source: () => rtlCss,
											size: () => rtlCss.length,
										} );
									}
								}
							} );
						}
					);
				} );
			},
		},
	],
};

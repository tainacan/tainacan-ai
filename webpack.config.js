/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: './src/js/admin.js',
		'item-form': './src/js/item-form.js',
		'admin-style': './src/css/admin.css',
		'item-form-style': './src/css/item-form.css',
	},
};

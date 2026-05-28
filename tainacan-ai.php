<?php
/**
 * Plugin Name: Tainacan AI
 * Plugin URI: https://github.com/tainacan/tainacan-ai
 * Description: Automated metadata extraction for Tainacan using WordPress AI and Connectors. Images, PDFs, and custom prompts.
 * Version: 0.1.0
 * Author: Sigismundo
 * Author URI: https://seu-site.com
 * Text Domain: tainacan-ai
 * Domain Path: /languages
 * License: GPL v2 or later
 * Requires Plugins: tainacan
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 *
 * @package Tainacan_AI
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TAINACAN_AI_PLUGIN_FILE' ) ) {
	define( 'TAINACAN_AI_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'TAINACAN_AI_VERSION' ) ) {
	define( 'TAINACAN_AI_VERSION', '0.1.0' );
}
if ( ! defined( 'TAINACAN_AI_PLUGIN_DIR' ) ) {
	define( 'TAINACAN_AI_PLUGIN_DIR', plugin_dir_path( TAINACAN_AI_PLUGIN_FILE ) );
}
if ( ! defined( 'TAINACAN_AI_PLUGIN_URL' ) ) {
	define( 'TAINACAN_AI_PLUGIN_URL', plugin_dir_url( TAINACAN_AI_PLUGIN_FILE ) );
}
if ( ! defined( 'TAINACAN_AI_DOMAIN' ) ) {
	define( 'TAINACAN_AI_DOMAIN', 'tainacan-ai' );
}

$tainacan_ai_autoload_file = TAINACAN_AI_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! file_exists( $tainacan_ai_autoload_file ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Tainacan AI is missing Composer dependencies. Run "composer install" in the plugin directory before activating it.',
				'tainacan-ai'
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once $tainacan_ai_autoload_file;

\Tainacan\AI\Plugin::get_instance();

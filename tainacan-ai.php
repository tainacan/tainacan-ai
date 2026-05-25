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

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TAINACAN_AI_VERSION', '0.1.0');
define('TAINACAN_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAINACAN_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TAINACAN_AI_DOMAIN', 'tainacan-ai');
/**
 * Plugin autoloader
 */
spl_autoload_register(function ($class) {
    $prefix = 'Tainacan\\AI\\';
    $base_dir = TAINACAN_AI_PLUGIN_DIR . 'includes/';
    $lib_dir = TAINACAN_AI_PLUGIN_DIR . 'lib/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);

    // First try in includes/ directory
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
        return;
    }

    // Then try in lib/ directory (embedded libraries)
    $file = $lib_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
        return;
    }
});

// Load Composer autoloader if it exists
if (file_exists(TAINACAN_AI_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once TAINACAN_AI_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Main plugin class
 */
final class Tainacan_AI {

    private static ?Tainacan_AI $instance = null;

    /**
     * Singleton
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'init'], 20);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // WP Consent API integration
        $this->init_consent_api();
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('Tainacan AI requires PHP 8.0 or higher.', 'tainacan-ai'),
                esc_html__('Activation Error', 'tainacan-ai'),
                ['back_link' => true]
            );
        }

        $this->set_default_options();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        global $wpdb;

        // Clear transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tainacan_ai_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tainacan_ai_%'");

        flush_rewrite_rules();
    }

    /**
     * Set default options
     */
    private function set_default_options(): void {
        $default_options = [
            'default_prompt' => \Tainacan\AI\PromptTemplates::get_default_prompt(),
            'max_tokens' => 2000,
            'temperature' => 0.1,
            'request_timeout' => 120,
            'cache_duration' => 3600,
            'extract_exif' => true,
            'consent_required' => true,
        ];

        $existing = get_option('tainacan_ai_options', []);
        update_option('tainacan_ai_options', wp_parse_args($existing, $default_options));
    }

    /**
     * WP Consent API integration
     */
    private function init_consent_api(): void {
        // Register plugin in Consent API
        $plugin = plugin_basename(__FILE__);
        add_filter("wp_consent_api_registered_{$plugin}", '__return_true');

        // Register cookie/data information
        add_action('wp_enqueue_scripts', function() {
            if (function_exists('wp_add_cookie_info')) {
                wp_add_cookie_info(
                    'tainacan_ai_cache',
                    __('AI analysis cache', 'tainacan-ai'),
                    'functional',
                    __('Stores analysis results to avoid repeated API calls', 'tainacan-ai'),
                    false,
                    false,
                    false
                );
            }
        });
    }

    /**
     * Check user consent
     */
    public static function has_consent(): bool {
        $options = get_option('tainacan_ai_options', []);

        // If consent is not required, return true
        if (empty($options['consent_required'])) {
            return true;
        }

        // Check via WP Consent API
        if (function_exists('wp_has_consent')) {
            return wp_has_consent('functional');
        }

        // Fallback: always allow for admins
        return current_user_can('manage_options');
    }

    /**
     * Initialize plugin components
     */
    public function init(): void {
        $this->set_default_options();

        // Check if Tainacan is active
        if (!class_exists('\Tainacan\Repositories\Items')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('Tainacan AI requires the Tainacan plugin to be active.', 'tainacan-ai');
                echo '</p></div>';
            });
            return;
        }

        // Initialize admin page
        if (class_exists('\Tainacan\Pages')) {
            \Tainacan\AI\AdminPage::get_instance();
        }

        // Initialize components
        new \Tainacan\AI\API();
        new \Tainacan\AI\ItemFormHook();
        new \Tainacan\AI\CollectionFormHook();
        new \Tainacan\AI\MetadatumFormHook();

        \Tainacan\AI\ExtractionMetadata::get_instance()->init_hooks();
        new \Tainacan\AI\CollectionPrompts();

        add_action('wpai_features_initialized', [\Tainacan\AI\CoreAIRequestLogging::class, 'register_integration']);
    }

    /**
     * Settings link
     */
    public function add_settings_link(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=tainacan_ai'),
            __('Settings', 'tainacan-ai')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Get plugin options
     */
    public static function get_options(): array {
        return get_option('tainacan_ai_options', []);
    }

    /**
     * Get a specific option
     */
    public static function get_option(string $key, mixed $default = null): mixed {
        $options = self::get_options();
        return $options[$key] ?? $default;
    }
}

// Initialize the plugin
Tainacan_AI::get_instance();

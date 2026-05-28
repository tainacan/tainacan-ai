<?php
namespace Tainacan\AI\Admin;

use Tainacan\AI\Extraction\PromptTemplates;
use Tainacan\AI\Support\CoreAI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin page for the plugin using Tainacan Pages API (1.0+)
 */
class AdminPage extends \Tainacan\Pages {
    use \Tainacan\Traits\Singleton_Instance;

    /**
     * Page slug
     */
    protected function get_page_slug(): string {
        return 'tainacan_ai';
    }

    /**
     * Initialization
     */
    public function init() {
        parent::init();

        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Register admin menu
     */
    public function add_admin_menu() {
        $page_suffix = add_submenu_page(
            $this->tainacan_other_links_slug,
            __('AI Tools', 'tainacan-ai'),
            '<span class="icon">' . $this->get_ai_icon() . '</span>' .
            '<span class="menu-text">' . __('AI Tools', 'tainacan-ai') . '</span>',
            'manage_options',
            $this->get_page_slug(),
            [$this, 'render_page'],
            10
        );

        add_action('load-' . $page_suffix, [$this, 'load_page']);
    }

    /**
     * Custom SVG icon
     */
    private function get_ai_icon(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" xml:space="preserve" id="svg5" width="32" height="32" version="1.1" viewBox="0 0 8.467 8.467">
            <g id="layer1" transform="translate(-51.439 -147.782)"><path id="path11554" d="m58.994 153.057-.247.062-.349.082c.124.134.217.267.282.396.158.318.161.607.012.927v.002l-.005.007c-.172.37-.412.548-.824.616-.002 0-.004 0-.005.002-.074.012-.16.018-.257.018-.383 0-.864-.118-1.415-.372l-.009-.005a.534.534 0 0 0-.078-.033 4.111 4.111 0 0 1-.427-.191h-.004c-.016.064-.03.131-.05.21a3.34 3.34 0 0 1-.083.302l-.01.029c.144.07.273.124.38.164l.037.019c.608.282 1.165.426 1.658.426.122 0 .24-.007.352-.026a1.588 1.588 0 0 0 1.235-.927l.003-.007c.215-.46.212-.95-.014-1.405-.051-.102-.111-.2-.182-.297z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path11552" d="M57.188 148.868c-.079 0-.16.006-.241.017-.359.047-.732.2-1.112.455.028.116.055.228.077.311.095.025.226.055.36.087.266-.158.536-.28.748-.307.366-.05.646.046.91.31h.003v.002c.27.272.363.549.314.915a1.85 1.85 0 0 1-.213.62l.055.216c.01.04.017.061.026.091.03.01.053.016.094.027l.238.058c.19-.32.306-.634.346-.94a1.592 1.592 0 0 0-.467-1.375l-.004-.003a1.583 1.583 0 0 0-1.134-.484z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path1" d="M53.574 148.312a1.671 1.671 0 0 0-.67.161l-.006.003c-1.05.493-1.246 1.706-.527 3.248l.015.034c.122.323.356.82.783 1.372a5.33 5.33 0 0 0-.208 1.435v.148h.148c.285 0 .731-.031 1.282-.17-.036-.007-.067-.016-.108-.025a3.406 3.406 0 0 1-.301-.083.791.791 0 0 1-.16-.071.441.441 0 0 1-.222-.295h-.002c-.028-.15 0-.435.101-.79a.55.55 0 0 0-.096-.486 4.834 4.834 0 0 1-.717-1.266l-.016-.034c-.328-.704-.421-1.293-.356-1.697.066-.404.238-.642.618-.82l.004-.002c.168-.078.323-.117.476-.115v-.001c.153 0 .305.042.465.122.15.075.33.237.496.415l.03-.121c.033-.14.067-.281.11-.409l.036-.092v-.001a2.172 2.172 0 0 0-.424-.284 1.605 1.605 0 0 0-.75-.176z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><g id="path6974" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.07603 0 0 1.0728 -16.96 -11.535)"><path id="path10029" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6968" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.51152 0 0 1.50697 -44.11 -74.969)"><path id="path10038" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6976" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(.77239 0 0 .77006 3.277 37.782)"><path id="path10046" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g></g>
        </svg>';
    }

    /**
     * Enqueue CSS
     */
    public function admin_enqueue_css() {
        $asset_file = TAINACAN_AI_PLUGIN_DIR . 'build/admin-style.asset.php';
        $asset = file_exists($asset_file) ? require $asset_file : ['dependencies' => [], 'version' => TAINACAN_AI_VERSION];
        
        wp_enqueue_style(
            'tainacan-ai-admin',
            TAINACAN_AI_PLUGIN_URL . 'build/admin-style.css',
            $asset['dependencies'],
            $asset['version']
        );
    }

    /**
     * Enqueue JavaScript
     */
    public function admin_enqueue_js() {
        $asset_file = TAINACAN_AI_PLUGIN_DIR . 'build/admin.asset.php';
        $asset = file_exists($asset_file) ? require $asset_file : ['dependencies' => [], 'version' => TAINACAN_AI_VERSION];
        
        wp_enqueue_script(
            'tainacan-ai-admin',
            TAINACAN_AI_PLUGIN_URL . 'build/admin.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_localize_script('tainacan-ai-admin', 'TainacanAIAdmin', [
            'restUrl' => rest_url('tainacan-ai/v1/'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'promptTemplates' => PromptTemplates::get_templates(),
            'texts' => [
                'error' => __('Something went wrong. Please try again.', 'tainacan-ai'),
                'clearing' => __('Clearing cache...', 'tainacan-ai'),
                'cacheCleared' => __('Cache cleared successfully!', 'tainacan-ai'),
                'saving' => __('Saving...', 'tainacan-ai'),
                'saved' => __('Saved successfully!', 'tainacan-ai'),
                'loading' => __('Loading...', 'tainacan-ai'),
                'confirmReplacePreambleTemplate' => __('Replace the current preamble with this template?', 'tainacan-ai'),
                'confirmClearCache' => __('Are you sure you want to clear all cache?', 'tainacan-ai'),
            ]
        ]);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('tainacan_ai_options', 'tainacan_ai_options', [$this, 'validate_options']);
    }

    /**
     * Validate options
     */
    public function validate_options($input) {
        $options = get_option('tainacan_ai_options', []);

        // Long text fields (prompts)
        $textarea_fields = ['default_preamble'];
        foreach ($textarea_fields as $field) {
            if (isset($input[$field])) {
                $options[$field] = wp_kses_post($input[$field]);
            }
        }

        // Numeric fields
        $numeric_fields = ['max_tokens', 'cache_duration'];
        foreach ($numeric_fields as $field) {
            if (isset($input[$field])) {
                $options[$field] = absint($input[$field]);
            }
        }

        if (isset($input['request_timeout'])) {
            $timeout_bounds = CoreAI::get_request_timeout_bounds();
            $options['request_timeout'] = max(
                $timeout_bounds['min'],
                min($timeout_bounds['max'], absint($input['request_timeout']))
            );
        }

        if (isset($input['temperature'])) {
            $temperature_bounds = CoreAI::get_temperature_bounds();
            $options['temperature'] = max(
                $temperature_bounds['min'],
                min($temperature_bounds['max'], (float) $input['temperature'])
            );
        }

        // Checkboxes
        $checkbox_fields = ['extract_exif', 'consent_required'];
        foreach ($checkbox_fields as $field) {
            $options[$field] = !empty($input[$field]);
        }

        return $options;
    }

    /**
     * Render page content
     */
    public function render_page_content() {
        $options = get_option('tainacan_ai_options', []);

        include __DIR__ . '/settings-page.php';
    }

}

<?php
namespace Tainacan\AI;

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
        add_action('wp_ajax_tainacan_ai_test_api', [$this, 'ajax_test_api']);
        add_action('wp_ajax_tainacan_ai_clear_cache', [$this, 'ajax_clear_cache']);
        add_action('wp_ajax_tainacan_ai_get_stats', [$this, 'ajax_get_stats']);
        add_action('wp_ajax_tainacan_ai_export_logs', [$this, 'ajax_export_logs']);
        add_action('wp_ajax_tainacan_ai_get_collection_metadata', [$this, 'ajax_get_collection_metadata']);
        add_action('wp_ajax_tainacan_ai_save_mapping', [$this, 'ajax_save_mapping']);
        add_action('wp_ajax_tainacan_ai_get_mapping', [$this, 'ajax_get_mapping']);
        add_action('wp_ajax_tainacan_ai_auto_detect_mapping', [$this, 'ajax_auto_detect_mapping']);
    }

    /**
     * Register admin menu
     */
    public function add_admin_menu() {
        $page_suffix = add_submenu_page(
            $this->tainacan_root_menu_slug,
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

        // Collections list for custom prompts
        $collections = $this->get_collections_list();

        wp_localize_script('tainacan-ai-admin', 'TainacanAIAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tainacan_ai_admin_nonce'),
            'collections' => $collections,
            'texts' => [
                'testing' => __('Testing API...', 'tainacan-ai'),
                'success' => __('Connection successful!', 'tainacan-ai'),
                'error' => __('Connection failed. Check your API key.', 'tainacan-ai'),
                'clearing' => __('Clearing cache...', 'tainacan-ai'),
                'cacheCleared' => __('Cache cleared successfully!', 'tainacan-ai'),
                'saving' => __('Saving...', 'tainacan-ai'),
                'saved' => __('Saved successfully!', 'tainacan-ai'),
                'confirmReset' => __('Are you sure you want to reset to the default prompt?', 'tainacan-ai'),
                'generating' => __('Generating suggestion...', 'tainacan-ai'),
                'loading' => __('Loading...', 'tainacan-ai'),
                'confirmClearCache' => __('Are you sure you want to clear all cache?', 'tainacan-ai'),
                'selectCollectionFirst' => __('Select a collection first.', 'tainacan-ai'),
                'loadingMetadata' => __('Loading metadata...', 'tainacan-ai'),
                'errorLoadingMetadata' => __('Error loading metadata.', 'tainacan-ai'),
                'doNotMap' => __('-- Do not map --', 'tainacan-ai'),
                'remove' => __('Remove', 'tainacan-ai'),
                'addCustomField' => __('Add custom field', 'tainacan-ai'),
                'aiFieldName' => __('AI field name', 'tainacan-ai'),
                'detecting' => __('Detecting...', 'tainacan-ai'),
                'fieldsDetected' => __('field(s) detected automatically!', 'tainacan-ai'),
                'errorDetectingMapping' => __('Error detecting mapping.', 'tainacan-ai'),
                'confirmClearMapping' => __('Are you sure you want to clear all mapping?', 'tainacan-ai'),
                'mappingCleared' => __('Mapping cleared. Click "Save" to confirm.', 'tainacan-ai'),
                'useDefaultPrompt' => __('Leave blank to use default prompt...', 'tainacan-ai'),
                'suggestionGenerated' => __('Suggestion generated! Review, adjust and click "Save Prompt".', 'tainacan-ai'),
                'errorGeneratingSuggestion' => __('Error generating suggestion.', 'tainacan-ai'),
                'close' => __('Close', 'tainacan-ai'),
                'copy' => __('Copy', 'tainacan-ai'),
                'copied' => __('Copied!', 'tainacan-ai'),
                'title' => __('Title', 'tainacan-ai'),
                'description' => __('Description', 'tainacan-ai'),
                'author' => __('Author', 'tainacan-ai'),
                'date' => __('Date', 'tainacan-ai'),
                'subject' => __('Subject', 'tainacan-ai'),
                'type' => __('Type', 'tainacan-ai'),
                'format' => __('Format', 'tainacan-ai'),
                'language' => __('Language', 'tainacan-ai'),
                'source' => __('Source', 'tainacan-ai'),
                'rights' => __('Rights', 'tainacan-ai'),
                'coverage' => __('Coverage', 'tainacan-ai'),
                'publisher' => __('Publisher', 'tainacan-ai'),
                'contributor' => __('Contributor', 'tainacan-ai'),
                'relation' => __('Relation', 'tainacan-ai'),
                'identifier' => __('Identifier', 'tainacan-ai'),
            ]
        ]);
    }

    /**
     * Get collections list
     */
    private function get_collections_list(): array {
        if (!class_exists('\Tainacan\Repositories\Collections')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TainacanAI] Collections class not found');
            }
            return [];
        }

        $collections_repo = \Tainacan\Repositories\Collections::get_instance();
        $collections = $collections_repo->fetch([], 'OBJECT');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[TainacanAI] Fetched collections count: ' . (is_array($collections) ? count($collections) : 'not array'));
        }

        $list = [];

        if (!$collections || !is_array($collections)) {
            return $list;
        }

        foreach ($collections as $collection) {
            $list[] = [
                'id' => $collection->get_id(),
                'name' => $collection->get_name(),
            ];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[TainacanAI] Collections list: ' . print_r($list, true));
        }

        return $list;
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

        // AI Provider
        if (isset($input['ai_provider'])) {
            $valid_providers = ['openai', 'gemini', 'deepseek', 'ollama'];
            $options['ai_provider'] = in_array($input['ai_provider'], $valid_providers)
                ? $input['ai_provider']
                : 'openai';
        }

        // Text fields (API keys and models)
        $text_fields = [
            'api_key',           // OpenAI
            'model',             // OpenAI
            'gemini_api_key',    // Gemini
            'gemini_model',      // Gemini
            'deepseek_api_key',  // DeepSeek
            'deepseek_model',    // DeepSeek
            'ollama_url',        // Ollama
            'ollama_model',      // Ollama
        ];
        foreach ($text_fields as $field) {
            if (isset($input[$field])) {
                $options[$field] = sanitize_text_field($input[$field]);
            }
        }

        // Long text fields (prompts)
        $textarea_fields = ['default_image_prompt', 'default_document_prompt'];
        foreach ($textarea_fields as $field) {
            if (isset($input[$field])) {
                $options[$field] = wp_kses_post($input[$field]);
            }
        }

        // Numeric fields
        $numeric_fields = ['max_tokens', 'request_timeout', 'cache_duration'];
        foreach ($numeric_fields as $field) {
            if (isset($input[$field])) {
                $options[$field] = absint($input[$field]);
            }
        }

        // Temperature (float)
        if (isset($input['temperature'])) {
            $options['temperature'] = max(0, min(2, floatval($input['temperature'])));
        }

        // Checkboxes
        $checkbox_fields = ['extract_exif', 'auto_map_metadata', 'consent_required', 'log_enabled', 'cost_tracking'];
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
        $logger = new UsageLogger();
        $stats = $logger->get_stats('month');

        include TAINACAN_AI_PLUGIN_DIR . 'includes/admin/admin-page.php';
    }

    /**
     * Test API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $provider = sanitize_text_field($_POST['provider'] ?? 'openai');
        $options = get_option('tainacan_ai_options', []);

        // Determine which key and model to use based on provider
        switch ($provider) {
            case 'gemini':
                $api_key = $options['gemini_api_key'] ?? '';
                $model = $options['gemini_model'] ?? 'gemini-1.5-pro';
                break;
            case 'deepseek':
                $api_key = $options['deepseek_api_key'] ?? '';
                $model = $options['deepseek_model'] ?? 'deepseek-chat';
                break;
            case 'ollama':
                $api_key = $options['ollama_url'] ?? 'http://localhost:11434';
                $model = $options['ollama_model'] ?? 'llama3.2';
                break;
            case 'openai':
            default:
                $api_key = $options['api_key'] ?? '';
                $model = $options['model'] ?? 'gpt-4o';
                break;
        }

        // Ollama doesn't need API key, but needs URL
        if ($provider !== 'ollama' && empty($api_key)) {
            wp_send_json_error(__('API key not configured. Save settings first.', 'tainacan-ai'));
        }

        if ($provider === 'ollama' && empty($api_key)) {
            $api_key = 'http://localhost:11434';
        }

        // Use factory to test provider
        $result = \Tainacan\AI\AI\AIProviderFactory::test_provider($provider, $api_key, $model);

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    /**
     * Clear cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        global $wpdb;
        $deleted = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tainacan_ai_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tainacan_ai_%'");

        wp_send_json_success(
            /* translators: %d: number of cache entries removed */
            sprintf(__('Cache cleared! %d entries removed.', 'tainacan-ai'), $deleted)
        );
    }

    /**
     * Get statistics
     */
    public function ajax_get_stats() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $period = sanitize_text_field($_POST['period'] ?? 'month');
        $logger = new UsageLogger();

        wp_send_json_success([
            'stats' => $logger->get_stats($period),
            'daily' => $logger->get_daily_usage(30),
        ]);
    }

    /**
     * Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $logger = new UsageLogger();
        $csv = $logger->export_csv();

        wp_send_json_success(['csv' => $csv]);
    }

    /**
     * Get collection metadata
     */
    public function ajax_get_collection_metadata() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);

        if (empty($collection_id)) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        if (!class_exists('\Tainacan\Repositories\Metadata')) {
            wp_send_json_error(__('Tainacan is not active.', 'tainacan-ai'));
        }

        $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
        $metadata = $metadata_repo->fetch_by_collection(
            new \Tainacan\Entities\Collection($collection_id),
            [],
            'OBJECT'
        );

        $list = [];
        foreach ($metadata as $meta) {
            $list[] = [
                'id' => $meta->get_id(),
                'name' => $meta->get_name(),
                'slug' => $meta->get_slug(),
                'type' => $meta->get_metadata_type(),
            ];
        }

        wp_send_json_success($list);
    }

    /**
     * Save field mapping for a collection
     */
    public function ajax_save_mapping() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);
        $mapping = isset($_POST['mapping']) ? json_decode(stripslashes($_POST['mapping']), true) : [];

        if (empty($collection_id)) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        // Save mapping as option
        $option_key = 'tainacan_ai_mapping_' . $collection_id;
        update_option($option_key, $mapping);

        wp_send_json_success(__('Mapping saved successfully!', 'tainacan-ai'));
    }

    /**
     * Get field mapping for a collection
     */
    public function ajax_get_mapping() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);

        if (empty($collection_id)) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        $option_key = 'tainacan_ai_mapping_' . $collection_id;
        $mapping = get_option($option_key, []);

        wp_send_json_success($mapping);
    }

    /**
     * Auto-detect mapping based on collection metadata
     */
    public function ajax_auto_detect_mapping() {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);

        if (empty($collection_id)) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        if (!class_exists('\Tainacan\Repositories\Metadata')) {
            wp_send_json_error(__('Tainacan is not active.', 'tainacan-ai'));
        }

        $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
        $metadata = $metadata_repo->fetch_by_collection(
            new \Tainacan\Entities\Collection($collection_id),
            [],
            'OBJECT'
        );

        // Common AI field mappings to Tainacan metadata
        $ai_field_mappings = [
            'titulo' => ['titulo', 'title', 'nome', 'name'],
            'descricao' => ['descricao', 'description', 'desc', 'resumo', 'abstract'],
            'autor' => ['autor', 'author', 'autores', 'authors', 'criador', 'creator', 'dccreator'],
            'data' => ['data', 'date', 'data_criacao', 'created_date', 'dcdate', 'ano', 'year'],
            'assunto' => ['assunto', 'subject', 'tema', 'topic', 'palavras-chave', 'keywords', 'dcsubject'],
            'tipo' => ['tipo', 'type', 'categoria', 'category', 'dctype'],
            'formato' => ['formato', 'format', 'dcformat'],
            'idioma' => ['idioma', 'language', 'lingua', 'dclanguage'],
            'fonte' => ['fonte', 'source', 'origem', 'dcsource'],
            'direitos' => ['direitos', 'rights', 'licenca', 'license', 'dcrights'],
            'cobertura' => ['cobertura', 'coverage', 'local', 'location', 'dccoverage'],
            'editor' => ['editor', 'publisher', 'editora', 'dcpublisher'],
            'contribuidor' => ['contribuidor', 'contributor', 'colaborador', 'dccontributor'],
            'relacao' => ['relacao', 'relation', 'relacionado', 'dcrelation'],
            'identificador' => ['identificador', 'identifier', 'id', 'dcidentifier'],
        ];

        $auto_mapping = [];

        foreach ($metadata as $meta) {
            $meta_name = strtolower($meta->get_name());
            $meta_slug = strtolower($meta->get_slug());
            $meta_id = $meta->get_id();

            // Try to find match
            foreach ($ai_field_mappings as $ai_field => $variations) {
                foreach ($variations as $variation) {
                    // Check in metadata name or slug
                    if (
                        strpos($meta_name, $variation) !== false ||
                        strpos($meta_slug, $variation) !== false ||
                        $meta_name === $variation ||
                        $meta_slug === $variation
                    ) {
                        $auto_mapping[$ai_field] = [
                            'metadata_id' => $meta_id,
                            'metadata_name' => $meta->get_name(),
                        ];
                        break 2; // Exit both loops
                    }
                }
            }
        }

        wp_send_json_success($auto_mapping);
    }
}

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
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
            <path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494z"/>
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

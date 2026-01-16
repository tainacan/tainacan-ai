<?php
namespace Tainacan\AI;

/**
 * Plugin REST API
 *
 * Exposes endpoints for document analysis and management.
 */
class API {

    private string $namespace = 'tainacan-ai/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register API routes
     */
    public function register_routes(): void {
        // Analyze document (old route for compatibility)
        register_rest_route($this->namespace, '/analyze/(?P<attachment_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'analyze_attachment'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'force_refresh' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                ],
                'item_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'collection_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Status
        register_rest_route($this->namespace, '/status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Limpar cache
        register_rest_route($this->namespace, '/cache/(?P<attachment_id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'clear_cache'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Tipos suportados
        register_rest_route($this->namespace, '/supported-types', [
            'methods' => 'GET',
            'callback' => [$this, 'get_supported_types'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Estatísticas
        register_rest_route($this->namespace, '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'period' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'month',
                ],
            ],
        ]);

        // Prompts por coleção
        register_rest_route($this->namespace, '/collections/(?P<id>\d+)/prompt', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_collection_prompt'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'type' => [
                        'required' => false,
                        'type' => 'string',
                        'default' => 'image',
                    ],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'save_collection_prompt'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args' => [
                    'type' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'prompt' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_collection_prompt'],
                'permission_callback' => [$this, 'check_admin_permission'],
                'args' => [
                    'type' => [
                        'required' => false,
                        'type' => 'string',
                        'default' => 'image',
                    ],
                ],
            ],
        ]);

        // Sugestão de prompt
        register_rest_route($this->namespace, '/collections/(?P<id>\d+)/prompt/suggestion', [
            'methods' => 'GET',
            'callback' => [$this, 'get_prompt_suggestion'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'image',
                ],
            ],
        ]);
    }

    /**
     * Check edit permission
     */
    public function check_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Check admin permission
     */
    public function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Analyze attachment
     */
    public function analyze_attachment(\WP_REST_Request $request): \WP_REST_Response {
        $attachment_id = $request->get_param('attachment_id');
        $force_refresh = $request->get_param('force_refresh');
        $item_id = $request->get_param('item_id');
        $collection_id = $request->get_param('collection_id');

        // Check cache
        $cache_key = 'tainacan_ai_' . $attachment_id;
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return new \WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'metadata' => $cached,
                        'from_cache' => true,
                    ],
                ], 200);
            }
        }

        $analyzer = new DocumentAnalyzer();
        $analyzer->set_context($collection_id, $item_id);
        $result = $analyzer->analyze($attachment_id);

        if (is_wp_error($result)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400);
        }

        // Save cache
        $options = \Tainacan_AI::get_options();
        $cache_duration = $options['cache_duration'] ?? 3600;
        if ($cache_duration > 0) {
            set_transient($cache_key, $result, $cache_duration);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'metadata' => $result,
                'from_cache' => false,
            ],
        ], 200);
    }

    /**
     * Plugin status
     */
    public function get_status(): \WP_REST_Response {
        $options = \Tainacan_AI::get_options();

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'configured' => !empty($options['api_key']),
                'model' => $options['model'] ?? 'gpt-4o',
                'version' => TAINACAN_AI_VERSION,
            ],
        ], 200);
    }

    /**
     * Clear cache
     */
    public function clear_cache(\WP_REST_Request $request): \WP_REST_Response {
        $attachment_id = $request->get_param('attachment_id');
        delete_transient('tainacan_ai_' . $attachment_id);

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Cache cleared successfully.', 'tainacan-ai'),
        ], 200);
    }

    /**
     * Get supported types
     */
    public function get_supported_types(): \WP_REST_Response {
        $analyzer = new DocumentAnalyzer();

        return new \WP_REST_Response([
            'success' => true,
            'data' => $analyzer->get_supported_types(),
        ], 200);
    }

    /**
     * Get statistics
     */
    public function get_stats(\WP_REST_Request $request): \WP_REST_Response {
        $period = $request->get_param('period');
        $logger = new UsageLogger();

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'stats' => $logger->get_stats($period),
                'daily' => $logger->get_daily_usage(30),
            ],
        ], 200);
    }

    /**
     * Get collection prompt
     */
    public function get_collection_prompt(\WP_REST_Request $request): \WP_REST_Response {
        $collection_id = (int) $request->get_param('id');
        $type = $request->get_param('type');

        $prompts = new CollectionPrompts();

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'prompt' => $prompts->get_prompt($collection_id, $type),
                'effective_prompt' => $prompts->get_effective_prompt($collection_id, $type),
            ],
        ], 200);
    }

    /**
     * Save collection prompt
     */
    public function save_collection_prompt(\WP_REST_Request $request): \WP_REST_Response {
        $collection_id = (int) $request->get_param('id');
        $type = $request->get_param('type');
        $prompt = $request->get_param('prompt');

        $prompts = new CollectionPrompts();

        if ($prompts->save_prompt($collection_id, $type, $prompt)) {
            return new \WP_REST_Response([
                'success' => true,
                'message' => __('Prompt saved successfully!', 'tainacan-ai'),
            ], 200);
        }

        return new \WP_REST_Response([
            'success' => false,
            'message' => __('Error saving prompt.', 'tainacan-ai'),
        ], 500);
    }

    /**
     * Remove collection prompt
     */
    public function delete_collection_prompt(\WP_REST_Request $request): \WP_REST_Response {
        $collection_id = (int) $request->get_param('id');
        $type = $request->get_param('type');

        $prompts = new CollectionPrompts();

        if ($prompts->delete_prompt($collection_id, $type)) {
            return new \WP_REST_Response([
                'success' => true,
                'message' => __('Prompt removed. Default prompt will be used.', 'tainacan-ai'),
            ], 200);
        }

        return new \WP_REST_Response([
            'success' => false,
            'message' => __('Error removing prompt.', 'tainacan-ai'),
        ], 500);
    }

    /**
     * Generate prompt suggestion
     */
    public function get_prompt_suggestion(\WP_REST_Request $request): \WP_REST_Response {
        $collection_id = (int) $request->get_param('id');
        $type = $request->get_param('type');

        $prompts = new CollectionPrompts();

        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'suggestion' => $prompts->generate_prompt_suggestion($collection_id, $type),
                'metadata' => $prompts->get_collection_metadata($collection_id),
            ],
        ], 200);
    }
}

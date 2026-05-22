<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

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

    }

    /**
     * Check edit permission
     */
    public function check_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Analyze attachment
     */
    public function analyze_attachment(\WP_REST_Request $request): \WP_REST_Response {
        $attachment_id = $request->get_param('attachment_id');
        $force_refresh = $request->get_param('force_refresh');
        $item_id = $request->get_param('item_id');
        $collection_id = $request->get_param('collection_id');

        $analyzer = new DocumentAnalyzer();
        $analyzer->set_context($collection_id, $item_id);

        // Check cache
        $cache_key = 'tainacan_ai_' . $attachment_id;
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return new \WP_REST_Response([
                    'success' => true,
                    'data' => $this->build_analyze_api_data($analyzer, (int) $attachment_id, $cached, true),
                ], 200);
            }
        }

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
            'data' => $this->build_analyze_api_data($analyzer, (int) $attachment_id, $result, false),
        ], 200);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function build_analyze_api_data(
        DocumentAnalyzer $analyzer,
        int $attachment_id,
        array $metadata,
        bool $from_cache
    ): array {
        $data = [
            'metadata' => $metadata,
            'from_cache' => $from_cache,
        ];

        $prompt_debug = $analyzer->build_prompt_debug_payload($attachment_id);

        if ($prompt_debug !== null) {
            if (is_wp_error($prompt_debug)) {
                $data['prompt_debug'] = [
                    'error' => $prompt_debug->get_error_message(),
                ];
            } else {
                $data['prompt_debug'] = $prompt_debug;
            }
        }

        return $data;
    }

    /**
     * Plugin status
     */
    public function get_status(): \WP_REST_Response {
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'configured' => CoreAI::is_supported_text_generation(),
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

}

<?php
namespace Tainacan\AI\REST;

use Tainacan\AI\Plugin;
use Tainacan\AI\Extraction\DocumentAnalyzer;
use Tainacan\AI\Extraction\ExtractionMetadata;
use Tainacan\AI\Hooks\DocumentContentIndexHook;
use Tainacan\AI\Support\AnalysisErrorDebug;
use Tainacan\AI\Support\DebugLog;

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

    private DocumentContentIndexHook $document_content_index;

    public function __construct(DocumentContentIndexHook $document_content_index) {
        $this->document_content_index = $document_content_index;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register API routes
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/extract', [
            'methods' => 'POST',
            'callback' => [$this, 'extract_document'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_id' => [
                    'required' => false,
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

        // Analyze document metadata from a cached extraction payload.
        register_rest_route($this->namespace, '/analyze', [
            'methods' => 'POST',
            'callback' => [$this, 'analyze_attachment'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'attachment_id' => [
                    'required' => false,
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
                'override_prompt' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'wp_kses_post',
                ],
            ],
        ]);

        // Item document discovery.
        register_rest_route($this->namespace, '/item-document/(?P<item_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_item_document_info'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'item_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Extraction-enabled fields for a collection.
        register_rest_route($this->namespace, '/extraction-fields/(?P<collection_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_extraction_fields'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'collection_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Admin settings cache clear.
        register_rest_route($this->namespace, '/clear-cache', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_all_cache'],
            'permission_callback' => [$this, 'check_manage_options_permission'],
        ]);

    }

    /**
     * Check edit permission
     */
    public function check_permission(): bool {
        return current_user_can('edit_posts');
    }

    public function check_manage_options_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Extract document content locally (no AI call).
     */
    public function extract_document(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        try {
            $context = $this->resolve_document_request_context($request);
            if (is_wp_error($context)) {
                return $context;
            }

            $extract_cache_key = (string) $context['extract_cache_key'];
            $force_refresh = (bool) $context['force_refresh'];
            $options = Plugin::get_options();
            $cache_duration = (int) ($options['cache_duration'] ?? 3600);

            if (!$force_refresh) {
                $index_extraction = $this->document_content_index->build_extraction_from_item_index($context);

                if (is_array($index_extraction) && $index_extraction !== []) {
                    if ($cache_duration > 0) {
                        set_transient($extract_cache_key, $index_extraction, $cache_duration);
                    }

                    return new \WP_REST_Response(
                        $this->build_extract_api_data($index_extraction, false),
                        200
                    );
                }

                $cached = get_transient($extract_cache_key);
                if (is_array($cached) && $cached !== []) {
                    return new \WP_REST_Response(
                        $this->build_extract_api_data($cached, true),
                        200
                    );
                }
            }

            $analyzer = new DocumentAnalyzer();
            $analyzer->set_context((int) $context['collection_id'], (int) $context['item_id']);

            $extraction = $context['is_remote_url_document']
                ? $analyzer->extract_document_url((string) $context['document_url'])
                : $analyzer->extract((int) $context['attachment_id']);

            if (is_wp_error($extraction)) {
                return $this->rest_error_from_wp_error($extraction);
            }

            $extraction['content_source'] = DocumentContentIndexHook::SOURCE_FILE_EXTRACTION;

            if ($cache_duration > 0) {
                set_transient($extract_cache_key, $extraction, $cache_duration);
            }

            return new \WP_REST_Response(
                $this->build_extract_api_data($extraction, false),
                200
            );
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                DebugLog::log('Error in extract_document REST: ' . $e->getMessage());
                DebugLog::log('Stack trace: ' . $e->getTraceAsString());
            }

            return AnalysisErrorDebug::from_throwable(
                $e,
                'extract_exception',
                sprintf(
                    /* translators: %s: error message */
                    __('Error extracting document: %s', 'tainacan-ai'),
                    $e->getMessage()
                ),
                500
            );
        }
    }

    /**
     * Analyze attachment metadata from a previously extracted payload.
     */
    public function analyze_attachment(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        try {
            $context = $this->resolve_document_request_context($request);
            if (is_wp_error($context)) {
                return $context;
            }

            $override_prompt = trim((string) ($request->get_param('override_prompt') ?? ''));
            $use_prompt_override = (
                $override_prompt !== ''
                && DocumentAnalyzer::should_include_prompt_in_response()
            );
            $force_refresh = (bool) $context['force_refresh'] || $use_prompt_override;

            $extract_cache_key = (string) $context['extract_cache_key'];
            $analyze_cache_key = (string) $context['analyze_cache_key'];
            $extraction = get_transient($extract_cache_key);

            if ((!is_array($extraction) || $extraction === []) && !$force_refresh) {
                $index_extraction = $this->document_content_index->build_extraction_from_item_index($context);

                if (is_array($index_extraction) && $index_extraction !== []) {
                    $extraction = $index_extraction;

                    $options = Plugin::get_options();
                    $cache_duration = (int) ($options['cache_duration'] ?? 3600);

                    if ($cache_duration > 0) {
                        set_transient($extract_cache_key, $extraction, $cache_duration);
                    }
                }
            }

            if (!is_array($extraction) || $extraction === []) {
                return new \WP_Error(
                    'extraction_required',
                    __('Document extraction is required before analysis. Run extraction first.', 'tainacan-ai'),
                    ['status' => 400]
                );
            }

            if (
                $context['is_remote_url_document']
                && empty($extraction['document_url'])
                && !empty($context['document_url'])
            ) {
                $extraction['document_url'] = (string) $context['document_url'];
            }

            $analyzer = new DocumentAnalyzer();
            $analyzer->set_context((int) $context['collection_id'], (int) $context['item_id']);

            if ($use_prompt_override) {
                $analyzer->set_prompt_override($override_prompt);
                $force_refresh = true;
            }

            if (!$force_refresh) {
                $cached = get_transient($analyze_cache_key);
                if ($cached !== false) {
                    return new \WP_REST_Response(
                        $this->build_analyze_api_data(
                            $analyzer,
                            $context['is_remote_url_document'] ? null : (int) $context['attachment_id'],
                            $cached,
                            true,
                            is_array($extraction) ? $extraction : null
                        ),
                        200
                    );
                }
            }

            $result = $analyzer->analyze_from_extraction(
                $extraction,
                (int) $context['attachment_id']
            );

            if (is_wp_error($result)) {
                return $this->rest_error_from_wp_error($result);
            }

            $options = Plugin::get_options();
            $cache_duration = (int) ($options['cache_duration'] ?? 3600);
            if ($cache_duration > 0 && !$use_prompt_override) {
                $result_to_cache = $result;
                unset($result_to_cache['prompt_debug']);
                set_transient($analyze_cache_key, $result_to_cache, $cache_duration);
            }

            return new \WP_REST_Response(
                $this->build_analyze_api_data(
                    $analyzer,
                    $context['is_remote_url_document'] ? null : (int) $context['attachment_id'],
                    $result,
                    false,
                    is_array($extraction) ? $extraction : null
                ),
                200
            );
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                DebugLog::log('Error in analyze_attachment REST: ' . $e->getMessage());
                DebugLog::log('Stack trace: ' . $e->getTraceAsString());
            }

            return AnalysisErrorDebug::from_throwable(
                $e,
                'analyze_exception',
                sprintf(
                    /* translators: %s: error message */
                    __('Error analyzing document: %s', 'tainacan-ai'),
                    $e->getMessage()
                ),
                500
            );
        }
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    private function resolve_document_request_context(\WP_REST_Request $request): array|\WP_Error {
        $item_id = (int) ($request->get_param('item_id') ?? 0);
        $attachment_id = (int) ($request->get_param('attachment_id') ?? 0);
        $collection_id = (int) ($request->get_param('collection_id') ?? 0);
        $force_refresh = (bool) ($request->get_param('force_refresh') ?? false);
        $document_data = null;

        if ($item_id <= 0 && $attachment_id <= 0) {
            return new \WP_Error(
                'missing_item_or_attachment',
                __('Item or attachment ID not provided.', 'tainacan-ai'),
                ['status' => 400]
            );
        }

        if ($attachment_id <= 0 && $item_id > 0) {
            $document_data = $this->get_item_document($item_id);
            if (!$document_data) {
                return new \WP_Error(
                    'no_item_document',
                    __('No document found in this item.', 'tainacan-ai'),
                    ['status' => 404]
                );
            }

            if (!empty($document_data['id'])) {
                $attachment_id = (int) $document_data['id'];
            }
        }

        if ($collection_id <= 0 && $item_id > 0) {
            $collection_id = $this->get_item_collection_id($item_id) ?? 0;
        }

        $is_remote_url_document = (
            is_array($document_data)
            && ($document_data['source'] ?? '') === 'url'
            && !empty($document_data['document_url'])
        );
        $document_url = $is_remote_url_document ? (string) $document_data['document_url'] : '';

        $extract_cache_key = $is_remote_url_document
            ? 'tainacan_ai_extract_url_' . md5($document_url)
            : 'tainacan_ai_extract_' . $attachment_id;
        $analyze_cache_key = $is_remote_url_document
            ? 'tainacan_ai_url_' . md5($document_url)
            : 'tainacan_ai_' . $attachment_id;

        return [
            'item_id' => $item_id,
            'attachment_id' => $attachment_id,
            'collection_id' => $collection_id,
            'force_refresh' => $force_refresh,
            'document_data' => $document_data,
            'is_remote_url_document' => $is_remote_url_document,
            'document_url' => $document_url,
            'extract_cache_key' => $extract_cache_key,
            'analyze_cache_key' => $analyze_cache_key,
        ];
    }

    /**
     * @param array<string, mixed> $extraction
     * @return array<string, mixed>
     */
    private function build_extract_api_data(array $extraction, bool $from_cache): array {
        return [
            'extraction' => $this->sanitize_extraction_for_client($extraction),
            'from_cache' => $from_cache,
        ];
    }

    /**
     * Omit large binary payloads from the REST response while keeping them in the server cache.
     *
     * @param array<string, mixed> $extraction
     * @return array<string, mixed>
     */
    private function sanitize_extraction_for_client(array $extraction): array {
        unset($extraction['vision_images'], $extraction['vision_image'], $extraction['file_path']);

        return $extraction;
    }

    private function rest_error_from_wp_error(\WP_Error $error): \WP_Error {
        $error_data = $error->get_error_data();
        if (!is_array($error_data)) {
            $error_data = [];
        }

        $status = $error_data['status'] ?? null;
        $status = is_int($status) && $status > 0 ? $status : 400;
        $error_data['status'] = $status;

        if (!AnalysisErrorDebug::should_include_in_response()) {
            unset($error_data['debug_details']);
        }

        if (!DocumentAnalyzer::should_include_prompt_in_response()) {
            unset($error_data['prompt_debug']);
        }

        return new \WP_Error(
            (string) $error->get_error_code(),
            $error->get_error_message(),
            $error_data
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $extraction
     * @return array<string, mixed>
     */
    private function build_analyze_api_data(
        DocumentAnalyzer $analyzer,
        ?int $attachment_id,
        array $metadata,
        bool $from_cache,
        ?array $extraction = null
    ): array {
        $prompt_debug = null;
        if (
            isset($metadata['prompt_debug'])
            && is_array($metadata['prompt_debug'])
            && $metadata['prompt_debug'] !== []
        ) {
            $prompt_debug = $metadata['prompt_debug'];
        } elseif (is_array($extraction) && $extraction !== []) {
            $prompt_debug = $analyzer->build_prompt_debug_payload_from_extraction(
                $extraction,
                (int) ($attachment_id ?? 0)
            );
        } elseif ($attachment_id && $attachment_id > 0) {
            $prompt_debug = $analyzer->build_prompt_debug_payload($attachment_id);
        }

        $result_for_client = $metadata;
        unset($result_for_client['prompt_debug']);

        $data = [
            'result' => $result_for_client,
            'from_cache' => $from_cache,
        ];

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

    public function get_item_document_info(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $item_id = (int) ($request->get_param('item_id') ?? 0);

        if ($item_id <= 0) {
            return new \WP_Error(
                'missing_item_id',
                __('Item ID not provided.', 'tainacan-ai'),
                ['status' => 400]
            );
        }

        $document = $this->get_item_document($item_id);
        if (!$document) {
            return new \WP_Error(
                'document_not_found',
                __('Document not found.', 'tainacan-ai'),
                ['status' => 404]
            );
        }

        return new \WP_REST_Response($document, 200);
    }

    public function get_extraction_fields(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        $collection_id = (int) ($request->get_param('collection_id') ?? 0);

        if ($collection_id <= 0) {
            return new \WP_Error(
                'missing_collection_id',
                __('Collection ID not provided.', 'tainacan-ai'),
                ['status' => 400]
            );
        }

        $fields = ExtractionMetadata::get_instance()->get_fields_for_collection($collection_id);

        return new \WP_REST_Response($fields, 200);
    }

    public function clear_all_cache(): \WP_REST_Response {
        global $wpdb;
        $deleted = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tainacan_ai_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tainacan_ai_%'");

        return new \WP_REST_Response(
            [
                'message' => sprintf(
                    /* translators: %d: number of cache entries removed */
                    __('Cache cleared! %d entries removed.', 'tainacan-ai'),
                    (int) $deleted
                ),
            ],
            200
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get_item_document(int $item_id): ?array {
        if (class_exists('\Tainacan\Repositories\Items')) {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $item = $items_repo->fetch($item_id);

            if ($item) {
                $document_type = $item->get_document_type();
                $document = $item->get_document();

                if ($document_type === 'attachment' && !empty($document) && is_numeric($document)) {
                    return $this->get_attachment_info((int) $document);
                }

                if ($document_type === 'url' && !empty($document)) {
                    $attachment_id = attachment_url_to_postid($document);
                    if ($attachment_id) {
                        return $this->get_attachment_info((int) $attachment_id);
                    }

                    return $this->get_remote_url_document_info((string) $document);
                }
            }
        }

        $document_id = get_post_meta($item_id, 'document', true);
        if (!empty($document_id) && is_numeric($document_id)) {
            return $this->get_attachment_info((int) $document_id);
        }

        $thumbnail_id = get_post_thumbnail_id($item_id);
        if ($thumbnail_id) {
            return $this->get_attachment_info((int) $thumbnail_id);
        }

        $attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'post_parent' => $item_id,
            'post_status' => 'inherit',
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

        if (!empty($attachments)) {
            return $this->get_attachment_info($attachments[0]->ID);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function get_attachment_info(int $attachment_id): array {
        $mime_type = get_post_mime_type($attachment_id);
        $title = get_the_title($attachment_id);
        $url = wp_get_attachment_url($attachment_id);

        $type = 'unknown';
        if (strpos($mime_type, 'image/') === 0) {
            $type = 'image';
        } elseif ($mime_type === 'application/pdf') {
            $type = 'pdf';
        } elseif (strpos($mime_type, 'text/') === 0) {
            $type = 'text';
        }

        $thumbnail = null;
        if ($type === 'image') {
            $thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');
            $thumbnail = $thumb ? $thumb[0] : null;
        }

        return [
            'id' => $attachment_id,
            'title' => $title,
            'url' => $url,
            'mime_type' => $mime_type,
            'type' => $type,
            'thumbnail' => $thumbnail,
            'source' => 'attachment',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function get_remote_url_document_info(string $document_url): array {
        $title = wp_parse_url($document_url, PHP_URL_PATH);
        $title = is_string($title) && $title !== '' ? basename($title) : $document_url;
        $extension = strtolower((string) pathinfo($title, PATHINFO_EXTENSION));
        $mime_type = 'application/octet-stream';
        $type = 'url';

        if ($extension === 'pdf') {
            $mime_type = 'application/pdf';
        } elseif (in_array($extension, ['txt', 'text'], true)) {
            $mime_type = 'text/plain';
        } elseif (in_array($extension, ['htm', 'html'], true)) {
            $mime_type = 'text/html';
        }

        return [
            'id' => 0,
            'title' => $title,
            'url' => $document_url,
            'mime_type' => $mime_type,
            'type' => $type,
            'thumbnail' => null,
            'source' => 'url',
            'document_url' => $document_url,
        ];
    }

    private function get_item_collection_id(int $item_id): ?int {
        if (!class_exists('\Tainacan\Repositories\Items')) {
            return null;
        }

        $items_repo = \Tainacan\Repositories\Items::get_instance();
        $item = $items_repo->fetch($item_id);

        if ($item && method_exists($item, 'get_collection_id')) {
            return $item->get_collection_id();
        }

        return null;
    }

}

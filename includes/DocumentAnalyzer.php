<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

use Tainacan\AI\PdfParser\PdfParser;
use Tainacan\AI\PdfParser\PdfToImage;

/**
 * Document analyzer using WordPress Core AI Client
 *
 * Analyzes images and documents extracting metadata via AI.
 * Uses `wp_ai_client_prompt()` through `CoreAI`.
 *
 * @since 1.0.0 - WordPress AI client via Connectors
 */
class DocumentAnalyzer {

    private array $options;
    private ?int $collection_id = null;
    private ?int $item_id = null;
    private ?int $current_attachment_id = null;
    private ExifExtractor $exif_extractor;
    private CollectionPrompts $collection_prompts;

    /**
     * Supported MIME types
     */
    private array $supported_image_types = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private array $supported_document_types = [
        'application/pdf',
        'text/plain',
        'text/html',
    ];

    private array $supported_remote_document_types = [
        'application/pdf',
        'text/plain',
        'text/html',
    ];

    public function __construct() {
        $this->options = \Tainacan_AI::get_options();
        $this->exif_extractor = new ExifExtractor();
        $this->collection_prompts = new CollectionPrompts();
    }

    /**
     * Set analysis context
     */
    public function set_context(?int $collection_id = null, ?int $item_id = null): self {
        $this->collection_id = $collection_id;
        $this->item_id = $item_id;
        return $this;
    }

    /**
     * Analyze an attachment
     */
    public function analyze(int $attachment_id, bool $include_exif = true): array|\WP_Error {
        if (!CoreAI::is_supported_text_generation()) {
            return new \WP_Error(
                'no_core_ai',
                __('WordPress Core AI Client is not available or not configured.', 'tainacan-ai')
            );
        }

        // Check consent
        if (!\Tainacan_AI::has_consent()) {
            return new \WP_Error('no_consent', __('Consent required to use AI features.', 'tainacan-ai'));
        }

        $file_path = get_attached_file($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);

        if (!$file_path) {
            return new \WP_Error(
                'file_not_found',
                __('File path not found in WordPress. The attachment may have been removed.', 'tainacan-ai')
            );
        }

        // Normalize file path (fixes slash and _x_ issues)
        $file_path = $this->normalize_file_path($file_path);

        if (!file_exists($file_path)) {
            return new \WP_Error(
                'file_not_found',
                sprintf(
                    /* translators: %s: file path */
                    __('Physical file does not exist on server. Expected at: %s', 'tainacan-ai'),
                    $file_path
                )
            );
        }

        // Detect collection if not defined
        if (!$this->collection_id && $this->item_id) {
            $this->collection_id = $this->get_item_collection($this->item_id);
        }

        $this->current_attachment_id = $attachment_id;

        try {
            return $this->run_analysis($attachment_id, $include_exif, $file_path, $mime_type);
        } finally {
            $this->current_attachment_id = null;
        }
    }

    /**
     * Analyze a remote document URL (https only).
     */
    public function analyze_document_url(string $document_url): array|\WP_Error {
        if (!CoreAI::is_supported_text_generation()) {
            return new \WP_Error(
                'no_core_ai',
                __('WordPress Core AI Client is not available or not configured.', 'tainacan-ai')
            );
        }

        if (!\Tainacan_AI::has_consent()) {
            return new \WP_Error('no_consent', __('Consent required to use AI features.', 'tainacan-ai'));
        }

        if (!$this->collection_id && $this->item_id) {
            $this->collection_id = $this->get_item_collection($this->item_id);
        }

        $downloaded = $this->download_remote_document($document_url);

        if (is_wp_error($downloaded)) {
            return $downloaded;
        }

        $this->current_attachment_id = null;

        try {
            return $this->run_analysis(
                0,
                false,
                $downloaded['file_path'],
                $downloaded['mime_type']
            );
        } finally {
            $this->current_attachment_id = null;
            if (file_exists($downloaded['file_path'])) {
                wp_delete_file($downloaded['file_path']);
            }
        }
    }

    /**
     * @return array|\WP_Error
     */
    private function run_analysis(
        int $attachment_id,
        bool $include_exif,
        string $file_path,
        string $mime_type
    ): array|\WP_Error {
        $result = [];
        $document_type = 'unknown';
        $extraction_method = null;

        // Analysis based on type
        if (in_array($mime_type, $this->supported_image_types)) {
            $document_type = 'image';

            // Extract EXIF first
            if ($include_exif && ($this->options['extract_exif'] ?? true)) {
                $exif_data = $this->exif_extractor->extract($file_path);
                if (!empty($exif_data['data'])) {
                    $result['exif'] = $exif_data['data'];
                    $result['exif_summary'] = $this->exif_extractor->get_summary($exif_data);
                }
            }

            // AI analysis
            $ai_result = $this->analyze_image($attachment_id, $file_path, $mime_type);
            $extraction_method = 'vision';

        } elseif ($mime_type === 'application/pdf') {
            $document_type = 'pdf';
            $pdf_result = $this->analyze_pdf_smart($file_path);
            $ai_result = $pdf_result['result'];
            $extraction_method = $pdf_result['method'];

        } elseif (in_array($mime_type, ['text/plain', 'text/html'])) {
            $document_type = 'text';
            $ai_result = $this->analyze_text(
                file_get_contents($file_path),
                [
                    'document_type' => 'text',
                    'extraction_method' => 'text',
                ],
                EvidenceInstructions::MODE_TEXT
            );
            $extraction_method = 'text';

        } else {
            return new \WP_Error(
                'unsupported_type',
                /* translators: %s: file type */
                sprintf(__('Unsupported file type: %s', 'tainacan-ai'), $mime_type)
            );
        }

        // Check for error in AI analysis
        if (is_wp_error($ai_result)) {
            $this->debug_log_analysis_outcome(
                $document_type,
                'error',
                0,
                '',
                '',
                $ai_result->get_error_message()
            );
            return $ai_result;
        }

        // Combine results
        $raw_metadata = $ai_result['metadata'] ?? $ai_result;
        if (is_array($raw_metadata)) {
            $normalized = EvidenceInstructions::normalize_metadata($raw_metadata);

            if ($this->collection_id) {
                $normalized = ExtractionMetadata::get_instance()->complete_expected_fields($normalized, $this->collection_id);
            }

            $result['ai_metadata'] = $normalized;
        } else {
            $result['ai_metadata'] = $raw_metadata;
        }
        $result['document_type'] = $document_type;
        $result['extraction_method'] = $extraction_method;
        $result['tokens_used'] = $ai_result['usage']['total_tokens'] ?? 0;
        $result['analyzed_at'] = current_time('mysql');

        $this->debug_log_analysis_outcome(
            $document_type,
            'success',
            (int) $result['tokens_used'],
            (string) ($ai_result['provider'] ?? ''),
            (string) ($ai_result['model'] ?? ''),
            null
        );

        return $result;
    }

    /**
     * @return array{file_path: string, mime_type: string}|\WP_Error
     */
    private function download_remote_document(string $document_url): array|\WP_Error {
        $document_url = trim($document_url);

        if ($document_url === '') {
            return new \WP_Error('empty_url', __('Document URL is empty.', 'tainacan-ai'));
        }

        if (!wp_http_validate_url($document_url)) {
            return new \WP_Error('invalid_url', __('Invalid document URL.', 'tainacan-ai'));
        }

        $parts = wp_parse_url($document_url);
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if ($scheme !== 'https') {
            return new \WP_Error('unsupported_url_scheme', __('Only HTTPS document URLs are supported.', 'tainacan-ai'));
        }

        $host = isset($parts['host']) ? strtolower((string) $parts['host']) : '';
        if ($host === '' || !$this->is_public_host($host)) {
            return new \WP_Error('private_url_blocked', __('Private or local document URLs are not allowed.', 'tainacan-ai'));
        }

        $tmp_file = wp_tempnam('tainacan-ai-remote');
        if (!$tmp_file) {
            return new \WP_Error('tmp_file_error', __('Could not create temporary file for remote document.', 'tainacan-ai'));
        }

        $response = wp_safe_remote_get(
            $document_url,
            [
                'timeout' => (int) ($this->options['request_timeout'] ?? 120),
                'redirection' => 3,
                'stream' => true,
                'filename' => $tmp_file,
                'limit_response_size' => 20 * 1024 * 1024,
            ]
        );

        if (is_wp_error($response)) {
            wp_delete_file($tmp_file);
            return new \WP_Error(
                'remote_download_failed',
                sprintf(
                    /* translators: %s: low-level remote error */
                    __('Could not download document URL: %s', 'tainacan-ai'),
                    $response->get_error_message()
                )
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            wp_delete_file($tmp_file);
            return new \WP_Error(
                'remote_http_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Document URL returned HTTP status %d.', 'tainacan-ai'),
                    $status_code
                )
            );
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $header_mime_type = strtolower(trim((string) preg_replace('/;.*/', '', $content_type)));
        $url_mime_type = (string) wp_check_filetype($document_url)['type'];
        $file_mime_type = $this->detect_local_file_mime_type($tmp_file);
        $mime_type = $this->resolve_remote_mime_type(
            $tmp_file,
            $file_mime_type,
            $header_mime_type,
            $url_mime_type
        );

        if (!in_array($mime_type, $this->supported_remote_document_types, true)) {
            wp_delete_file($tmp_file);
            return new \WP_Error(
                'unsupported_remote_type',
                sprintf(
                    /* translators: %s: MIME type */
                    __('Unsupported remote document type: %s', 'tainacan-ai'),
                    $mime_type !== '' ? $mime_type : __('unknown', 'tainacan-ai')
                )
            );
        }

        return [
            'file_path' => $tmp_file,
            'mime_type' => $mime_type,
        ];
    }

    private function detect_local_file_mime_type(string $file_path): string {
        if (!is_readable($file_path)) {
            return '';
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $file_path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return strtolower($mime);
                }
            }
        }

        return '';
    }

    private function resolve_remote_mime_type(
        string $file_path,
        string $file_mime_type,
        string $header_mime_type,
        string $url_mime_type
    ): string {
        $candidates = [
            strtolower(trim($file_mime_type)),
            strtolower(trim($header_mime_type)),
            strtolower(trim($url_mime_type)),
        ];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $this->supported_remote_document_types, true)) {
                return $candidate;
            }
        }

        if ($this->file_looks_like_pdf($file_path)) {
            return 'application/pdf';
        }

        if ($this->file_looks_like_html($file_path)) {
            return 'text/html';
        }

        return $candidates[0] ?? '';
    }

    private function file_looks_like_pdf(string $file_path): bool {
        if (!is_readable($file_path)) {
            return false;
        }

        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 5);
        fclose($handle);

        return $header === '%PDF-';
    }

    private function file_looks_like_html(string $file_path): bool {
        if (!is_readable($file_path)) {
            return false;
        }

        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }

        $sample = fread($handle, 4096);
        fclose($handle);

        if (!is_string($sample) || $sample === '') {
            return false;
        }

        $normalized = strtolower(ltrim($sample));
        return strpos($normalized, '<!doctype html') === 0
            || strpos($normalized, '<html') === 0
            || strpos($normalized, '<head') === 0
            || strpos($normalized, '<body') === 0;
    }

    /**
     * WP_DEBUG-only server log (no prompt or response body).
     */
    private function debug_log(string $message): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('[TainacanAI] ' . preg_replace('/\s+/', ' ', $message));
    }

    /**
     * One-line analysis outcome for WP_DEBUG (complements Core AI Request Logs).
     */
    private function debug_log_analysis_outcome(
        string $document_type,
        string $status,
        int $tokens_used,
        string $provider,
        string $model,
        ?string $error_message
    ): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $parts = [
            'analysis',
            'attachment_id=' . ($this->current_attachment_id ?? '0'),
            'item_id=' . ($this->item_id ?? '0'),
            'collection_id=' . ($this->collection_id ?? '0'),
            'document_type=' . $document_type,
            'status=' . $status,
            'tokens=' . $tokens_used,
        ];

        if ($provider !== '') {
            $parts[] = 'provider=' . $provider;
        }
        if ($model !== '') {
            $parts[] = 'model=' . $model;
        }
        if ($error_message !== null && $error_message !== '') {
            $parts[] = 'error=' . preg_replace('/\s+/', ' ', $error_message);
        }

        error_log('[TainacanAI] ' . implode(' ', $parts));
    }

    /**
     * @param array<string, mixed> $log_extra
     * @return array<string, mixed>
     */
    private function generation_options(array $log_extra = []): array {
        $options = [
            'temperature' => (float) ($this->options['temperature'] ?? 0.1),
            'max_tokens' => (int) ($this->options['max_tokens'] ?? 2000),
            'request_timeout' => (int) ($this->options['request_timeout'] ?? 120),
        ];

        return CoreAIRequestLogging::options_with_context(
            $this->base_log_context($log_extra),
            $options
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function base_log_context(array $extra = []): array {
        $context = [
            'plugin' => 'tainacan-ai',
            'feature' => 'document_analysis',
        ];

        if ($this->current_attachment_id) {
            $context['attachment_id'] = $this->current_attachment_id;
        }
        if ($this->item_id) {
            $context['item_id'] = $this->item_id;
        }
        if ($this->collection_id) {
            $context['collection_id'] = $this->collection_id;
        }

        return array_merge($context, $extra);
    }

    /**
     * Smart PDF analysis with multiple fallbacks
     */
    private function analyze_pdf_smart(string $file_path): array {
        $core_supports_image = CoreAI::is_supported_image_analysis();

        // Method 1: Text extraction (faster and cheaper)
        $text = $this->extract_pdf_text($file_path);

        if (!is_wp_error($text) && !empty(trim($text)) && strlen(trim($text)) > 100) {
            $result = $this->analyze_text($text, [
                'document_type' => 'pdf',
                'extraction_method' => 'text_extraction',
            ], EvidenceInstructions::MODE_PDF_TEXT);
            return [
                'result' => $result,
                'method' => 'text_extraction',
            ];
        }

        // Method 2: Convert to image and visual analysis (if the connector supports vision)
        if ($core_supports_image) {
            $visual_result = $this->analyze_pdf_visually($file_path);

            if (!is_wp_error($visual_result)) {
                return [
                    'result' => $visual_result,
                    'method' => 'visual_analysis',
                ];
            }

            $this->debug_log('PDF visual analysis failed: ' . $visual_result->get_error_message());
        }

        // If both failed, return more informative error
        $error_msg = __('Could not analyze PDF. ', 'tainacan-ai');

        if (is_wp_error($text)) {
            $error_msg .= $text->get_error_message() . ' ';
        } else {
            $error_msg .= __('PDF does not contain extractable text. ', 'tainacan-ai');
        }

        if (!$core_supports_image) {
            $error_msg .= sprintf(
                __('Core AI does not support image analysis on this site. ', 'tainacan-ai')
            );
        }

        $this->debug_log('PDF analysis failed (all methods): ' . trim($error_msg));

        return [
            'result' => new \WP_Error('pdf_analysis_failed', trim($error_msg)),
            'method' => 'failed',
        ];
    }

    /**
     * Analyze PDF visually (for scanned PDFs)
     */
    private function analyze_pdf_visually(string $file_path): array|\WP_Error {
        $images = [];

        try {
            $converter = new PdfToImage();
            $converter->setDpi(150)
                      ->setQuality(85)
                      ->setMaxPages(3);

            $images = $converter->convert($file_path);

            if (empty($images)) {
                return new \WP_Error(
                    'conversion_failed',
                    __('Could not convert PDF to image. Install Imagick or Ghostscript.', 'tainacan-ai')
                );
            }

            $prompt = $this->resolve_analysis_prompt(EvidenceInstructions::MODE_PDF_VISUAL);

            if (is_wp_error($prompt)) {
                return $prompt;
            }

            $pageCount = count($images);
            $promptWithContext = $prompt . "\n\n" . sprintf(
                'The document has %d page(s). Analyze the visual content of all provided pages.',
                $pageCount
            );

            // Prepare images for the AI client
            $image_data = [];
            foreach ($images as $image) {
                $image_data[] = [
                    'data' => "data:{$image['mime']};base64,{$image['base64']}",
                    'mime' => $image['mime'],
                ];
            }

            return CoreAI::generate_json_from_text_and_files(
                $promptWithContext,
                $image_data,
                $this->generation_options([
                    'document_type' => 'pdf',
                    'extraction_method' => 'visual_analysis',
                ])
            );
        } catch (\Throwable $e) {
            $this->debug_log('PDF visual analysis error: ' . $e->getMessage());
            return new \WP_Error('visual_analysis_error', $e->getMessage());
        } finally {
            foreach ($images as $image) {
                if (!empty($image['path']) && file_exists($image['path'])) {
                    wp_delete_file($image['path']);
                }
            }
        }
    }

    /**
     * Analyze image
     */
    private function analyze_image(int $attachment_id, string $file_path, string $mime_type): array|\WP_Error {
        if (CoreAI::get_image_analysis_support_status() === CoreAI::IMAGE_SUPPORT_UNAVAILABLE) {
            return new \WP_Error(
                'vision_not_supported',
                __('No configured connector exposes a model that accepts image input in its metadata. Use text extraction or configure a vision-capable model.', 'tainacan-ai')
            );
        }

        // Always use base64 to ensure API can access the image
        // Local URLs (localhost, 127.0.0.1, private IPs) are not accessible by external APIs
        $image_url = wp_get_attachment_url($attachment_id);
        $image_data = null;
        $use_base64 = true;

        // Check if it's a publicly accessible URL (not localhost/local)
        if ($image_url && $this->is_public_url($image_url) && $this->is_url_accessible($image_url)) {
            $use_base64 = false;
            $image_data = $image_url;
        }

        if ($use_base64) {
            $image_content = @file_get_contents($file_path);

            if ($image_content === false) {
                return new \WP_Error('file_read_error', __('Could not read image file.', 'tainacan-ai'));
            }

            $base64 = base64_encode($image_content);

            if (empty($base64)) {
                return new \WP_Error('base64_error', __('Error encoding image to base64.', 'tainacan-ai'));
            }

            $image_data = "data:{$mime_type};base64,{$base64}";
        }

        $prompt = $this->resolve_analysis_prompt(EvidenceInstructions::MODE_IMAGE);

        if (is_wp_error($prompt)) {
            return $prompt;
        }

        return CoreAI::generate_json_from_text_and_files(
            $prompt,
            [
                [
                    'data' => $image_data,
                    'mime' => $mime_type,
                ],
            ],
            $this->generation_options([
                'document_type' => 'image',
                'extraction_method' => 'vision',
            ])
        );
    }

    /**
     * Analyze text
     *
     * @param array<string, mixed> $log_extra
     */
    private function analyze_text(string $text, array $log_extra = [], string $analysis_mode = EvidenceInstructions::MODE_TEXT): array|\WP_Error {
        // Sanitize text to valid UTF-8
        $text = $this->sanitize_utf8_string($text);

        // Limit text size
        $max_chars = 32000;
        if (mb_strlen($text, 'UTF-8') > $max_chars) {
            $text = mb_substr($text, 0, $max_chars, 'UTF-8');
            $text .= "\n\n[Document truncated due to size]";
        }

        $prompt = $this->resolve_analysis_prompt($analysis_mode);

        if (is_wp_error($prompt)) {
            return $prompt;
        }

        $full_prompt = $prompt . "\n\n---\n\n**Document:**\n\n" . $text;

        return CoreAI::generate_json_from_text($full_prompt, $this->generation_options($log_extra));
    }

    /**
     * Resolve base prompt sections in deterministic order.
     *
     * analysis_mode selects task wording and evidence rules (image/text/pdf_*).
     *
     * @return string|\WP_Error
     */
    private function resolve_analysis_prompt(string $analysis_mode): string|\WP_Error {
        $prompt = AnalysisPromptComposer::compose(
            (int) ($this->collection_id ?? 0),
            $this->get_user_prompt(),
            $analysis_mode
        );

        if ($prompt === '') {
            return new \WP_Error('no_prompt', __('No analysis prompt configured.', 'tainacan-ai'));
        }

        return $prompt;
    }

    /**
     * Whether the resolved prompt may be included in AJAX/REST responses (never cached).
     */
    public static function should_include_prompt_in_response(): bool {
        $include = defined('WP_DEBUG') && WP_DEBUG && current_user_can('edit_posts');

        /**
         * @param bool $include Default: WP_DEBUG and edit_posts capability.
         */
        return (bool) apply_filters('tainacan_ai_include_prompt_in_response', $include);
    }

    /**
     * Debug payload for the composed analysis prompt sections + attachment note.
     *
     * @return array{
     *     prompt: string,
     *     parts: array{user: string, task: string, rules: string, fields: string, schema: string, evidence: string, output: string},
     *     analysis_mode: string,
     *     attachment_note: string
     * }|\WP_Error|null Null when prompt debug is disabled.
     */
    public function build_prompt_debug_payload(int $attachment_id): array|\WP_Error|null {
        if (!self::should_include_prompt_in_response()) {
            return null;
        }

        $file_path = get_attached_file($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);

        if (!$file_path) {
            return new \WP_Error('file_not_found', __('File path not found.', 'tainacan-ai'));
        }

        $file_path = $this->normalize_file_path($file_path);

        if (!$this->collection_id && $this->item_id) {
            $this->collection_id = $this->get_item_collection($this->item_id);
        }

        $analysis_mode = $this->guess_analysis_mode($file_path, (string) $mime_type);
        $sections = AnalysisPromptComposer::get_sections(
            (int) ($this->collection_id ?? 0),
            $this->get_user_prompt(),
            $analysis_mode
        );
        $base_prompt = trim(implode("\n\n", array_filter($sections, static fn (string $value): bool => $value !== '')));

        if ($base_prompt === '') {
            return new \WP_Error('no_prompt', __('No analysis prompt configured.', 'tainacan-ai'));
        }

        $resolved = $this->resolve_analysis_prompt($analysis_mode);

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $attachment_note = $this->get_prompt_attachment_note($file_path, (string) $mime_type, $analysis_mode);
        $prompt = $resolved;

        if ($attachment_note !== '') {
            $prompt .= "\n\n" . $attachment_note;
        }

        return [
            'prompt' => $prompt,
            'parts' => [
                'user' => $sections['user'],
                'task' => $sections['task'],
                'rules' => $sections['rules'],
                'fields' => $sections['fields'],
                'schema' => $sections['schema'],
                'evidence' => $sections['evidence'],
                'output' => $sections['output'],
            ],
            'analysis_mode' => $analysis_mode,
            'attachment_note' => $attachment_note,
        ];
    }

    private function guess_analysis_mode(string $file_path, string $mime_type): string {
        if (in_array($mime_type, $this->supported_image_types, true)) {
            return EvidenceInstructions::MODE_IMAGE;
        }

        if (in_array($mime_type, ['text/plain', 'text/html'], true)) {
            return EvidenceInstructions::MODE_TEXT;
        }

        if ($mime_type === 'application/pdf') {
            $text = $this->extract_pdf_text($file_path);

            if (!is_wp_error($text) && strlen(trim($text)) > 100) {
                return EvidenceInstructions::MODE_PDF_TEXT;
            }

            return EvidenceInstructions::MODE_PDF_VISUAL;
        }

        return EvidenceInstructions::MODE_TEXT;
    }

    private function get_prompt_attachment_note(string $file_path, string $mime_type, string $analysis_mode): string {
        if ($analysis_mode === EvidenceInstructions::MODE_IMAGE) {
            return '[Image file attached to the model request]';
        }

        if ($analysis_mode === EvidenceInstructions::MODE_PDF_VISUAL) {
            return '[PDF pages sent as images to the model]';
        }

        if (!is_readable($file_path)) {
            return '';
        }

        $text = file_get_contents($file_path);

        if ($text === false) {
            return '';
        }

        if ($mime_type === 'application/pdf') {
            $extracted = $this->extract_pdf_text($file_path);
            $text = is_wp_error($extracted) ? '' : $extracted;
        }

        $text = $this->sanitize_utf8_string($text);
        $length = mb_strlen($text, 'UTF-8');
        $max_chars = 32000;
        $truncated = $length > $max_chars;

        return sprintf(
            '--- Document body appended (%1$d characters%2$s) ---',
            min($length, $max_chars),
            $truncated ? ', truncated' : ''
        );
    }

    /**
     * User-defined prompt: collection post meta, falling back to the site default option.
     */
    private function get_user_prompt(): string {
        if ($this->collection_id) {
            return $this->collection_prompts->get_effective_prompt($this->collection_id);
        }

        return (string) ($this->options['default_prompt'] ?? '');
    }

    /**
     * Extract text from PDF using multiple methods
     */
    private function extract_pdf_text(string $file_path): string|\WP_Error {
        // Method 1: Built-in plugin parser
        try {
            $parser = new PdfParser();
            $text = $parser->parseFile($file_path)->getText();

            if (!empty(trim($text))) {
                return $text;
            }
        } catch (\Throwable $e) {
            $this->debug_log('PdfParser error: ' . $e->getMessage());
        }

        // Method 2: smalot/pdfparser (if installed via Composer)
        if (class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file_path);
                $text = $pdf->getText();

                if (!empty(trim($text))) {
                    return $text;
                }
            } catch (\Throwable $e) {
                $this->debug_log('Smalot PdfParser error: ' . $e->getMessage());
            }
        }

        // Method 3: pdftotext (poppler-utils) - Linux/Mac
        if (function_exists('shell_exec') && !$this->is_windows()) {
            $escaped_path = escapeshellarg($file_path);
            $output = @shell_exec("pdftotext {$escaped_path} - 2>/dev/null");

            if (!empty($output)) {
                return $output;
            }
        }

        // Method 4: pdftotext on Windows
        if ($this->is_windows() && function_exists('shell_exec')) {
            $escaped_path = escapeshellarg($file_path);
            $paths = [
                'pdftotext',
                'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
                'C:\\poppler\\bin\\pdftotext.exe',
            ];

            foreach ($paths as $pdftotext) {
                $output = @shell_exec("\"{$pdftotext}\" {$escaped_path} - 2>nul");
                if (!empty($output)) {
                    return $output;
                }
            }
        }

        // Method 5: Basic extraction via regex
        $text = $this->basic_pdf_text_extract($file_path);
        if (!empty(trim($text))) {
            return $text;
        }

        $message = __('Could not extract text from PDF. The document may be a scanned image.', 'tainacan-ai');
        $this->debug_log('PDF text extraction failed: ' . $message);

        return new \WP_Error('pdf_extract_failed', $message);
    }

    /**
     * Basic PDF text extraction
     */
    private function basic_pdf_text_extract(string $file_path): string {
        $content = file_get_contents($file_path);
        $text = '';

        if (preg_match_all('/stream\s*\n(.*?)\nendstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    $decoded = $stream;
                }

                if (preg_match_all('/\((.*?)\)/', $decoded, $text_matches)) {
                    $text .= implode(' ', $text_matches[1]) . ' ';
                }
            }
        }

        return trim($text);
    }

    /**
     * Check if URL is accessible
     */
    private function is_url_accessible(string $url): bool {
        $response = wp_remote_head($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Check if it's a public URL (not localhost/local)
     */
    private function is_public_url(string $url): bool {
        $parsed = wp_parse_url($url);

        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // List of local hosts that external APIs cannot access
        $local_hosts = [
            'localhost',
            '127.0.0.1',
            '::1',
            '0.0.0.0',
        ];

        if (in_array($host, $local_hosts)) {
            return false;
        }

        // Check private IPs (RFC 1918)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // 10.0.0.0 - 10.255.255.255
            if (preg_match('/^10\./', $host)) {
                return false;
            }
            // 172.16.0.0 - 172.31.255.255
            if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)) {
                return false;
            }
            // 192.168.0.0 - 192.168.255.255
            if (preg_match('/^192\.168\./', $host)) {
                return false;
            }
        }

        // Check common local domains
        $local_domains = ['.local', '.localhost', '.test', '.example', '.invalid', '.lan'];
        foreach ($local_domains as $domain) {
            if (str_ends_with($host, $domain)) {
                return false;
            }
        }

        return true;
    }

    private function is_public_host(string $host): bool {
        $local_hosts = [
            'localhost',
            '127.0.0.1',
            '::1',
            '0.0.0.0',
        ];

        if (in_array($host, $local_hosts, true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (preg_match('/^10\./', $host)) {
                return false;
            }
            if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)) {
                return false;
            }
            if (preg_match('/^192\.168\./', $host)) {
                return false;
            }
        }

        $local_domains = ['.local', '.localhost', '.test', '.example', '.invalid', '.lan'];
        foreach ($local_domains as $domain) {
            if (str_ends_with($host, $domain)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if it's Windows
     */
    private function is_windows(): bool {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Get collection of an item
     */
    private function get_item_collection(int $item_id): ?int {
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

    /**
     * Sanitize a string to valid UTF-8
     */
    private function sanitize_utf8_string(string $string): string {
        if (mb_check_encoding($string, 'UTF-8')) {
            $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
            return $string;
        }

        $encodings = ['ISO-8859-1', 'Windows-1252', 'ASCII'];

        foreach ($encodings as $encoding) {
            $converted = @mb_convert_encoding($string, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $converted);
            }
        }

        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
        $string = preg_replace('/[\x80-\xFF](?![\x80-\xBF])|(?<![\xC0-\xFF])[\x80-\xBF]/', '', $string);

        return $string;
    }

    /**
     * Normalize file path to work on Windows and Linux
     */
    private function normalize_file_path(string $file_path): string {
        $file_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);

        if (file_exists($file_path)) {
            return $file_path;
        }

        $fixed_path = preg_replace('/([\/\\\\])_x_(\d+)([\/\\\\])/', '$1$2$3', $file_path);

        if ($fixed_path !== $file_path && file_exists($fixed_path)) {
            return $fixed_path;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $alt_path = str_replace('/', '\\', $file_path);
            if (file_exists($alt_path)) {
                return $alt_path;
            }

            $alt_fixed = str_replace('/', '\\', $fixed_path);
            if (file_exists($alt_fixed)) {
                return $alt_fixed;
            }
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $alt_path = str_replace('\\', '/', $file_path);
            if (file_exists($alt_path)) {
                return $alt_path;
            }

            $alt_fixed = str_replace('\\', '/', $fixed_path);
            if (file_exists($alt_fixed)) {
                return $alt_fixed;
            }
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        if (preg_match('/tainacan-items[\/\\\\](\d+)[\/\\\\](?:_x_)?(\d+)[\/\\\\](.+)$/', $file_path, $matches)) {
            $collection_id = $matches[1];
            $item_id = $matches[2];
            $file_name = $matches[3];

            $correct_path = $base_dir . DIRECTORY_SEPARATOR . 'tainacan-items' . DIRECTORY_SEPARATOR .
                           $collection_id . DIRECTORY_SEPARATOR . $item_id . DIRECTORY_SEPARATOR . $file_name;

            if (file_exists($correct_path)) {
                return $correct_path;
            }
        }

        return $file_path;
    }

    /**
     * Get supported file types
     */
    public function get_supported_types(): array {
        return [
            'images' => $this->supported_image_types,
            'documents' => $this->supported_document_types,
        ];
    }

    /**
     * Check if type is supported
     */
    public function is_supported(string $mime_type): bool {
        return in_array($mime_type, array_merge($this->supported_image_types, $this->supported_document_types));
    }

    /**
     * Check available capabilities
     */
    public static function get_capabilities(): array {
        $capabilities = [
            'text_extraction' => [
                'name' => __('Text Extraction', 'tainacan-ai'),
                'available' => true,
                'methods' => ['built_in_parser'],
            ],
            'visual_analysis' => [
                'name' => __('Visual Analysis (PDF)', 'tainacan-ai'),
                'available' => false,
                'methods' => [],
            ],
            'exif_extraction' => [
                'name' => __('EXIF Extraction', 'tainacan-ai'),
                'available' => function_exists('exif_read_data'),
            ],
        ];

        $backends = PdfToImage::getAvailableBackends();

        if (!empty($backends['imagick']['available']) && !empty($backends['imagick']['supports_pdf'])) {
            $capabilities['visual_analysis']['available'] = true;
            $capabilities['visual_analysis']['methods'][] = 'imagick';
        }

        if (!empty($backends['ghostscript']['available'])) {
            $capabilities['visual_analysis']['available'] = true;
            $capabilities['visual_analysis']['methods'][] = 'ghostscript';
        }

        if (class_exists('\Smalot\PdfParser\Parser')) {
            $capabilities['text_extraction']['methods'][] = 'smalot_pdfparser';
        }

        if (function_exists('shell_exec')) {
            $output = @shell_exec('pdftotext -v 2>&1');
            if ($output && stripos($output, 'pdftotext') !== false) {
                $capabilities['text_extraction']['methods'][] = 'pdftotext';
            }
        }

        return $capabilities;
    }
}

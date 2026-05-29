<?php
namespace Tainacan\AI\Extraction;

use Tainacan\AI\Plugin;
use Tainacan\AI\Hooks\CollectionFormHook;
use Tainacan\AI\Support\AnalysisErrorDebug;
use Tainacan\AI\Support\AnalysisLimits;
use Tainacan\AI\Support\CoreAI;
use Tainacan\AI\Support\CoreAIRequestLogging;
use Tainacan\AI\Support\DebugLog;
use Tainacan\AI\Support\ProcessingWarnings;

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
    private ?string $prompt_override = null;
    private ExifExtractor $exif_extractor;
    /** @var array<string, array{prompt: string, sections: array<string, string>, expected_slugs: string[], fields: array<string, array<string, mixed>>}> */
    private array $prompt_context_cache = [];
    private ProcessingWarnings $processing_warnings;
    private ?int $last_request_characters = null;

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
        $this->processing_warnings = new ProcessingWarnings();
        $this->options = Plugin::get_options();
        $this->exif_extractor = new ExifExtractor();
    }

    /**
     * Set analysis context
     */
    public function set_context(?int $collection_id = null, ?int $item_id = null): self {
        $this->collection_id = $collection_id;
        $this->item_id = $item_id;
        $this->prompt_context_cache = [];
        return $this;
    }

    /**
     * Per-run prompt override for advanced debugging (not persisted).
     */
    public function set_prompt_override(?string $prompt): self {
        $trimmed = $prompt !== null ? trim($prompt) : '';
        $this->prompt_override = $trimmed !== '' ? $trimmed : null;
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
        if (!Plugin::has_consent()) {
            return new \WP_Error('no_consent', __('Consent required to use AI features.', 'tainacan-ai'));
        }

        $file_path = get_attached_file($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);

        if (!$file_path) {
            return new \WP_Error(
                'file_not_found',
                __('File path not found in WordPress. The attachment may have been removed.', 'tainacan-ai'),
                AnalysisErrorDebug::data(
                    array(
                        'attachment_id' => (string) $attachment_id,
                    ),
                    404
                )
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
                ),
                AnalysisErrorDebug::data(
                    array(
                        'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
                        'attachment_id' => (string) $attachment_id,
                    ),
                    404
                )
            );
        }

        // Detect collection if not defined
        if (!$this->collection_id && $this->item_id) {
            $this->collection_id = $this->get_item_collection($this->item_id);
        }

        $this->current_attachment_id = $attachment_id;

        try {
            $result = $this->run_analysis($attachment_id, $include_exif, $file_path, $mime_type);
        } finally {
            $this->current_attachment_id = null;
        }

        if (is_wp_error($result)) {
            return $this->attach_prompt_debug_to_error(
                $result,
                $file_path,
                (string) $mime_type,
                $attachment_id
            );
        }

        return $result;
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

        if (!Plugin::has_consent()) {
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
            $result = $this->run_analysis(
                0,
                false,
                $downloaded['file_path'],
                $downloaded['mime_type']
            );

            if (is_wp_error($result)) {
                $result = $this->attach_prompt_debug_to_error(
                    $result,
                    $downloaded['file_path'],
                    $downloaded['mime_type'],
                    0
                );
            }

            return $result;
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
        $this->processing_warnings = new ProcessingWarnings();
        $this->last_request_characters = null;
        $result = [];
        $document_type = 'unknown';
        $extraction_method = null;
        $analysis_mode = EvidenceInstructions::MODE_TEXT;

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
            $analysis_mode = EvidenceInstructions::MODE_IMAGE;

        } elseif ($mime_type === 'application/pdf') {
            $document_type = 'pdf';
            $pdf_result = $this->analyze_pdf_smart($file_path);
            $ai_result = $pdf_result['result'];
            $extraction_method = $pdf_result['method'];
            $analysis_mode = $pdf_result['analysis_mode'] ?? EvidenceInstructions::MODE_TEXT;

        } elseif (in_array($mime_type, ['text/plain', 'text/html'])) {
            $document_type = 'text';
            $prepared = $this->prepare_document_text(
                (string) file_get_contents($file_path),
                $mime_type
            );
            $ai_result = $this->analyze_text(
                $prepared['content'],
                [
                    'document_type' => 'text',
                    'extraction_method' => 'text',
                ],
                EvidenceInstructions::MODE_TEXT,
                $mime_type === 'text/html'
                    ? EvidenceInstructions::DOCUMENT_FORMAT_HTML
                    : EvidenceInstructions::DOCUMENT_FORMAT_PLAIN
            );
            $extraction_method = 'text';
            $analysis_mode = EvidenceInstructions::MODE_TEXT;

        } else {
            return new \WP_Error(
                'unsupported_type',
                /* translators: %s: file type */
                sprintf(__('Unsupported file type: %s', 'tainacan-ai'), $mime_type),
                AnalysisErrorDebug::data(
                    array(
                        'mime_type' => (string) $mime_type,
                    )
                )
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
            return $this->attach_processing_to_error(
                $this->attach_request_context_to_error(
                    $ai_result,
                    $this->last_request_characters,
                    $analysis_mode
                ),
                $analysis_mode
            );
        }

        // Combine results
        $raw_metadata = $ai_result['metadata'] ?? $ai_result;
        if (is_array($raw_metadata)) {
            $normalized = EvidenceInstructions::normalize_metadata($raw_metadata);
            $field_definitions = [];

            if ($this->collection_id) {
                $context = $this->resolve_analysis_prompt_context($analysis_mode);
                $expected_slugs = !is_wp_error($context) ? ($context['expected_slugs'] ?? []) : [];
                $field_definitions = !is_wp_error($context) && is_array($context['fields'] ?? null)
                    ? $context['fields']
                    : [];

                if (is_array($expected_slugs) && $expected_slugs !== []) {
                    $normalized = ExtractionMetadata::get_instance()->complete_expected_fields_with_slugs(
                        $normalized,
                        $expected_slugs
                    );
                } else {
                    $normalized = ExtractionMetadata::get_instance()->complete_expected_fields($normalized, $this->collection_id);
                }
            }

            if (is_array($field_definitions) && $field_definitions !== []) {
                $normalized = $this->normalize_metadata_values_for_api($normalized, $field_definitions);
            }

            $result['ai_metadata'] = $normalized;
        } else {
            $result['ai_metadata'] = $raw_metadata;
        }
        $result['document_type'] = $document_type;
        $result['extraction_method'] = $extraction_method;
        $this->apply_request_details_to_result($result, $ai_result, $analysis_mode);
        $result['analyzed_at'] = current_time('mysql');
        $this->attach_processing_to_result($result, $analysis_mode);

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

            return AnalysisErrorDebug::wrap(
                $response,
                'remote_download_failed',
                sprintf(
                    /* translators: %s: low-level remote error */
                    __('Could not download document URL: %s', 'tainacan-ai'),
                    $response->get_error_message()
                ),
                array(
                    'document_url' => AnalysisErrorDebug::sanitize_url_for_debug($document_url),
                ),
                502
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
                ),
                AnalysisErrorDebug::data(
                    array(
                        'document_url' => AnalysisErrorDebug::sanitize_url_for_debug($document_url),
                        'http_status' => (string) $status_code,
                    ),
                    502
                )
            );
        }

        $content_type = (string) wp_remote_retrieve_header($response, 'content-type');
        $header_mime_type = strtolower(trim((string) preg_replace('/;.*/', '', $content_type)));
        $url_mime_type = (string) wp_check_filetype($document_url)['type'];
        $file_mime_type = $this->detect_local_file_mime_type($tmp_file);
        $mime_type = $this->resolve_remote_mime_type(
            $tmp_file,
            $document_url,
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
                ),
                AnalysisErrorDebug::data(
                    array(
                        'document_url' => AnalysisErrorDebug::sanitize_url_for_debug($document_url),
                        'mime_type' => $mime_type !== '' ? $mime_type : 'unknown',
                    ),
                    415
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
        string $document_url,
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

        // WordPress-core style fallback: extension + content-based detection.
        $filename = wp_basename((string) wp_parse_url($document_url, PHP_URL_PATH));
        if ($filename !== '') {
            $checked = wp_check_filetype_and_ext($file_path, $filename);
            $checked_type = strtolower(trim((string) ($checked['type'] ?? '')));
            if (in_array($checked_type, $this->supported_remote_document_types, true)) {
                return $checked_type;
            }
        }

        return $candidates[0] ?? '';
    }

    /**
     * WP_DEBUG-only server log (no prompt or response body).
     */
    private function debug_log(string $message): void {
        DebugLog::log((string) preg_replace('/\s+/', ' ', $message));
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

        DebugLog::log(implode(' ', $parts));
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
            $prepared = $this->prepare_document_text((string) $text, 'text/plain');
            $result = $this->analyze_text($prepared['content'], [
                'document_type' => 'pdf',
                'extraction_method' => 'text_extraction',
            ], EvidenceInstructions::MODE_PDF_TEXT);
            return [
                'result' => $result,
                'method' => 'text_extraction',
                'analysis_mode' => EvidenceInstructions::MODE_PDF_TEXT,
            ];
        }

        // Method 2: Convert to image and visual analysis (if the connector supports vision)
        $visual_result = null;
        if ($core_supports_image) {
            $visual_result = $this->analyze_pdf_visually($file_path);

            if (!is_wp_error($visual_result)) {
                return [
                    'result' => $visual_result,
                    'method' => 'visual_analysis',
                    'analysis_mode' => EvidenceInstructions::MODE_PDF_VISUAL,
                ];
            }

            $this->debug_log('PDF visual analysis failed: ' . $visual_result->get_error_message());
        }

        $failed_error = $this->build_pdf_analysis_failed_error(
            $text,
            $visual_result,
            $core_supports_image,
            $file_path
        );

        $this->debug_log('PDF analysis failed (all methods): ' . $failed_error->get_error_message());

        return [
            'result' => $failed_error,
            'method' => 'failed',
            'analysis_mode' => EvidenceInstructions::MODE_TEXT,
        ];
    }

    /**
     * Aggregate PDF text + vision failures into one WP_Error with debug context.
     */
    private function build_pdf_analysis_failed_error(
        string|\WP_Error $text,
        ?\WP_Error $visual_result,
        bool $core_supports_image,
        string $file_path
    ): \WP_Error {
        $error_msg = __('Could not analyze PDF.', 'tainacan-ai') . ' ';
        $debug_fields = array(
            'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
            'file_size' => is_readable($file_path) ? (string) filesize($file_path) : 'unknown',
            'vision_supported' => $core_supports_image ? 'yes' : 'no',
        );

        if (is_wp_error($text)) {
            $error_msg .= $text->get_error_message() . ' ';
            $debug_fields = array_merge(
                $debug_fields,
                AnalysisErrorDebug::export_wp_error_context($text, 'text_extraction')
            );
        } else {
            $error_msg .= __('PDF does not contain extractable text.', 'tainacan-ai') . ' ';
            $trimmed = trim((string) $text);
            $debug_fields['extracted_text_length'] = (string) strlen($trimmed);

            if ($trimmed !== '') {
                $sample = AnalysisErrorDebug::truncate($trimmed, 2000);
                $debug_fields['extracted_text_sample'] = array(
                    'label' => AnalysisErrorDebug::label_for('extracted_text_sample'),
                    'content' => $sample['content'],
                    'truncated' => $sample['truncated'],
                );
            }
        }

        if (!$core_supports_image) {
            $error_msg .= __('Core AI does not support image analysis on this site.', 'tainacan-ai');
            $debug_fields['visual_analysis_status'] = 'skipped (vision not supported)';
        } elseif ($visual_result instanceof \WP_Error) {
            $error_msg .= $visual_result->get_error_message();
            $debug_fields = array_merge(
                $debug_fields,
                AnalysisErrorDebug::export_wp_error_context($visual_result, 'visual_analysis')
            );
            $debug_fields['visual_analysis_status'] = 'failed';
        } else {
            $debug_fields['visual_analysis_status'] = 'not attempted';
        }

        $request_meta = AnalysisErrorDebug::request_meta_from_wp_error($visual_result);

        return new \WP_Error(
            'pdf_analysis_failed',
            trim($error_msg),
            AnalysisErrorDebug::data($debug_fields, 502, $request_meta)
        );
    }

    /**
     * Analyze PDF visually (for scanned PDFs)
     */
    private function analyze_pdf_visually(string $file_path): array|\WP_Error {
        $images = [];
        $max_pages = AnalysisLimits::get_pdf_visual_max_pages();

        try {
            $converter = new PdfToImage();
            $converter->setDpi(150)
                      ->setQuality(85)
                      ->setMaxPages($max_pages);

            $images = $converter->convert($file_path);

            if (empty($images)) {
                return new \WP_Error(
                    'conversion_failed',
                    __('Could not convert PDF to image. Install Imagick or Ghostscript.', 'tainacan-ai'),
                    AnalysisErrorDebug::data(
                        array(
                            'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
                            'file_size' => is_readable($file_path) ? (string) filesize($file_path) : 'unknown',
                            'imagick_available' => extension_loaded('imagick') ? 'yes' : 'no',
                        ),
                        500
                    )
                );
            }

            $prompt = $this->resolve_analysis_prompt(EvidenceInstructions::MODE_PDF_VISUAL);

            if (is_wp_error($prompt)) {
                return $prompt;
            }

            $pageCount = count($images);
            if ($pageCount >= $max_pages) {
                $this->processing_warnings->add(
                    'pdf_pages_limited',
                    'warning',
                    sprintf(
                        /* translators: %d: maximum number of PDF pages sent for visual analysis */
                        __('Only the first %d PDF page(s) were sent for visual analysis. Later pages were not processed.', 'tainacan-ai'),
                        $max_pages
                    ),
                    array(
                        'max_pages' => $max_pages,
                        'sent_pages' => $pageCount,
                    )
                );
            }

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

            $this->record_request_characters( $promptWithContext );

            $ai_result = CoreAI::generate_json_from_text_and_files(
                $promptWithContext,
                $image_data,
                $this->generation_options([
                    'document_type' => 'pdf',
                    'extraction_method' => 'visual_analysis',
                ])
            );

            if (is_wp_error($ai_result)) {
                return AnalysisErrorDebug::wrap(
                    $ai_result,
                    'visual_analysis_error',
                    $ai_result->get_error_message(),
                    array(
                        'pdf_pages_converted' => (string) $pageCount,
                        'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
                    ),
                    502
                );
            }

            return $ai_result;
        } catch (\Throwable $e) {
            $this->debug_log('PDF visual analysis error: ' . $e->getMessage());

            return AnalysisErrorDebug::from_throwable(
                $e,
                'visual_analysis_error',
                null,
                502
            );
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
                __('No configured connector exposes a model that accepts image input in its metadata. Use text extraction or configure a vision-capable model.', 'tainacan-ai'),
                AnalysisErrorDebug::data(
                    array(
                        'mime_type' => $mime_type,
                        'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
                    ),
                    501
                )
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
                return new \WP_Error(
                    'file_read_error',
                    __('Could not read image file.', 'tainacan-ai'),
                    AnalysisErrorDebug::data(
                        array(
                            'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
                        ),
                        500
                    )
                );
            }

            $base64 = base64_encode($image_content);

            if (empty($base64)) {
                return new \WP_Error(
                    'base64_error',
                    __('Error encoding image to base64.', 'tainacan-ai'),
                    AnalysisErrorDebug::data(
                        array(
                            'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
                            'file_size' => (string) strlen($image_content),
                        ),
                        500
                    )
                );
            }

            $image_data = "data:{$mime_type};base64,{$base64}";
        }

        $prompt = $this->resolve_analysis_prompt(EvidenceInstructions::MODE_IMAGE);

        if (is_wp_error($prompt)) {
            return $prompt;
        }

        $this->record_request_characters( $prompt );

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
    private function analyze_text(
        string $text,
        array $log_extra = [],
        string $analysis_mode = EvidenceInstructions::MODE_TEXT,
        string $document_format = EvidenceInstructions::DOCUMENT_FORMAT_PLAIN
    ): array|\WP_Error {
        $context = $this->resolve_analysis_prompt_context($analysis_mode);

        if (is_wp_error($context)) {
            return $context;
        }

        $prompt = $context['prompt'];
        $expected_slugs = is_array($context['expected_slugs'] ?? null)
            ? $context['expected_slugs']
            : [];

        $full_prompt = $this->build_text_analysis_prompt(
            $prompt,
            $text,
            $document_format,
            $expected_slugs
        );

        $this->record_request_characters( $full_prompt );

        return CoreAI::generate_json_from_text( $full_prompt, $this->generation_options( $log_extra ) );
    }

    /**
     * @param string[] $expected_slugs
     */
    private function build_text_analysis_prompt(
        string $instruction_prompt,
        string $document_body,
        string $document_format,
        array $expected_slugs
    ): string {
        $parts = array(
            $instruction_prompt,
            '---',
            '**Document:**',
            EvidenceInstructions::get_document_format_guidance($document_format),
            $document_body,
            '---',
            ExtractionMetadata::get_instance()->build_response_closing_reminder($expected_slugs),
        );

        return trim(implode("\n\n", array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== ''
        )));
    }

    /**
     * Resolve base prompt sections in deterministic order.
     *
     * analysis_mode selects task wording and evidence rules (image/text/pdf_*).
     *
     * @return string|\WP_Error
     */
    private function resolve_analysis_prompt(string $analysis_mode): string|\WP_Error {
        $context = $this->resolve_analysis_prompt_context($analysis_mode);

        if (is_wp_error($context)) {
            return $context;
        }

        return $context['prompt'];
    }

    /**
     * @return array{
     *     prompt: string,
     *     sections: array<string, string>,
     *     expected_slugs: string[],
     *     fields: array<string, array<string, mixed>>
     * }|\WP_Error
     */
    private function resolve_analysis_prompt_context(string $analysis_mode): array|\WP_Error {
        if (isset($this->prompt_context_cache[$analysis_mode])) {
            return $this->prompt_context_cache[$analysis_mode];
        }

        $context = AnalysisPromptComposer::get_context(
            (int) ($this->collection_id ?? 0),
            $this->get_user_preamble(),
            $analysis_mode
        );

        $prompt = trim((string) ($context['prompt'] ?? ''));
        if (
            $this->prompt_override !== null
            && self::should_include_prompt_in_response()
        ) {
            $prompt = $this->prompt_override;
        }

        if ($prompt === '') {
            return new \WP_Error(
                'no_preamble',
                __('No prompt preamble configured.', 'tainacan-ai'),
                AnalysisErrorDebug::data(
                    array(
                        'analysis_mode' => $analysis_mode,
                        'collection_id' => $this->collection_id ? (string) $this->collection_id : 'none',
                    ),
                    500
                )
            );
        }

        $resolved = [
            'prompt' => $prompt,
            'sections' => is_array($context['sections'] ?? null) ? $context['sections'] : [],
            'expected_slugs' => is_array($context['expected_slugs'] ?? null) ? array_values($context['expected_slugs']) : [],
            'fields' => is_array($context['fields'] ?? null) ? $context['fields'] : [],
        ];

        $this->prompt_context_cache[$analysis_mode] = $resolved;
        return $resolved;
    }

    /**
     * @param array<string, array{value: mixed, evidence: mixed|null, label?: mixed, pending_new_terms?: array<int, array{label: string, evidence: string|null}>}> $metadata
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array{value: mixed, evidence: mixed|null, label?: mixed, pending_new_terms?: array<int, array{label: string, evidence: string|null}>}>
     */
    private function normalize_metadata_values_for_api(array $metadata, array $fields): array {
        $metadata_helper = ExtractionMetadata::get_instance();

        foreach ($metadata as $slug => $entry) {
            if (!is_string($slug) || !is_array($entry) || !array_key_exists($slug, $fields) || !array_key_exists('value', $entry)) {
                continue;
            }

            $field = $fields[$slug];
            $type = $metadata_helper->format_metadata_type((string) ($field['type'] ?? ''));

            if ($type === 'taxonomy') {
                $metadata[$slug] = $this->normalize_taxonomy_metadata_entry($entry, $field);
                continue;
            }

            $allowed_value_options = $metadata_helper->get_allowed_value_options($field);
            if ($allowed_value_options !== []) {
                $is_multiple = isset($field['multiple']) && $field['multiple'] === true;
                $metadata[$slug] = $this->normalize_allowed_value_options_entry(
                    $entry,
                    $is_multiple,
                    $allowed_value_options
                );
                continue;
            }

            // Future: relationship fields can set allowed_value_options when ranked targets exist.

            if ($type === 'date') {
                $metadata[$slug] = $this->normalize_date_metadata_entry($entry);
            }
        }

        return $metadata;
    }

    /**
     * @param array<int, array<string, mixed>> $allowed_options
     * @return array<string, int|float|string>
     */
    private function build_allowed_value_label_lookup(array $allowed_options): array {
        $value_by_label = [];

        foreach ($allowed_options as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $api = $row['value'] ?? null;

            if ($label === '' || !is_scalar($api) || is_bool($api)) {
                continue;
            }

            if (is_string($api)) {
                $api = trim($api);
                if ($api === '') {
                    continue;
                }
            } elseif (is_int($api) || is_float($api)) {
                if ($api <= 0) {
                    continue;
                }
            } else {
                continue;
            }

            if (!isset($value_by_label[$label])) {
                $value_by_label[$label] = $api;
            }
        }

        return $value_by_label;
    }

    /**
     * Match LLM `value` (prompt strings) to allowed_value_options rows `{ value: API, label: prompt }`.
     *
     * Taxonomy uses term IDs as `value` and term names as `label`. Relationship can reuse the same shape.
     *
     * @param array{value: mixed, evidence: mixed|null, label?: mixed} $entry
     * @param array<int, array<string, mixed>> $allowed_options
     * @return array{value: mixed, evidence: mixed|null, label?: mixed}
     */
    private function normalize_allowed_value_options_entry(array $entry, bool $is_multiple, array $allowed_options): array {
        $raw_value = $entry['value'] ?? null;
        $candidates = is_array($raw_value) ? $raw_value : [$raw_value];
        $resolved_values = [];
        $resolved_labels = [];
        $value_by_label = $this->build_allowed_value_label_lookup($allowed_options);

        foreach ($candidates as $candidate) {
            $match_label = is_scalar($candidate) ? trim((string) $candidate) : '';
            if ($match_label === '' || !isset($value_by_label[$match_label])) {
                continue;
            }

            $resolved_values[] = $value_by_label[$match_label];
            $resolved_labels[] = $match_label;
        }

        $resolved_values = array_values(array_unique($resolved_values, SORT_REGULAR));
        $resolved_labels = array_values(array_unique(array_filter(
            array_map(
                static function ($lbl): string {
                    return is_string($lbl) ? trim($lbl) : '';
                },
                $resolved_labels
            ),
            static fn (string $lbl): bool => $lbl !== ''
        )));

        if ($is_multiple) {
            $entry['value'] = $resolved_values;
            if ($resolved_labels !== []) {
                $entry['label'] = $resolved_labels;
            } else {
                unset($entry['label']);
            }
            return $entry;
        }

        $entry['value'] = $resolved_values[0] ?? null;
        if ($resolved_labels !== []) {
            $entry['label'] = $resolved_labels[0];
        } else {
            unset($entry['label']);
        }

        return $entry;
    }

    /**
     * Taxonomy: whitelist normalization using `allowed_value_options`.
     *
     * When `allow_new_terms` is true, unmatched string suggestions are preserved in
     * `pending_new_terms` so the UI can offer user-driven term creation.
     *
     * @param array{value: mixed, evidence: mixed|null, label?: mixed, pending_new_terms?: array<int, array{label: string, evidence: string|null}>} $entry
     * @param array<string, mixed> $field
     * @return array{value: mixed, evidence: mixed|null, label?: mixed, pending_new_terms?: array<int, array{label: string, evidence: string|null}>}
     */
    private function normalize_taxonomy_metadata_entry(array $entry, array $field): array {
        $taxonomy_id = isset($field['taxonomy_id']) ? (int) $field['taxonomy_id'] : 0;
        if ($taxonomy_id <= 0) {
            return $entry;
        }

        $metadata_helper = ExtractionMetadata::get_instance();
        $allowed_value_options = $metadata_helper->get_allowed_value_options($field);
        $is_multiple = isset($field['multiple']) && $field['multiple'] === true;
        $allow_new_terms = ($field['allow_new_terms'] ?? null) === true;

        $normalized = $this->normalize_allowed_value_options_entry($entry, $is_multiple, $allowed_value_options);

        if (!$allow_new_terms) {
            unset($normalized['pending_new_terms']);
            return $normalized;
        }

        $value_by_label = $this->build_allowed_value_label_lookup($allowed_value_options);
        $raw_value = $entry['value'] ?? null;
        $candidates = is_array($raw_value) ? array_values($raw_value) : [$raw_value];
        $raw_evidence = $entry['evidence'] ?? null;
        $candidate_evidence = is_array($raw_evidence) ? array_values($raw_evidence) : [];
        $shared_evidence = !is_array($raw_evidence) && is_scalar($raw_evidence)
            ? trim((string) $raw_evidence)
            : '';
        $pending_new_terms = [];
        $seen_pending_labels = [];

        foreach ($candidates as $index => $candidate) {
            $candidate_label = is_scalar($candidate) ? trim((string) $candidate) : '';
            if ($candidate_label === '' || isset($value_by_label[$candidate_label]) || isset($seen_pending_labels[$candidate_label])) {
                continue;
            }

            $evidence = null;
            if (array_key_exists($index, $candidate_evidence) && is_scalar($candidate_evidence[$index])) {
                $candidate_evidence_value = trim((string) $candidate_evidence[$index]);
                if ($candidate_evidence_value !== '') {
                    $evidence = $candidate_evidence_value;
                }
            } elseif ($shared_evidence !== '') {
                $evidence = $shared_evidence;
            }

            $pending_new_terms[] = [
                'label' => $candidate_label,
                'evidence' => $evidence,
            ];
            $seen_pending_labels[$candidate_label] = true;
        }

        $has_resolved_values = false;
        if (array_key_exists('value', $normalized)) {
            $normalized_value = $normalized['value'];
            if (is_array($normalized_value)) {
                $has_resolved_values = count($normalized_value) > 0;
            } else {
                $has_resolved_values = $normalized_value !== null && $normalized_value !== '';
            }
        }

        // Keep pending terms only when no allowed option was resolved.
        if ($pending_new_terms !== [] && !$has_resolved_values) {
            $normalized['pending_new_terms'] = $pending_new_terms;
        } else {
            unset($normalized['pending_new_terms']);
        }

        return $normalized;
    }

    /**
     * @param array{value: mixed, evidence: mixed|null, label?: mixed} $entry
     * @return array{value: mixed, evidence: mixed|null, label?: mixed}
     */
    private function normalize_date_metadata_entry(array $entry): array {
        $value = $entry['value'] ?? null;

        if (!is_string($value)) {
            return $entry;
        }

        $normalized_value = trim($value);
        if ($normalized_value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized_value)) {
            return $entry;
        }

        $timestamp = strtotime($normalized_value . ' 00:00:00');
        if ($timestamp === false) {
            return $entry;
        }

        $date_format = (string) get_option('date_format', 'Y-m-d');
        $entry['label'] = wp_date($date_format, $timestamp);
        return $entry;
    }

    /**
     * Whether the resolved prompt may be included in AJAX/REST responses (never cached).
     */
    public static function should_include_prompt_in_response(): bool {
        $include = current_user_can('edit_posts')
            && (
                (defined('WP_DEBUG') && WP_DEBUG)
                || Plugin::is_advanced_debug()
            );

        /**
         * @param bool $include Default: WP_DEBUG or advanced_debug, and edit_posts capability.
         */
        return (bool) apply_filters('tainacan_ai_include_prompt_in_response', $include);
    }

    /**
     * Debug payload for the composed analysis prompt sections + attachment note.
     *
     * @return array{
     *     prompt: string,
     *     instruction_prompt: string,
     *     parts: array{user: string, task: string, rules: string, fields: string, schema: string, evidence: string, output: string},
     *     analysis_mode: string,
     *     attachment_note: string,
     *     document_body: array{type: string, content: string, truncated: bool}
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

        return $this->build_prompt_debug_payload_for_file(
            $this->normalize_file_path($file_path),
            (string) $mime_type,
            $attachment_id
        );
    }

    /**
     * Attach resolved prompt + document preview to a failed analysis WP_Error.
     */
    private function attach_prompt_debug_to_error(
        \WP_Error $error,
        string $file_path,
        string $mime_type,
        int $attachment_id = 0
    ): \WP_Error {
        if (!self::should_include_prompt_in_response()) {
            return $error;
        }

        if (!is_readable($file_path)) {
            return $error;
        }

        $payload = $this->build_prompt_debug_payload_for_file(
            $this->normalize_file_path($file_path),
            $mime_type,
            $attachment_id
        );

        if ($payload === null) {
            return $error;
        }

        $error_data = $error->get_error_data();
        if (!is_array($error_data)) {
            $error_data = [];
        }

        if (is_wp_error($payload)) {
            $error_data['prompt_debug'] = [
                'error' => $payload->get_error_message(),
            ];
        } else {
            $error_data['prompt_debug'] = $payload;
        }

        return new \WP_Error(
            (string) $error->get_error_code(),
            $error->get_error_message(),
            $error_data
        );
    }

    /**
     * @return array{
     *     prompt: string,
     *     instruction_prompt: string,
     *     parts: array{user: string, task: string, rules: string, fields: string, schema: string, evidence: string, output: string},
     *     analysis_mode: string,
     *     attachment_note: string,
     *     document_body: array{type: string, content: string, truncated: bool}
     * }|\WP_Error|null
     */
    private function build_prompt_debug_payload_for_file(
        string $file_path,
        string $mime_type,
        int $attachment_id = 0
    ): array|\WP_Error|null {
        if (!self::should_include_prompt_in_response()) {
            return null;
        }

        if (!$this->collection_id && $this->item_id) {
            $this->collection_id = $this->get_item_collection($this->item_id);
        }

        $analysis_mode = $this->guess_analysis_mode($file_path, $mime_type);
        $context = $this->resolve_analysis_prompt_context($analysis_mode);

        if (is_wp_error($context)) {
            return $context;
        }

        $attachment_note = $this->get_prompt_attachment_note($file_path, $mime_type, $analysis_mode);
        $instruction_prompt = $context['prompt'];
        $sections = $context['sections'];
        $prompt = $instruction_prompt;

        if ($attachment_note !== '') {
            $prompt .= "\n\n" . $attachment_note;
        }

        $document_body = $this->get_prompt_document_body_preview(
            $file_path,
            $mime_type,
            $analysis_mode,
            $attachment_id
        );

        return [
            'prompt' => $prompt,
            'instruction_prompt' => $instruction_prompt,
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
            'document_body' => $document_body,
        ];
    }

    /**
     * Text that is appended or described for the model (debug preview only).
     *
     * @return array{type: string, content: string, truncated: bool}
     */
    private function get_prompt_document_body_preview(
        string $file_path,
        string $mime_type,
        string $analysis_mode,
        int $attachment_id = 0
    ): array {
        if ($analysis_mode === EvidenceInstructions::MODE_IMAGE) {
            return [
                'type' => EvidenceInstructions::MODE_IMAGE,
                'content' => $this->get_visual_attachment_debug_summary($attachment_id),
                'truncated' => false,
            ];
        }

        if ($analysis_mode === EvidenceInstructions::MODE_PDF_VISUAL) {
            return [
                'type' => EvidenceInstructions::MODE_PDF_VISUAL,
                'content' => $this->get_visual_attachment_debug_summary($attachment_id),
                'truncated' => false,
            ];
        }

        $text = '';

        if (is_readable($file_path)) {
            if ($mime_type === 'application/pdf') {
                $extracted = $this->extract_pdf_text($file_path);
                $text = is_wp_error($extracted) ? '' : $extracted;
                if ($text !== '') {
                    $prepared = DocumentTextPreparer::prepare($text, 'text/plain');
                    $text = $prepared['content'];
                    $truncated = $prepared['truncated'];
                    $original_length = $prepared['original_length'];
                    $sent_length = $prepared['sent_length'];
                    $document_warnings = $prepared['warnings']->to_list();
                }
            } else {
                $raw = file_get_contents($file_path);
                $prepared = DocumentTextPreparer::prepare($raw === false ? '' : $raw, $mime_type);
                $text = $prepared['content'];
                $truncated = $prepared['truncated'];
                $original_length = $prepared['original_length'];
                $sent_length = $prepared['sent_length'];
                $document_warnings = $prepared['warnings']->to_list();
            }
        }

        if (!isset($truncated)) {
            $truncated = false;
            $original_length = mb_strlen($text, 'UTF-8');
            $sent_length = $original_length;
            $document_warnings = [];
        }

        return [
            'type' => $analysis_mode === EvidenceInstructions::MODE_PDF_TEXT
                ? EvidenceInstructions::MODE_PDF_TEXT
                : EvidenceInstructions::MODE_TEXT,
            'content' => $text,
            'truncated' => $truncated,
            'original_length' => $original_length,
            'sent_length' => $sent_length,
            'warnings' => $document_warnings,
        ];
    }

    private function get_visual_attachment_debug_summary(int $attachment_id): string {
        if ($attachment_id <= 0) {
            return '';
        }

        $title = trim((string) get_the_title($attachment_id));
        $url = (string) wp_get_attachment_url($attachment_id);
        $mime_type = (string) get_post_mime_type($attachment_id);
        $lines = [];

        if ($title !== '') {
            $lines[] = 'Title: ' . $title;
        }
        if ($mime_type !== '') {
            $lines[] = 'MIME: ' . $mime_type;
        }
        if ($url !== '') {
            $lines[] = 'URL: ' . $url;
        }

        return implode("\n", $lines);
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

        if ($mime_type === 'application/pdf') {
            $extracted = $this->extract_pdf_text($file_path);
            $text = is_wp_error($extracted) ? '' : $extracted;
            $prepared = DocumentTextPreparer::prepare($text, 'text/plain');
        } else {
            $raw = file_get_contents($file_path);
            $prepared = DocumentTextPreparer::prepare($raw === false ? '' : $raw, $mime_type);
        }

        return sprintf(
            '--- Document body appended (%1$s of %2$s characters%3$s) ---',
            number_format_i18n($prepared['sent_length']),
            number_format_i18n($prepared['original_length']),
            $prepared['truncated'] ? ', truncated' : ''
        );
    }

    /**
     * User preamble: collection post meta, falling back to the site default option.
     */
    private function get_user_preamble(): string {
        if ($this->collection_id) {
            return CollectionFormHook::get_effective_preamble($this->collection_id);
        }

        return (string) ($this->options['default_preamble'] ?? '');
    }

    /**
     * Extract text from PDF using multiple methods
     */
    private function extract_pdf_text(string $file_path): string|\WP_Error {
        $methods_tried = array();
        $last_error = '';

        // Method 1: smalot/pdfparser (best quality for digital PDFs)
        if (class_exists('\Smalot\PdfParser\Parser')) {
            $methods_tried[] = 'smalot/pdfparser';
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file_path);
                $text = $pdf->getText();

                if (!empty(trim($text))) {
                    return $text;
                }
            } catch (\Throwable $e) {
                $last_error = $e->getMessage();
                $this->debug_log('Smalot PdfParser error: ' . $last_error);
            }
        }

        // Method 2: Built-in plugin parser (fallback)
        $methods_tried[] = 'tainacan/pdfparser';
        try {
            $parser = new PdfParser();
            $text = $parser->parseFile($file_path)->getText();

            if (!empty(trim($text))) {
                return $text;
            }
        } catch (\Throwable $e) {
            $last_error = $e->getMessage();
            $this->debug_log('PdfParser error: ' . $last_error);
        }

        // Method 3: pdftotext (poppler-utils) - Linux/Mac
        if (function_exists('shell_exec') && !$this->is_windows()) {
            $methods_tried[] = 'pdftotext (unix)';
            $escaped_path = escapeshellarg($file_path);
            $output = @shell_exec("pdftotext {$escaped_path} - 2>/dev/null");

            if (!empty($output)) {
                return $output;
            }
        }

        // Method 4: pdftotext on Windows
        if ($this->is_windows() && function_exists('shell_exec')) {
            $methods_tried[] = 'pdftotext (windows)';
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
        $methods_tried[] = 'regex';
        $text = $this->basic_pdf_text_extract($file_path);
        if (!empty(trim($text))) {
            return $text;
        }

        $message = __('Could not extract text from PDF. The document may be a scanned image.', 'tainacan-ai');
        $this->debug_log('PDF text extraction failed: ' . $message);

        $debug_fields = array(
            'file_path' => AnalysisErrorDebug::basename_for_debug($file_path),
            'file_size' => is_readable($file_path) ? (string) filesize($file_path) : 'unknown',
            'extraction_methods_tried' => implode(', ', $methods_tried),
        );

        if ($last_error !== '') {
            $debug_fields['last_parser_error'] = $last_error;
        }

        return new \WP_Error(
            'pdf_extract_failed',
            $message,
            AnalysisErrorDebug::data($debug_fields, 422)
        );
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
        return DocumentTextPreparer::sanitize_utf8_string($string);
    }

    /**
     * @return array{
     *     content: string,
     *     truncated: bool,
     *     original_length: int,
     *     sent_length: int,
     *     warnings: ProcessingWarnings
     * }
     */
    private function prepare_document_text(string $raw, string $mime_type): array {
        $prepared = DocumentTextPreparer::prepare($raw, $mime_type);
        $this->processing_warnings->merge($prepared['warnings']);

        return $prepared;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function attach_processing_to_result(array &$result, string $analysis_mode): void {
        $processing = $this->build_processing_payload($analysis_mode);
        if ($processing !== null) {
            $result['processing'] = $processing;
        }
    }

    private function attach_processing_to_error(\WP_Error $error, string $analysis_mode): \WP_Error {
        $processing = $this->build_processing_payload($analysis_mode);
        if ($processing === null) {
            return $error;
        }

        $error_data = $error->get_error_data();
        if (!is_array($error_data)) {
            $error_data = [];
        }

        $error_data['processing'] = $processing;

        return new \WP_Error(
            (string) $error->get_error_code(),
            $error->get_error_message(),
            $error_data
        );
    }

    /**
     * @return array{warnings: list<array<string, mixed>>}|null
     */
    private function build_processing_payload(string $analysis_mode): ?array {
        $context = $this->resolve_analysis_prompt_context($analysis_mode);
        if (!is_wp_error($context)) {
            $this->merge_prompt_processing_warnings($context['fields'] ?? []);
        }

        return $this->processing_warnings->to_payload();
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function merge_prompt_processing_warnings(array $fields): void {
        $truncated_labels = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            if (!empty($field['allowed_values_truncated'])) {
                $label = trim((string) ($field['label'] ?? $field['name'] ?? ''));
                $truncated_labels[] = $label !== '' ? $label : (string) ($field['slug'] ?? '');
            }
        }

        $truncated_labels = array_values(array_filter($truncated_labels));

        if ($truncated_labels === []) {
            return;
        }

        $this->processing_warnings->add(
            'prompt_allowed_values_truncated',
            'warning',
            sprintf(
                /* translators: 1: comma-separated field labels, 2: configured term limit */
                __( 'Some fields include only a subset of allowed taxonomy terms in the prompt (%1$s). The prompt lists up to %2$s terms per taxonomy field.', 'tainacan-ai' ),
                implode(', ', $truncated_labels),
                number_format_i18n(AnalysisLimits::get_taxonomy_allowed_values_limit())
            ),
            array(
                'fields' => implode(', ', $truncated_labels),
                'max_terms' => (string) AnalysisLimits::get_taxonomy_allowed_values_limit(),
            )
        );
    }

    private function attach_request_context_to_error(
        \WP_Error $error,
        ?int $request_characters,
        string $analysis_mode = ''
    ): \WP_Error {
        $error_data = $error->get_error_data();
        if (!is_array($error_data)) {
            $error_data = [];
        }

        $request_meta = isset($error_data['request_meta']) && is_array($error_data['request_meta'])
            ? $error_data['request_meta']
            : [];

        if ($request_characters !== null && $request_characters > 0) {
            $request_meta['request_characters'] = $request_characters;
        }

        if ($analysis_mode !== '') {
            $request_meta['analysis_mode'] = $analysis_mode;
        }

        if ($request_meta !== []) {
            $error_data['request_meta'] = AnalysisErrorDebug::normalize_request_meta($request_meta);
        }

        return new \WP_Error(
            (string) $error->get_error_code(),
            $error->get_error_message(),
            $error_data
        );
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $ai_result
     */
    private function apply_request_details_to_result(array &$result, array $ai_result, string $analysis_mode): void {
        $meta = is_array($ai_result['request_meta'] ?? null)
            ? $ai_result['request_meta']
            : [];

        if ($this->last_request_characters !== null && $this->last_request_characters > 0) {
            $meta['request_characters'] = $this->last_request_characters;
        }

        if ($analysis_mode !== '') {
            $meta['analysis_mode'] = $analysis_mode;
        }

        if ($meta === [] && isset($ai_result['usage'])) {
            $meta = [
                'tokens_used' => (int) ($ai_result['usage']['total_tokens'] ?? 0),
                'prompt_tokens' => (int) ($ai_result['usage']['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($ai_result['usage']['completion_tokens'] ?? 0),
                'model_used' => (string) ($ai_result['model'] ?? ''),
                'provider_used' => (string) ($ai_result['provider'] ?? ''),
            ];
        }

        $normalized = AnalysisErrorDebug::normalize_request_meta($meta);
        if ($normalized === null) {
            return;
        }

        foreach ($normalized as $key => $value) {
            $result[$key] = $value;
        }
    }

    private function record_request_characters( string $text ): void {
        $length = mb_strlen( $text, 'UTF-8' );
        $this->last_request_characters = $length > 0 ? $length : null;
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

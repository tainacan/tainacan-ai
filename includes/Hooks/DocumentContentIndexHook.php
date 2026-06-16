<?php
declare(strict_types=1);

namespace Tainacan\AI\Hooks;

use Tainacan\AI\Extraction\DocumentAnalyzer;
use Tainacan\AI\Extraction\EvidenceInstructions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrates Tainacan AI document extraction with Tainacan core `document_content_index`.
 *
 * - Hooks into core filters so the admin modal can extract URL/PDF text via this plugin.
 * - Supplies extraction payloads from an existing index for the AI REST `/extract` and `/analyze` routes.
 */
class DocumentContentIndexHook {

    public const SOURCE_DOCUMENT_CONTENT_INDEX = 'document_content_index';
    public const SOURCE_FILE_EXTRACTION = 'file_extraction';

    public function __construct() {
        add_filter(
            'tainacan_item_supports_document_content_extraction',
            [$this, 'filter_supports_document_content_extraction'],
            10,
            2
        );

        add_filter(
            'tainacan_extract_document_content',
            [$this, 'filter_extract_document_content'],
            10,
            3
        );
    }

    public function filter_supports_document_content_extraction(bool $supports, \Tainacan\Entities\Item $item): bool {
        if ($supports) {
            return true;
        }

        $document_type = (string) $item->get_document_type();

        if ($document_type === 'url' && !empty($item->get_document())) {
            return true;
        }

        if ($document_type === 'attachment' && !empty($item->get_document()) && is_numeric($item->get_document())) {
            $attachment_id = (int) $item->get_document();

            if (wp_attachment_is_image($attachment_id)) {
                return false;
            }

            return get_post_mime_type($attachment_id) === 'application/pdf';
        }

        return $supports;
    }

    /**
     * @param null|string|false|\WP_Error $extracted_content
     * @param mixed                       $context
     * @return null|string|false|\WP_Error
     */
    public function filter_extract_document_content(
        $extracted_content,
        \Tainacan\Entities\Item $item,
        $context = null
    ) {
        if ($extracted_content !== null) {
            return $extracted_content;
        }

        $document_type = (string) $item->get_document_type();

        if ($document_type === 'url' && !empty($item->get_document())) {
            return $this->extract_url_text_for_index($item, (string) $item->get_document());
        }

        if ($document_type === 'attachment' && !empty($item->get_document()) && is_numeric($item->get_document())) {
            $attachment_id = (int) $item->get_document();

            if (wp_attachment_is_image($attachment_id)) {
                return null;
            }

            if (get_post_mime_type($attachment_id) === 'application/pdf') {
                return $this->extract_attachment_text_for_index($item, $attachment_id);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build_extraction_from_item_index(array $context): ?array {
        $item_id = (int) ($context['item_id'] ?? 0);

        if ($item_id <= 0) {
            return null;
        }

        $content = $this->get_item_content_index($item_id);

        if ($content === '') {
            return null;
        }

        $attachment_id = (int) ($context['attachment_id'] ?? 0);
        $document_data = is_array($context['document_data'] ?? null) ? $context['document_data'] : [];
        $is_remote_url_document = !empty($context['is_remote_url_document']);
        $document_url = $is_remote_url_document ? (string) ($context['document_url'] ?? '') : '';
        $metadata = $this->resolve_index_extraction_metadata($document_data);

        $extraction = [
            'attachment_id' => $attachment_id,
            'mime_type' => $metadata['mime_type'],
            'file_path' => '',
            'document_type' => $metadata['document_type'],
            'extraction_method' => self::SOURCE_DOCUMENT_CONTENT_INDEX,
            'analysis_mode' => $metadata['analysis_mode'],
            'extracted_text' => $content,
            'document_format' => EvidenceInstructions::DOCUMENT_FORMAT_PLAIN,
            'exif' => null,
            'exif_summary' => null,
            'vision_image' => null,
            'vision_images' => null,
            'vision_page_count' => null,
            'vision_total_pages' => null,
            'content_source' => self::SOURCE_DOCUMENT_CONTENT_INDEX,
            'extracted_at' => current_time('mysql'),
            'extraction_run_id' => (string) (int) round(microtime(true) * 1000),
        ];

        if ($document_url !== '') {
            $extraction['document_url'] = $document_url;
        }

        return $extraction;
    }

    public function get_item_content_index(int $item_id): string {
        if (!class_exists('\Tainacan\Repositories\Items')) {
            return '';
        }

        $item = \Tainacan\Repositories\Items::get_instance()->fetch($item_id);

        if (!$item instanceof \Tainacan\Entities\Item) {
            return '';
        }

        return trim((string) $item->get_document_content_index());
    }

    /**
     * @return string|false|\WP_Error
     */
    private function extract_url_text_for_index(\Tainacan\Entities\Item $item, string $document_url): string|false|\WP_Error {
        $analyzer = $this->create_analyzer_for_item($item);
        $extraction = $analyzer->extract_document_url($document_url);

        if (is_wp_error($extraction)) {
            return $extraction;
        }

        return $this->map_extraction_to_index_text($extraction);
    }

    /**
     * @return string|false|\WP_Error
     */
    private function extract_attachment_text_for_index(\Tainacan\Entities\Item $item, int $attachment_id): string|false|\WP_Error {
        $analyzer = $this->create_analyzer_for_item($item);
        $extraction = $analyzer->extract($attachment_id, false);

        if (is_wp_error($extraction)) {
            return $extraction;
        }

        return $this->map_extraction_to_index_text($extraction);
    }

    private function create_analyzer_for_item(\Tainacan\Entities\Item $item): DocumentAnalyzer {
        $analyzer = new DocumentAnalyzer();
        $analyzer->set_context((int) $item->get_collection_id(), (int) $item->get_ID());

        return $analyzer;
    }

    /**
     * @param array<string, mixed> $extraction
     * @return string|false
     */
    private function map_extraction_to_index_text(array $extraction): string|false {
        $analysis_mode = (string) ($extraction['analysis_mode'] ?? '');

        if (
            $analysis_mode === EvidenceInstructions::MODE_IMAGE
            || $analysis_mode === EvidenceInstructions::MODE_PDF_VISUAL
        ) {
            return false;
        }

        $text = trim((string) ($extraction['extracted_text'] ?? ''));

        return $text !== '' ? $text : false;
    }

    /**
     * @param array<string, mixed> $document_data
     * @return array{mime_type: string, document_type: string, analysis_mode: string}
     */
    private function resolve_index_extraction_metadata(array $document_data): array {
        $mime_type = (string) ($document_data['mime_type'] ?? 'text/plain');
        $type = (string) ($document_data['type'] ?? 'unknown');

        if ($type === 'pdf' || $mime_type === 'application/pdf') {
            return [
                'mime_type' => 'application/pdf',
                'document_type' => 'pdf',
                'analysis_mode' => EvidenceInstructions::MODE_PDF_TEXT,
            ];
        }

        if ($type === 'text' || strpos($mime_type, 'text/') === 0) {
            return [
                'mime_type' => $mime_type !== '' ? $mime_type : 'text/plain',
                'document_type' => 'text',
                'analysis_mode' => EvidenceInstructions::MODE_TEXT,
            ];
        }

        return [
            'mime_type' => $mime_type !== '' ? $mime_type : 'text/plain',
            'document_type' => 'text',
            'analysis_mode' => EvidenceInstructions::MODE_TEXT,
        ];
    }
}

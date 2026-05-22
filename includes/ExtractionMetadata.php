<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-metadatum AI extraction flag and collection field discovery.
 *
 * Stores opt-out only: absent meta means the field is included in extraction.
 *
 * @see https://github.com/tainacan/tainacan-ai/issues/7
 */
class ExtractionMetadata {

    public const META_KEY = 'tainacan_ai_exclude';
    public const POST_TYPE = 'tainacan-metadatum';

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init_hooks(): void {
        add_action('init', [$this, 'register_post_meta'], 15);
    }

    public function register_post_meta(): void {
        register_post_meta(
            self::POST_TYPE,
            self::META_KEY,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => static function (): bool {
                    return current_user_can('edit_posts');
                },
            ]
        );
    }

    public static function meta_key(): string {
        return self::META_KEY;
    }

    public function is_excluded(\Tainacan\Entities\Metadatum $metadatum): bool {
        $id = (int) $metadatum->get_id();

        if ($id <= 0) {
            return true;
        }

        return metadata_exists('post', $id, self::META_KEY)
            && rest_sanitize_boolean(get_post_meta($id, self::META_KEY, true));
    }

    public function is_enabled(\Tainacan\Entities\Metadatum $metadatum): bool {
        return !$this->is_excluded($metadatum);
    }

    /**
     * Fields to extract for a collection (keyed by metadata slug).
     *
     * @return array<string, array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     type: string,
     *     multiple: bool,
     *     description: string,
     *     placeholder: string
     * }>
     */
    public function get_fields_for_collection(int $collection_id): array {
        if ($collection_id <= 0 || !class_exists('\Tainacan\Repositories\Metadata')) {
            return [];
        }

        try {
            $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
            $collection = new \Tainacan\Entities\Collection($collection_id);
            $metadata_list = $metadata_repo->fetch_by_collection($collection, [], 'OBJECT');
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TainacanAI] Extraction fields lookup failed: ' . $e->getMessage());
            }
            return [];
        }

        $fields = [];

        foreach ($metadata_list as $metadatum) {
            if (!$metadatum instanceof \Tainacan\Entities\Metadatum) {
                continue;
            }

            if (!$this->is_enabled($metadatum)) {
                continue;
            }

            if (method_exists($metadatum, 'get_enabled_for_collection') && !$metadatum->get_enabled_for_collection()) {
                continue;
            }

            $slug = (string) $metadatum->get_slug();
            if ($slug === '') {
                continue;
            }

            $fields[$slug] = [
                'id' => (int) $metadatum->get_id(),
                'slug' => $slug,
                'name' => (string) $metadatum->get_name(),
                'type' => (string) $metadatum->get_metadata_type(),
                'multiple' => $metadatum->get_multiple() === 'yes',
                'description' => trim((string) $metadatum->get_description()),
                'placeholder' => trim((string) $metadatum->get_placeholder()),
            ];
        }

        return $fields;
    }

    /**
     * Plugin-provided field list and JSON response keys for the analysis prompt.
     */
    public function build_instructions_section(int $collection_id): string {
        $fields = $this->get_fields_for_collection($collection_id);

        if ($fields === []) {
            return '';
        }

        $fields_list = [];
        $json_example = [];

        foreach ($fields as $slug => $field) {
            $line = '- **' . $slug . '** (' . $field['name'] . ')';

            if ($field['multiple']) {
                $line .= ' — ' . __('multivalued: use parallel arrays in "value" and "evidence"', 'tainacan-ai');
            }

            if ($field['description'] !== '') {
                $line .= "\n  - " . __('Extraction guidance:', 'tainacan-ai') . ' ' . $field['description'];
            }

            if ($field['placeholder'] !== '') {
                $line .= "\n  - " . __('Expected format hint:', 'tainacan-ai') . ' ' . $field['placeholder'];
            }

            $fields_list[] = $line;
            $json_example[$slug] = $field['multiple']
                ? [
                    'value' => ['example 1', 'example 2'],
                    'evidence' => ['source for example 1', 'source for example 2'],
                ]
                : [
                    'value' => null,
                    'evidence' => '',
                ];
        }

        $fields_text = implode("\n", $fields_list);
        $json_text = json_encode($json_example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return '## ' . __('Fields to extract', 'tainacan-ai') . "\n" .
            $fields_text . "\n\n" .
            '## ' . __('Extraction instructions', 'tainacan-ai') . "\n" .
            '- ' . __('Extract information for EACH field listed above.', 'tainacan-ai') . "\n" .
            '- ' . __('Follow per-field extraction guidance when provided.', 'tainacan-ai') . "\n" .
            '- ' . __('Return JSON using EXACTLY the response keys below (metadata slugs).', 'tainacan-ai') . "\n\n" .
            '## ' . __('Response keys', 'tainacan-ai') . "\n" .
            $json_text;
    }
}

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
     * Complete the AI metadata payload with all expected slugs for this collection.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, array{value: mixed, evidence: mixed|null}>
     */
    public function complete_expected_fields(array $metadata, int $collection_id): array {
        $fields = $this->get_fields_for_collection($collection_id);
        $completed = [];

        foreach ($metadata as $slug => $entry) {
            if (!is_string($slug)) {
                continue;
            }

            if (is_array($entry) && array_key_exists('value', $entry)) {
                $completed[$slug] = [
                    'value' => $entry['value'],
                    'evidence' => $entry['evidence'] ?? null,
                ];
                continue;
            }

            $completed[$slug] = [
                'value' => $entry,
                'evidence' => null,
            ];
        }

        foreach (array_keys($fields) as $slug) {
            if (!array_key_exists($slug, $completed)) {
                $completed[$slug] = [
                    'value' => null,
                    'evidence' => null,
                ];
            }
        }

        return $completed;
    }

    public function format_metadata_type(string $class_name): string {
        $type = trim($class_name);

        if ($type === '') {
            return 'unknown';
        }

        if (str_contains($type, '\\')) {
            $parts = explode('\\', $type);
            $type = (string) end($parts);
        }

        $type = strtolower($type);

        return $type !== '' ? $type : 'unknown';
    }

    /**
     * @param array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     type: string,
     *     multiple: bool,
     *     description: string,
     *     placeholder: string
     * } $field
     */
    public function get_field_extraction_mode(array $field): string {
        $type = $this->format_metadata_type((string) ($field['type'] ?? ''));

        if (str_contains($type, 'taxonomy') || str_contains($type, 'relationship')) {
            return 'exploratory';
        }

        return 'strict';
    }

    /**
     * @param array<string, array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     type: string,
     *     multiple: bool,
     *     description: string,
     *     placeholder: string
     * }> $fields
     */
    public function build_fields_section(array $fields): string {
        if ($fields === []) {
            return '';
        }

        $lines = ['FIELDS'];

        foreach ($fields as $slug => $field) {
            $lines[] = '';
            $lines[] = $slug;
            $lines[] = '- type: ' . $this->format_metadata_type((string) $field['type']);
            $lines[] = '- label: ' . $field['name'];
            $lines[] = '- multivalued: ' . ($field['multiple'] ? 'true' : 'false');
            $lines[] = '- mode: ' . $this->get_field_extraction_mode($field);

            if ($field['description'] !== '') {
                $lines[] = '- field_guidance: ' . $field['description'];
            }

            if ($field['placeholder'] !== '') {
                $lines[] = '- expected_format_hint: ' . $field['placeholder'];
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param string[] $slugs
     */
    public function build_output_keys_section(array $slugs): string {
        if ($slugs === []) {
            return '';
        }

        return 'OUTPUT KEYS' . "\n" .
            'Return one JSON object with exactly these keys:' . "\n" .
            implode(', ', $slugs);
    }

    public function build_field_format_section(): string {
        return 'FIELD FORMAT' . "\n" .
            'Each slug maps to {"value": scalar|array|null, "evidence": string|array|null}.' . "\n" .
            'Single-value fields: scalar value and string|null evidence.' . "\n" .
            'Multivalued fields: value and evidence must be parallel arrays with equal length.' . "\n" .
            'Missing support: set value to null, evidence null or omitted.' . "\n" .
            'Output must be ONLY JSON (no markdown, no comments, no prose).';
    }

    /**
     * Backward-compatible combined instructions section.
     */
    public function build_instructions_section(int $collection_id): string {
        $fields = $this->get_fields_for_collection($collection_id);

        if ($fields === []) {
            return '';
        }

        return $this->build_fields_section($fields) . "\n\n" .
            $this->build_field_format_section() . "\n\n" .
            $this->build_output_keys_section(array_keys($fields));
    }
}

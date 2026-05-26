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
    public const TAXONOMY_ALLOWED_VALUES_LIMIT = 100;

    private static ?self $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init_hooks(): void {
        add_action('init', [$this, 'register_post_meta'], 15);
        add_filter('tainacan_ai_extraction_field', [$this, 'inject_known_metadata_type_hints'], 10, 4);
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
     *     required: bool,
     *     max_items: int|null,
     *     description: string,
     *     placeholder: string,
     *     min: int|float|string|null,
     *     max: int|float|string|null,
     *     step: int|float|string|null,
     *     max_length: int|null,
     *     mask: string,
     *     taxonomy_id: int|null,
     *     allow_new_terms: bool|null,
     *     allowed_values_truncated: bool,
     *     target_collection: int|null,
     *     relationship_search_field: int|null,
     *     allowed_values: string[]
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
                'required' => method_exists($metadatum, 'get_required') && $metadatum->get_required() === 'yes',
                'max_items' => $this->normalize_max_items(
                    method_exists($metadatum, 'get_cardinality') ? $metadatum->get_cardinality() : null
                ),
                'description' => trim((string) $metadatum->get_description()),
                'placeholder' => trim((string) $metadatum->get_placeholder()),
            ];

            $metadata_type_options = $this->get_metadata_type_options($metadatum);
            $field_with_hints = apply_filters(
                'tainacan_ai_extraction_field',
                $fields[$slug],
                $metadatum,
                $collection_id,
                $metadata_type_options
            );

            if (is_array($field_with_hints)) {
                $fields[$slug] = array_merge($fields[$slug], $field_with_hints);
            }
        }

        return $fields;
    }

    /**
     * Default field hint enrichment using known Tainacan metadata type options.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $metadata_type_options
     * @return array<string, mixed>
     */
    public function inject_known_metadata_type_hints(
        array $field,
        \Tainacan\Entities\Metadatum $metadatum,
        int $collection_id,
        array $metadata_type_options
    ): array {
        unset($metadatum, $collection_id);
        return array_merge($field, $this->build_metadata_type_hints_from_options($field, $metadata_type_options));
    }

    /**
     * Complete the AI metadata payload with all expected slugs for this collection.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, array{value: mixed, evidence: mixed|null, label?: mixed}>
     */
    public function complete_expected_fields(array $metadata, int $collection_id): array {
        $fields = $this->get_fields_for_collection($collection_id);
        return $this->complete_expected_fields_with_slugs($metadata, array_keys($fields));
    }

    /**
     * Complete the AI metadata payload with a pre-resolved list of expected slugs.
     *
     * @param array<string, mixed> $metadata
     * @param string[] $expected_slugs
     * @return array<string, array{value: mixed, evidence: mixed|null, label?: mixed}>
     */
    public function complete_expected_fields_with_slugs(array $metadata, array $expected_slugs): array {
        $completed = [];

        foreach ($metadata as $slug => $entry) {
            if (!is_string($slug)) {
                continue;
            }

            if (is_array($entry) && array_key_exists('value', $entry)) {
                $normalized_entry = [
                    'value' => $entry['value'],
                    'evidence' => $entry['evidence'] ?? null,
                ];
                if (array_key_exists('label', $entry)) {
                    $normalized_entry['label'] = $entry['label'];
                }
                $completed[$slug] = $normalized_entry;
                continue;
            }

            $completed[$slug] = [
                'value' => $entry,
                'evidence' => null,
            ];
        }

        foreach ($expected_slugs as $slug) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }

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
     *     required: bool,
     *     max_items: int|null,
     *     description: string,
     *     placeholder: string,
     *     min: int|float|string|null,
     *     max: int|float|string|null,
     *     step: int|float|string|null,
     *     max_length: int|null,
     *     mask: string,
     *     taxonomy_id: int|null,
     *     allow_new_terms: bool|null,
     *     allowed_values_truncated: bool,
     *     taxonomy_allowed_values: array<int, array{value: int, label: string}>,
     *     target_collection: int|null,
     *     relationship_search_field: int|null,
     *     allowed_values: string[]
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
     *     required: bool,
     *     max_items: int|null,
     *     description: string,
     *     placeholder: string,
     *     min: int|float|string|null,
     *     max: int|float|string|null,
     *     step: int|float|string|null,
     *     max_length: int|null,
     *     mask: string,
     *     taxonomy_id: int|null,
     *     allow_new_terms: bool|null,
     *     allowed_values_truncated: bool,
     *     target_collection: int|null,
     *     relationship_search_field: int|null,
     *     allowed_values: string[]
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

            $field_prompt_hints = $this->build_field_prompt_hints($field);
            unset($field_prompt_hints['taxonomy_allowed_values']);

            foreach ($field_prompt_hints as $key => $value) {
                $lines[] = '- ' . $key . ': ' . $this->format_prompt_value($value);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private function build_field_prompt_hints(array $field): array {
        $field_hints = [];

        if (($field['required'] ?? false) === true) {
            $field_hints['required'] = true;
        }

        if (isset($field['max_items']) && is_int($field['max_items'])) {
            $field_hints['max_items'] = $field['max_items'];
        }

        if (isset($field['description']) && is_string($field['description']) && trim($field['description']) !== '') {
            $field_hints['field_guidance'] = trim($field['description']);
        }

        if (isset($field['placeholder']) && is_string($field['placeholder']) && trim($field['placeholder']) !== '') {
            $field_hints['expected_format_hint'] = trim($field['placeholder']);
        }

        $core_field_keys = [
            'id' => true,
            'slug' => true,
            'name' => true,
            'type' => true,
            'multiple' => true,
            'required' => true,
            'max_items' => true,
            'description' => true,
            'placeholder' => true,
            // Internal runtime taxonomy catalog, not a prompt instruction.
            'taxonomy_allowed_values' => true,
        ];

        foreach ($field as $key => $value) {
            if (!is_string($key) || isset($core_field_keys[$key])) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            if (is_array($value) && $value === []) {
                continue;
            }

            $field_hints[$key] = $value;
        }

        if (isset($field['taxonomy_allowed_values']) && is_array($field['taxonomy_allowed_values'])) {
            $labels = [];
            foreach ($field['taxonomy_allowed_values'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $label = trim((string) ($entry['label'] ?? ''));
                if ($label !== '') {
                    $labels[] = $label;
                }
            }

            if ($labels !== []) {
                $field_hints['allowed_values'] = array_values(array_unique($labels));
            }
        }

        return $field_hints;
    }

    private function normalize_max_items(mixed $cardinality): ?int {
        if (is_int($cardinality) && $cardinality > 0) {
            return $cardinality;
        }

        if (is_string($cardinality) && trim($cardinality) !== '' && is_numeric($cardinality)) {
            $parsed = (int) $cardinality;
            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function get_metadata_type_options(\Tainacan\Entities\Metadatum $metadatum): array {
        if (!method_exists($metadatum, 'get_metadata_type_options')) {
            return [];
        }

        $options = $metadatum->get_metadata_type_options();
        return is_array($options) ? $options : [];
    }

    /**
     * @param array{
     *     id: int,
     *     slug: string,
     *     name: string,
     *     type: string,
     *     multiple: bool,
     *     required: bool,
     *     max_items: int|null,
     *     description: string,
     *     placeholder: string,
     *     min: int|float|string|null,
     *     max: int|float|string|null,
     *     step: int|float|string|null,
     *     max_length: int|null,
     *     mask: string,
     *     taxonomy_id: int|null,
     *     allow_new_terms: bool|null,
     *     allowed_values_truncated: bool,
     *     target_collection: int|null,
     *     relationship_search_field: int|null,
     *     allowed_values: string[]
     * } $field
     * @param array<string, mixed> $metadata_type_options
     * @return array<string, mixed>
     */
    private function build_metadata_type_hints_from_options(array $field, array $metadata_type_options): array {
        $type = $this->format_metadata_type((string) $field['type']);
        $field_hints = [];

        if (in_array($type, ['numeric', 'date'], true)) {
            $field_hints['min'] = $this->normalize_scalar_constraint($metadata_type_options['min'] ?? null);
            $field_hints['max'] = $this->normalize_scalar_constraint($metadata_type_options['max'] ?? null);
        }

        if ($type === 'date') {
            $field_hints['expected_format_hint'] = 'YYYY-MM-DD';
        }

        if ($type === 'numeric') {
            $field_hints['step'] = $this->normalize_scalar_constraint($metadata_type_options['step'] ?? null);
        }

        if (in_array($type, ['text', 'textarea', 'core_title', 'core_description'], true)) {
            $field_hints['max_length'] = $this->normalize_positive_int($metadata_type_options['maxlength'] ?? null);
        }

        if (in_array($type, ['text', 'core_title'], true)) {
            $field_hints['mask'] = is_string($metadata_type_options['mask'] ?? null) ? trim((string) $metadata_type_options['mask']) : '';
        }

        if ($type === 'taxonomy') {
            $taxonomy_id = $this->normalize_positive_int($metadata_type_options['taxonomy_id'] ?? null);
            if ($taxonomy_id !== null) {
                $field_hints['taxonomy_id'] = $taxonomy_id;

                $taxonomy_allowed_values = $this->get_ranked_taxonomy_allowed_values($taxonomy_id);
                if ($taxonomy_allowed_values['taxonomy_allowed_values'] !== []) {
                    $field_hints['taxonomy_allowed_values'] = $taxonomy_allowed_values['taxonomy_allowed_values'];
                }
                if ($taxonomy_allowed_values['allowed_values_truncated']) {
                    $field_hints['allowed_values_truncated'] = true;
                }
            }

            $field_hints['allow_new_terms'] = $this->normalize_yes_no_to_bool_or_null($metadata_type_options['allow_new_terms'] ?? null);
        }

        if ($type === 'geocoordinate') {
            $field_hints['expected_format_hint'] = '[lat,lng] in decimal degrees (e.g., [-14.4086569,-51.31668])';
        }

        if ($type === 'relationship') {
            $field_hints['target_collection'] = $this->normalize_positive_int($metadata_type_options['collection_id'] ?? null);
            $field_hints['relationship_search_field'] = $this->normalize_positive_int($metadata_type_options['search'] ?? null);
        }

        if ($type === 'selectbox') {
            $field_hints['allowed_values'] = $this->parse_selectbox_allowed_values($metadata_type_options['options'] ?? '');
        }

        return $field_hints;
    }

    /**
     * @return array{
     *     taxonomy_allowed_values: array<int, array{value: int, label: string}>,
     *     allowed_values_truncated: bool,
     * }
     */
    private function get_ranked_taxonomy_allowed_values(int $taxonomy_id): array {
        $limit = (int) apply_filters(
            'tainacan_ai_taxonomy_allowed_values_limit',
            self::TAXONOMY_ALLOWED_VALUES_LIMIT,
            $taxonomy_id
        );
        $limit = max(1, $limit);

        $terms = $this->fetch_ranked_taxonomy_terms($taxonomy_id, $limit + 1);
        $term_names = array_values(array_unique(array_filter(
            array_map(static fn (array $term): string => trim((string) ($term['label'] ?? '')), $terms),
            static fn (string $name): bool => $name !== ''
        )));

        $is_truncated = count($term_names) > $limit;
        if ($is_truncated) {
            $term_names = array_slice($term_names, 0, $limit);
        }

        /** @var string[] $term_names */
        $term_names = (array) apply_filters(
            'tainacan_ai_taxonomy_allowed_values',
            $term_names,
            $taxonomy_id,
            $is_truncated
        );

        $allowed_labels = array_values(array_unique(array_filter(
            array_map(static fn ($name): string => is_string($name) ? trim($name) : '', $term_names),
            static fn (string $name): bool => $name !== ''
        )));

        $taxonomy_allowed_values = [];
        foreach ($terms as $term) {
            $label = trim((string) ($term['label'] ?? ''));
            $id = isset($term['value']) ? (int) $term['value'] : 0;
            if ($label === '' || $id <= 0 || !in_array($label, $allowed_labels, true)) {
                continue;
            }
            $taxonomy_allowed_values[] = [
                'value' => $id,
                'label' => $label,
            ];
        }

        $taxonomy_allowed_values = array_values(array_unique($taxonomy_allowed_values, SORT_REGULAR));

        return [
            'taxonomy_allowed_values' => $taxonomy_allowed_values,
            'allowed_values_truncated' => $is_truncated,
        ];
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function fetch_ranked_taxonomy_terms(int $taxonomy_id, int $limit): array {
        if ($taxonomy_id <= 0 || $limit <= 0) {
            return [];
        }

        $terms_repository = null;
        if (function_exists('tainacan_terms')) {
            $terms_repository = tainacan_terms();
        } elseif (class_exists('\Tainacan\Repositories\Terms')) {
            $terms_repository = \Tainacan\Repositories\Terms::get_instance();
        }

        if (!$terms_repository || !method_exists($terms_repository, 'fetch')) {
            return [];
        }

        $terms = $terms_repository->fetch(
            [
                'hide_empty' => false,
                'orderby' => 'count',
                'order' => 'DESC',
                'number' => $limit,
            ],
            $taxonomy_id
        );

        if (!is_array($terms)) {
            return [];
        }

        $terms_list = [];
        foreach ($terms as $term) {
            if ($term instanceof \Tainacan\Entities\Term && method_exists($term, 'get_name') && method_exists($term, 'get_id')) {
                $id = (int) $term->get_id();
                $name = trim((string) $term->get_name());
                if ($name !== '' && $id > 0) {
                    $terms_list[] = [
                        'value' => $id,
                        'label' => $name,
                    ];
                }
                continue;
            }

            if ($term instanceof \WP_Term) {
                $id = (int) $term->term_id;
                $name = trim((string) $term->name);
                if ($name !== '' && $id > 0) {
                    $terms_list[] = [
                        'value' => $id,
                        'label' => $name,
                    ];
                }
            }
        }

        return $terms_list;
    }

    private function normalize_scalar_constraint(mixed $value): int|float|string|null {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if (is_numeric($normalized)) {
            return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
        }

        return $normalized;
    }

    private function normalize_positive_int(mixed $value): ?int {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function normalize_yes_no_to_bool_or_null(mixed $value): ?bool {
        if ($value === 'yes') {
            return true;
        }

        if ($value === 'no') {
            return false;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function parse_selectbox_allowed_values(mixed $options): array {
        if (!is_string($options) || trim($options) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $options);
        if (!is_array($lines)) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }

            $value = trim($line);
            if ($value === '') {
                continue;
            }

            $values[$value] = $value;
        }

        return array_values($values);
    }

    private function format_prompt_scalar(int|float|string $value): string {
        if (is_string($value)) {
            return trim($value);
        }

        return (string) $value;
    }

    private function format_prompt_value(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $serialized = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $serialized[] = trim((string) $item);
                }
            }

            return implode(' | ', array_filter($serialized, static fn (string $item): bool => $item !== ''));
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return $this->format_prompt_scalar($value);
        }

        return '';
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
            'Each slug maps to {"value": scalar|array|null, "evidence": string|array|null, "label"?: scalar|array|null}.' . "\n" .
            'Single-value fields: scalar value and string|null evidence.' . "\n" .
            'Multivalued fields: value and evidence must be parallel arrays with equal length.' . "\n" .
            'Use label only when a human-readable display differs from value (for example taxonomy names for term IDs).' . "\n" .
            'Missing support: set value to null, evidence null or omitted.' . "\n" .
            'Output must be ONLY JSON (no markdown, no comments, no prose).';
    }

}

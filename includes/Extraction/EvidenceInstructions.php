<?php
namespace Tainacan\AI\Extraction;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File-type-specific evidence guidance.
 *
 * Appended to every analysis prompt at runtime so templates and custom prompts
 * do not need per-field evidence definitions.
 *
 * Strings in this class are intentionally English-only and not passed through
 * gettext. They are model instructions, not admin UI copy.
 */
class EvidenceInstructions {

    public const MODE_IMAGE = 'image';
    public const MODE_TEXT = 'text';
    public const MODE_PDF_TEXT = 'pdf_text';
    public const MODE_PDF_VISUAL = 'pdf_visual';

    /**
     * Normalize AI metadata so each field is { value, evidence, label? } with parallel arrays for multivalued data.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function normalize_metadata(array $metadata): array {
        $normalized = [];

        foreach ($metadata as $key => $data) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = self::normalize_field($data);
        }

        return $normalized;
    }

    /**
     * @return array{value: mixed, evidence: mixed|null, label?: mixed}
     */
    private static function normalize_field(mixed $data): array {
        if (is_array($data) && self::is_list_of_value_evidence_objects($data)) {
            return self::coalesce_value_evidence_objects($data);
        }

        if (!is_array($data) || !array_key_exists('value', $data)) {
            $normalized = [
                'value' => $data,
                'evidence' => null,
            ];
            return $normalized;
        }

        $value = $data['value'] ?? null;
        $evidence = $data['evidence'] ?? null;
        $label = $data['label'] ?? null;

        if (is_array($value) && self::is_list_of_value_evidence_objects($value)) {
            $coalesced = self::coalesce_value_evidence_objects($value);

            if ($evidence === null || $evidence === '' || (is_array($evidence) && $evidence === [])) {
                $evidence = $coalesced['evidence'];
            }

            if ($label === null || $label === '' || (is_array($label) && $label === [])) {
                $label = $coalesced['label'];
            }

            $value = $coalesced['value'];
        }

        $normalized = [
            'value' => $value,
            'evidence' => $evidence,
        ];

        if ($label !== null && $label !== '' && (!is_array($label) || $label !== [])) {
            $normalized['label'] = $label;
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $items
     */
    private static function is_list_of_value_evidence_objects(array $items): bool {
        if ($items === []) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists('value', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{value: array<int, mixed>, evidence: array<int, string>, label: array<int, string>}
     */
    private static function coalesce_value_evidence_objects(array $items): array {
        $values = [];
        $evidences = [];
        $labels = [];

        foreach ($items as $item) {
            $values[] = $item['value'] ?? null;
            $evidences[] = isset($item['evidence']) ? (string) $item['evidence'] : '';
            if (array_key_exists('label', $item) && $item['label'] !== null) {
                $labels[] = (string) $item['label'];
            } else {
                $labels[] = '';
            }
        }

        return [
            'value' => $values,
            'evidence' => $evidences,
            'label' => $labels,
        ];
    }

    /**
     * @param string $analysis_mode One of EvidenceInstructions::MODE_* constants.
     * @param string $prompt        Prompt text assembled before the evidence section (for filter context).
     */
    public static function get_mode_guidance(string $analysis_mode, string $prompt = ''): string {
        switch ($analysis_mode) {
            case self::MODE_IMAGE:
                $block = 'EVIDENCE RULES: IMAGE' . "\n" .
                    '- Cite visible text, labels, inscriptions, or marks when available.' . "\n" .
                    '- Mention region/object anchors (e.g. ' . __('lower-right signature, frame caption', 'tainacan-ai') . ').' . "\n" .
                    '- Include concise cues on style, material, technique, or condition when relevant.';
                break;

            case self::MODE_PDF_VISUAL:
                $block = 'EVIDENCE RULES: PDF_VISUAL' . "\n" .
                    '- Cite page number and visual region (' . __('header, footer, caption, table, cover', 'tainacan-ai') . ').' . "\n" .
                    '- Quote short visible snippets when legible.';
                break;

            case self::MODE_PDF_TEXT:
                $block = 'EVIDENCE RULES: PDF_TEXT' . "\n" .
                    '- Cite page number whenever possible.' . "\n" .
                    '- Cite PDF structure anchors when available (' . __('header, footer, caption, table, cover, section heading', 'tainacan-ai') . ').' . "\n" .
                    '- Include a short quote or concise paraphrase supporting the value.';
                break;

            case self::MODE_TEXT:
            default:
                $block = 'EVIDENCE RULES: TEXT' . "\n" .
                    '- Cite section/heading and page or approximate position when identifiable.' . "\n" .
                    '- Include short quote or concise paraphrase supporting the value.';
                break;
        }

        /**
         * Filters the evidence instruction block appended to analysis prompts.
         *
         * @param string $block         Full evidence section (mode guidance).
         * @param string $analysis_mode One of EvidenceInstructions::MODE_* constants.
         * @param string $prompt        Prompt text assembled before the evidence section.
         */
        return (string) apply_filters('tainacan_ai_evidence_instructions', $block, $analysis_mode, $prompt);
    }
}

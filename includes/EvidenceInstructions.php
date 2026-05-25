<?php
namespace Tainacan\AI;

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
     * Append analysis-mode-specific evidence guidance to a prompt.
     */
    public static function append_to_prompt(string $prompt, string $analysis_mode): string {
        $prompt = trim($prompt);
        $block = self::get_instruction_block($analysis_mode);

        /**
         * Filters the evidence instruction block appended to analysis prompts.
         *
         * @param string $block         Full evidence section (mode guidance).
         * @param string $analysis_mode One of EvidenceInstructions::MODE_* constants.
         * @param string $prompt        Base prompt before appending evidence instructions.
         */
        $block = (string) apply_filters('tainacan_ai_evidence_instructions', $block, $analysis_mode, $prompt);

        if ($block === '') {
            return $prompt;
        }

        return $prompt . "\n\n" . $block;
    }

    /**
     * @return string[]
     */
    public static function get_modes(): array {
        return [
            self::MODE_IMAGE,
            self::MODE_TEXT,
            self::MODE_PDF_TEXT,
            self::MODE_PDF_VISUAL,
        ];
    }

    private static function get_instruction_block(string $analysis_mode): string {
        return self::get_mode_guidance($analysis_mode);
    }

    /**
     * Normalize AI metadata so each field is { value, evidence } with parallel arrays for multivalued data.
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
     * @return array{value: mixed, evidence: mixed|null}
     */
    private static function normalize_field(mixed $data): array {
        if (is_array($data) && self::is_list_of_value_evidence_objects($data)) {
            return self::coalesce_value_evidence_objects($data);
        }

        if (!is_array($data) || !array_key_exists('value', $data)) {
            return [
                'value' => $data,
                'evidence' => null,
            ];
        }

        $value = $data['value'] ?? null;
        $evidence = $data['evidence'] ?? null;

        if (is_array($value) && self::is_list_of_value_evidence_objects($value)) {
            $coalesced = self::coalesce_value_evidence_objects($value);

            if ($evidence === null || $evidence === '' || (is_array($evidence) && $evidence === [])) {
                $evidence = $coalesced['evidence'];
            }

            $value = $coalesced['value'];
        }

        return [
            'value' => $value,
            'evidence' => $evidence,
        ];
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
     * @return array{value: array<int, mixed>, evidence: array<int, string>}
     */
    private static function coalesce_value_evidence_objects(array $items): array {
        $values = [];
        $evidences = [];

        foreach ($items as $item) {
            $values[] = $item['value'] ?? null;
            $evidences[] = isset($item['evidence']) ? (string) $item['evidence'] : '';
        }

        return [
            'value' => $values,
            'evidence' => $evidences,
        ];
    }

    public static function get_mode_guidance(string $analysis_mode): string {
        switch ($analysis_mode) {
            case self::MODE_IMAGE:
                return 'EVIDENCE RULES: IMAGE' . "\n" .
                    '- Cite visible text, labels, inscriptions, or marks when available.' . "\n" .
                    '- Mention region/object anchors (e.g. lower-right signature, frame caption).' . "\n" .
                    '- Include concise cues on style, material, technique, or condition when relevant.';

            case self::MODE_PDF_VISUAL:
                return 'EVIDENCE RULES: PDF_VISUAL' . "\n" .
                    '- Cite page number and visual region (header, footer, caption, table, cover).' . "\n" .
                    '- Quote short visible snippets when legible.';

            case self::MODE_PDF_TEXT:
                return 'EVIDENCE RULES: PDF_TEXT' . "\n" .
                    '- Cite page number whenever possible (e.g. "page 2").' . "\n" .
                    '- Cite PDF structure anchors when available (header, footer, caption, table, cover, section heading).' . "\n" .
                    '- Include a short quote or concise paraphrase supporting the value.';

            case self::MODE_TEXT:
            default:
                return 'EVIDENCE RULES: TEXT' . "\n" .
                    '- Cite section/heading and page or approximate position when identifiable.' . "\n" .
                    '- Include short quote or concise paraphrase supporting the value.';
        }
    }
}

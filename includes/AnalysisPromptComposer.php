<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the final analysis prompt in deterministic sections.
 *
 * Strings in this class are intentionally English-only and not passed through
 * gettext. They are model instructions.
 *
 * Terminology:
 * - analysis_mode: file-aware mode used for task wording and evidence rules
 *   (image, text, pdf_text, pdf_visual)
 * - field_guidance: per-field hints coming from metadatum description/placeholder
 * - evidence: source justification returned by the model for each extracted value
 */
class AnalysisPromptComposer {

    /**
     * @return array{
     *     user: string,
     *     task: string,
     *     rules: string,
     *     fields: string,
 *     schema: string,
 *     evidence: string,
     *     output: string
     * }
     */
    public static function get_sections(
        int $collection_id,
        string $user_preamble,
        string $analysis_mode
    ): array {
        $extraction = ExtractionMetadata::get_instance();
        $fields = $collection_id > 0 ? $extraction->get_fields_for_collection($collection_id) : [];

        $sections = [
            'user' => $user_preamble,
            'task' => self::build_task_section($analysis_mode),
            'rules' => self::build_global_rules_section(),
            'fields' => $extraction->build_fields_section($fields),
            'schema' => $extraction->build_field_format_section(),
            'evidence' => EvidenceInstructions::get_mode_guidance($analysis_mode),
            'output' => $extraction->build_output_keys_section(array_keys($fields)),
        ];

        /** @var array<string, string> $sections */
        $sections = (array) apply_filters(
            'tainacan_ai_analysis_prompt_sections',
            $sections,
            $analysis_mode,
            $collection_id
        );

        $normalized = [];
        foreach (['user', 'task', 'rules', 'fields', 'schema', 'evidence', 'output'] as $key) {
            $normalized[$key] = isset($sections[$key]) ? trim((string) $sections[$key]) : '';
        }

        return $normalized;
    }

    public static function compose(
        int $collection_id,
        string $user_preamble,
        string $analysis_mode
    ): string {
        $sections = self::get_sections($collection_id, $user_preamble, $analysis_mode);
        $parts = array_values(array_filter($sections, static fn (string $value): bool => $value !== ''));
        $prompt = trim(implode("\n\n", $parts));

        return (string) apply_filters(
            'tainacan_ai_analysis_prompt',
            $prompt,
            $sections,
            $analysis_mode,
            $collection_id
        );
    }

    private static function build_task_section(string $analysis_mode): string {
        $label = trim($analysis_mode) !== '' ? str_replace('_', ' ', trim($analysis_mode)) : 'document';

        return 'TASK' . "\n" .
            sprintf('Extract structured metadata from the attached %s. Return ONLY valid JSON.', $label);
    }

    private static function build_global_rules_section(): string {
        return 'GLOBAL RULES' . "\n" .
            '- For strict factual fields, never fabricate dates, personal names, authors, locations, identifiers, or coordinates.' . "\n" .
            '- For taxonomy and relationship fields, you may suggest grounded vocabulary candidates to help discovery.' . "\n" .
            '- Suggested relationships or classifications must cite support in evidence. Do not invent unsupported links.' . "\n" .
            '- Use value: null when there is no support in the document.' . "\n" .
            '- If field_guidance is missing, infer field intent from label and type.' . "\n" .
            '- Write evidence as a short, objective source note (quote, page, region, heading, or label).' . "\n" .
            '- Process internally: analyze, match fields, validate, output JSON only.';
    }
}

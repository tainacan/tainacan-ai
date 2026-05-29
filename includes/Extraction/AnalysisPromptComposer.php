<?php
namespace Tainacan\AI\Extraction;

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
     *     fields: array<string, array<string, mixed>>,
     *     expected_slugs: string[],
     *     sections: array{user: string, task: string, rules: string, fields: string, schema: string, evidence: string, output: string},
     *     prompt: string
     * }
     */
    public static function get_context(
        int $collection_id,
        string $user_preamble,
        string $analysis_mode
    ): array {
        $extraction = ExtractionMetadata::get_instance();
        $fields = $collection_id > 0 ? $extraction->get_fields_for_collection($collection_id) : [];
        $expected_slugs = array_keys($fields);

        $sections = [
            'user' => $user_preamble,
            'task' => self::build_task_section($analysis_mode),
            'rules' => self::build_global_rules_section(),
            'fields' => $extraction->build_fields_section($fields),
            'schema' => $extraction->build_field_format_section(),
            'language' => self::build_output_language_section(),
        ];

        $prompt_before_evidence = trim(implode("\n\n", array_values(array_filter(
            [
                $sections['user'],
                $sections['task'],
                $sections['rules'],
                $sections['fields'],
                $sections['schema'],
                $sections['language'],
            ],
            static fn (string $value): bool => $value !== ''
        ))));

        $sections['evidence'] = EvidenceInstructions::get_mode_guidance($analysis_mode, $prompt_before_evidence);
        $sections['output'] = $extraction->build_output_keys_section($expected_slugs);

        /** @var array<string, string> $sections */
        $sections = (array) apply_filters(
            'tainacan_ai_analysis_prompt_sections',
            $sections,
            $analysis_mode,
            $collection_id
        );

        $normalized = [];
        foreach (['user', 'task', 'rules', 'fields', 'schema', 'language', 'evidence', 'output'] as $key) {
            $normalized[$key] = isset($sections[$key]) ? trim((string) $sections[$key]) : '';
        }

        // Keep a final defensive cleanup because section filters may return empty blocks.
        $parts = array_values(array_filter($normalized, static fn (string $value): bool => $value !== ''));
        $prompt = trim(implode("\n\n", $parts));
        $prompt = (string) apply_filters(
            'tainacan_ai_analysis_prompt',
            $prompt,
            $normalized,
            $analysis_mode,
            $collection_id
        );

        return [
            'fields' => $fields,
            'expected_slugs' => $expected_slugs,
            'sections' => $normalized,
            'prompt' => $prompt,
        ];
    }

    private static function build_task_section(string $analysis_mode): string {
        $label = trim($analysis_mode) !== '' ? str_replace('_', ' ', trim($analysis_mode)) : 'document';

        return 'TASK' . "\n" .
            sprintf('Extract structured metadata from the attached %s. Return ONLY valid JSON.', $label);
    }

    private static function build_output_language_section(): string {
        $site_locale = function_exists('get_locale') ? (string) get_locale() : 'en_US';
        /**
         * WordPress locale string shown in GLOBAL RULES as the default for ambiguous free-text language.
         *
         * @param string $site_locale   Locale from get_locale().
         */
        $site_locale = (string) apply_filters('tainacan_ai_analysis_site_locale_for_prompt', $site_locale);

        return 'OUTPUT LANGUAGE:' . "\n" .
            'Output language for free-text field values (unless explicitly asked in the first section of this prompt) should follow these rules:' . "\n" .
            '- For fields with allowed_values (taxonomy, selectbox, etc.), when you pick from that list use the exact string from allowed_values (language follows the list).' . "\n" .
            '- Otherwise prefer the primary language of the document when it is clear from quoted or cited content.' . "\n" .
            '- When the document language is unclear (e.g. image-only or mixed/ambiguous text), default free-text values to the site locale: ' . $site_locale . '.' . "\n";
    }

    private static function build_global_rules_section(): string {
        return 'GLOBAL RULES' . "\n" .
            '- For strict factual fields, never fabricate dates, personal names, authors, locations, identifiers, or coordinates.' . "\n" .
            '- For taxonomy and relationship fields, suggest values only when evidence in the document supports them.' . "\n" .
            '- For any field with allowed_values, return only exact strings from that list or null.' . "\n" .
            '- Never use metadatum placeholder text, form control labels, or instructional sentences as field values unless that exact string appears in allowed_values.' . "\n" .
            '- For taxonomy fields only: when allow_new_terms=true and no allowed_values term matches, you may suggest one new term label; when allow_new_terms=false, return null.' . "\n" .
            '- Never mix allowed_values matches and a new taxonomy term in the same field response.' . "\n" .
            '- Never invent relationship values; every non-null suggestion must include direct evidence support.' . "\n" .
            '- Use value: null when there is no support in the document.' . "\n" .
            '- If field_guidance is missing, infer field intent from label and type.' . "\n" .
            '- Write evidence as a short, objective source note (quote, page, region, heading, or label).' . "\n" .
            '- Process internally: analyze, match fields, validate, output JSON only.' . "\n" .
            '- Never summarize, explain, or comment on the document; return the JSON object only.' . "\n";
    }
}

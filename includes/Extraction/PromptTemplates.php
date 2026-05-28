<?php
namespace Tainacan\AI\Extraction;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suggested prompt intros for the admin UI and default site option.
 *
 * Template labels and descriptions use gettext (English defaults). Template body
 * text is English-only and not translated: it is sent to the model as-is. Per-collection
 * field blocks, response keys, and evidence rules are appended at runtime by the plugin.
 */
class PromptTemplates {

    public static function get_default_preamble(): string {
        $default_preamble = self::get_image_template();

        /**
         * Filters the default prompt preamble used by Tainacan AI.
         *
         * @param string $default_preamble The default preamble text.
         */
        return (string) apply_filters('tainacan_ai_default_preamble', $default_preamble);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function get_templates(): array {
        $templates = [
            'image' => [
                'label' => __('Image analysis', 'tainacan-ai'),
                'description' => __('Extraction-focused intro for image metadata. Keep it about priorities and domain context, not output formatting.', 'tainacan-ai'),
                'content' => self::get_image_template(),
            ],
            'document' => [
                'label' => __('Document analysis', 'tainacan-ai'),
                'description' => __('Extraction-focused intro for textual documents. Keep it about priorities and domain context, not output formatting.', 'tainacan-ai'),
                'content' => self::get_document_template(),
            ],
        ];

        /**
         * Filters prompt template suggestions shown in the admin.
         *
         * @param array<string, array<string, string>> $templates Template map keyed by template slug.
         */
        $templates = apply_filters('tainacan_ai_prompt_templates', $templates);
        if (!is_array($templates)) {
            return [];
        }

        $normalized = [];
        foreach ($templates as $key => $template) {
            if (!is_string($key) || !is_array($template)) {
                continue;
            }

            $content = isset($template['content']) ? (string) $template['content'] : '';
            if ($content === '') {
                continue;
            }

            $normalized[$key] = [
                'label' => isset($template['label']) ? (string) $template['label'] : $key,
                'description' => isset($template['description']) ? (string) $template['description'] : '',
                'content' => $content,
            ];
        }

        return $normalized;
    }

    private static function get_image_template(): string {
        return __('You are a precise metadata extraction assistant for museum and archival collections. Extract only the requested fields and avoid broad descriptions outside field needs.', 'tainacan-ai') . "\n\n";
    }

    private static function get_document_template(): string {
        return __('You are a precise metadata extraction assistant for documentary and bibliographic collections. Extract only the requested fields and avoid summaries beyond field needs.', 'tainacan-ai') . "\n\n";
    }
}

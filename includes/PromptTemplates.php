<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suggested prompt intros for the admin UI and default site option.
 *
 * Templates describe role and analysis goals only. Per-collection field lists,
 * response keys, and JSON/evidence format are appended at runtime by the plugin.
 */
class PromptTemplates {

    public static function get_default_prompt(): string {
        $default_prompt = self::get_image_template();

        /**
         * Filters the default analysis prompt used by Tainacan AI.
         *
         * @param string $default_prompt The default prompt text.
         */
        return (string) apply_filters('tainacan_ai_default_prompt', $default_prompt);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function get_templates(): array {
        $templates = [
            'image' => [
                'label' => __('Image analysis', 'tainacan-ai'),
                'description' => __('Intro for visual and museological analysis. Fields and response format are appended by the plugin per collection.', 'tainacan-ai'),
                'content' => self::get_image_template(),
            ],
            'document' => [
                'label' => __('Document analysis', 'tainacan-ai'),
                'description' => __('Intro for bibliographic and textual documents. Fields and response format are appended by the plugin per collection.', 'tainacan-ai'),
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
        return 'Você é um especialista em catalogação museológica e arquivística. Analise esta imagem e descreva o que for relevante para descrição e preservação do acervo.' . "\n\n" .
            '## Objetivos da análise' . "\n" .
            '1. Analise cuidadosamente todos os elementos visuais' . "\n" .
            '2. Identifique técnicas artísticas, materiais e estilos' . "\n" .
            '3. Estime períodos históricos quando possível' . "\n" .
            '4. Descreva o estado de conservação visível';
    }

    private static function get_document_template(): string {
        return 'Você é um especialista em análise documental e bibliográfica. Analise este documento e identifique informações relevantes para catalogação e indexação.' . "\n\n" .
            '## Objetivos da análise' . "\n" .
            '1. Identifique o tipo de documento (artigo, relatório, tese, etc.)' . "\n" .
            '2. Extraia informações bibliográficas completas quando presentes' . "\n" .
            '3. Identifique temas e áreas de conhecimento' . "\n" .
            '4. Resuma o conteúdo principal';
    }
}

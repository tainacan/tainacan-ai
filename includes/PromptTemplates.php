<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

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
                'description' => __('Default template for visual and museological analysis of image files.', 'tainacan-ai'),
                'content' => self::get_image_template(),
            ],
            'document' => [
                'label' => __('Document analysis', 'tainacan-ai'),
                'description' => __('Suggested template for bibliographic and textual documents.', 'tainacan-ai'),
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
        return 'Você é um especialista em catalogação museológica e arquivística. Analise esta imagem e extraia metadados detalhados.' . "\n\n" .
'## Instruções:' . "\n" .
'1. Analise cuidadosamente todos os elementos visuais' . "\n" .
'2. Identifique técnicas artísticas, materiais e estilos' . "\n" .
'3. Estime períodos históricos quando possível' . "\n" .
'4. Descreva o estado de conservação visível' . "\n\n" .
'## Retorne um JSON com os seguintes campos:' . "\n" .
'{' . "\n" .
'    "titulo": "Título descritivo da obra/objeto",' . "\n" .
'    "autor": "Nome do autor/criador (ou \'Desconhecido\')",' . "\n" .
'    "data_criacao": "Data ou período estimado",' . "\n" .
'    "tecnica": "Técnica(s) utilizada(s)",' . "\n" .
'    "materiais": ["lista", "de", "materiais"],' . "\n" .
'    "dimensoes_estimadas": "Dimensões aproximadas",' . "\n" .
'    "estado_conservacao": "Bom/Regular/Ruim - descrição",' . "\n" .
'    "descricao": "Descrição visual detalhada",' . "\n" .
'    "estilo_artistico": "Estilo ou movimento artístico",' . "\n" .
'    "palavras_chave": ["palavras", "chave", "relevantes"],' . "\n" .
'    "observacoes": "Outras observações relevantes"' . "\n" .
'}' . "\n\n" .
'Responda APENAS com o JSON, sem texto adicional.';
    }

    private static function get_document_template(): string {
        return 'Você é um especialista em análise documental e bibliográfica. Analise este documento e extraia metadados estruturados.' . "\n\n" .
'## Instruções:' . "\n" .
'1. Identifique o tipo de documento (artigo, relatório, tese, etc.)' . "\n" .
'2. Extraia informações bibliográficas completas' . "\n" .
'3. Identifique temas e áreas de conhecimento' . "\n" .
'4. Resuma o conteúdo principal' . "\n\n" .
'## Retorne um JSON com os seguintes campos:' . "\n" .
'{' . "\n" .
'    "titulo": "Título do documento",' . "\n" .
'    "autor": ["Nome dos autores"],' . "\n" .
'    "tipo_documento": "Artigo/Relatório/Tese/Livro/etc",' . "\n" .
'    "ano_publicacao": "Ano",' . "\n" .
'    "instituicao": "Instituição relacionada",' . "\n" .
'    "resumo": "Resumo do conteúdo (máx. 500 caracteres)",' . "\n" .
'    "palavras_chave": ["palavras", "chave"],' . "\n" .
'    "area_conhecimento": "Área principal",' . "\n" .
'    "idioma": "Idioma do documento",' . "\n" .
'    "referencias_principais": ["Referências importantes citadas"],' . "\n" .
'    "metodologia": "Metodologia utilizada (se aplicável)",' . "\n" .
'    "observacoes": "Outras observações"' . "\n" .
'}' . "\n\n" .
'Responda APENAS com o JSON, sem texto adicional.';
    }
}

<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

class PromptTemplates {

    public static function get_default_prompt(): string {
        return self::get_image_template();
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function get_templates(): array {
        return [
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
    }

    private static function get_image_template(): string {
        return 'Você é um especialista em catalogação museológica e arquivística. Analise esta imagem e extraia metadados detalhados.' . "\n\n" .
'## Instruções:' . "\n" .
'1. Analise cuidadosamente todos os elementos visuais' . "\n" .
'2. Identifique técnicas artísticas, materiais e estilos' . "\n" .
'3. Estime períodos históricos quando possível' . "\n" .
'4. Descreva o estado de conservação visível' . "\n" .
'5. Para CADA campo, inclua a evidência de onde a informação foi extraída' . "\n\n" .
'## Retorne um JSON com os seguintes campos (cada campo deve ter "valor" e "evidencia"):' . "\n" .
'{' . "\n" .
'    "titulo": {' . "\n" .
'        "valor": "Título descritivo da obra/objeto",' . "\n" .
'        "evidencia": "Descrição de qual elemento visual ou texto levou a esta conclusão"' . "\n" .
'    },' . "\n" .
'    "autor": {' . "\n" .
'        "valor": "Nome do autor/criador (ou \'Desconhecido\')",' . "\n" .
'        "evidencia": "Assinatura visível, estilo característico, ou \'Não identificado na imagem\'"' . "\n" .
'    },' . "\n" .
'    "data_criacao": {' . "\n" .
'        "valor": "Data ou período estimado",' . "\n" .
'        "evidencia": "Elementos que indicam a época (estilo, materiais, técnica, inscrições)"' . "\n" .
'    },' . "\n" .
'    "tecnica": {' . "\n" .
'        "valor": "Técnica(s) utilizada(s)",' . "\n" .
'        "evidencia": "Características visuais que identificam a técnica"' . "\n" .
'    },' . "\n" .
'    "materiais": {' . "\n" .
'        "valor": ["lista", "de", "materiais"],' . "\n" .
'        "evidencia": "Texturas, cores e características que indicam os materiais"' . "\n" .
'    },' . "\n" .
'    "dimensoes_estimadas": {' . "\n" .
'        "valor": "Dimensões aproximadas",' . "\n" .
'        "evidencia": "Elementos de referência usados para estimar (objetos conhecidos, proporções)"' . "\n" .
'    },' . "\n" .
'    "estado_conservacao": {' . "\n" .
'        "valor": "Bom/Regular/Ruim - descrição",' . "\n" .
'        "evidencia": "Sinais visíveis de desgaste, danos ou boa preservação"' . "\n" .
'    },' . "\n" .
'    "descricao": {' . "\n" .
'        "valor": "Descrição visual detalhada",' . "\n" .
'        "evidencia": "Elementos principais observados na imagem"' . "\n" .
'    },' . "\n" .
'    "estilo_artistico": {' . "\n" .
'        "valor": "Estilo ou movimento artístico",' . "\n" .
'        "evidencia": "Características formais que indicam o estilo"' . "\n" .
'    },' . "\n" .
'    "palavras_chave": {' . "\n" .
'        "valor": ["palavras", "chave", "relevantes"],' . "\n" .
'        "evidencia": "Temas e elementos principais identificados"' . "\n" .
'    },' . "\n" .
'    "observacoes": {' . "\n" .
'        "valor": "Outras observações relevantes",' . "\n" .
'        "evidencia": "Detalhes adicionais notados"' . "\n" .
'    }' . "\n" .
'}' . "\n\n" .
'Responda APENAS com o JSON, sem texto adicional.';
    }

    private static function get_document_template(): string {
        return 'Você é um especialista em análise documental e bibliográfica. Analise este documento e extraia metadados estruturados.' . "\n\n" .
'## Instruções:' . "\n" .
'1. Identifique o tipo de documento (artigo, relatório, tese, etc.)' . "\n" .
'2. Extraia informações bibliográficas completas' . "\n" .
'3. Identifique temas e áreas de conhecimento' . "\n" .
'4. Resuma o conteúdo principal' . "\n" .
'5. Para CADA campo, inclua a evidência de onde a informação foi extraída (página, seção, trecho do texto)' . "\n\n" .
'## Retorne um JSON com os seguintes campos (cada campo deve ter "valor" e "evidencia"):' . "\n" .
'{' . "\n" .
'    "titulo": {' . "\n" .
'        "valor": "Título do documento",' . "\n" .
'        "evidencia": "Local onde o título foi encontrado (capa, cabeçalho, página X)"' . "\n" .
'    },' . "\n" .
'    "autor": {' . "\n" .
'        "valor": ["Nome dos autores"],' . "\n" .
'        "evidencia": "Local onde os autores foram identificados (capa, página X, seção de autoria)"' . "\n" .
'    },' . "\n" .
'    "tipo_documento": {' . "\n" .
'        "valor": "Artigo/Relatório/Tese/Livro/etc",' . "\n" .
'        "evidencia": "Elementos que indicam o tipo (estrutura, formatação, declarações explícitas)"' . "\n" .
'    },' . "\n" .
'    "ano_publicacao": {' . "\n" .
'        "valor": "Ano",' . "\n" .
'        "evidencia": "Local onde a data foi encontrada"' . "\n" .
'    },' . "\n" .
'    "instituicao": {' . "\n" .
'        "valor": "Instituição relacionada",' . "\n" .
'        "evidencia": "Menções à instituição no documento"' . "\n" .
'    },' . "\n" .
'    "resumo": {' . "\n" .
'        "valor": "Resumo do conteúdo (máx. 500 caracteres)",' . "\n" .
'        "evidencia": "Seções principais que fundamentam o resumo"' . "\n" .
'    },' . "\n" .
'    "palavras_chave": {' . "\n" .
'        "valor": ["palavras", "chave"],' . "\n" .
'        "evidencia": "Seção de palavras-chave ou temas recorrentes identificados"' . "\n" .
'    },' . "\n" .
'    "area_conhecimento": {' . "\n" .
'        "valor": "Área principal",' . "\n" .
'        "evidencia": "Indicadores da área (terminologia, metodologia, referências)"' . "\n" .
'    },' . "\n" .
'    "idioma": {' . "\n" .
'        "valor": "Idioma do documento",' . "\n" .
'        "evidencia": "Idioma identificado no texto"' . "\n" .
'    },' . "\n" .
'    "referencias_principais": {' . "\n" .
'        "valor": ["Referências importantes citadas"],' . "\n" .
'        "evidencia": "Seção de referências ou citações no texto"' . "\n" .
'    },' . "\n" .
'    "metodologia": {' . "\n" .
'        "valor": "Metodologia utilizada (se aplicável)",' . "\n" .
'        "evidencia": "Seção de metodologia ou descrição dos métodos"' . "\n" .
'    },' . "\n" .
'    "observacoes": {' . "\n" .
'        "valor": "Outras observações",' . "\n" .
'        "evidencia": "Elementos adicionais notados no documento"' . "\n" .
'    }' . "\n" .
'}' . "\n\n" .
'Responda APENAS com o JSON, sem texto adicional.';
    }
}

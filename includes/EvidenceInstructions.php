<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Standard evidence output schema and file-type-specific extraction guidance.
 *
 * Appended to every analysis prompt at runtime so templates and custom prompts
 * do not need per-field evidence definitions.
 */
class EvidenceInstructions {

    public const STRATEGY_IMAGE = 'image';
    public const STRATEGY_DOCUMENT_TEXT = 'document_text';
    public const STRATEGY_DOCUMENT_VISUAL = 'document_visual';

    /**
     * Append output schema + strategy-specific evidence guidance to a prompt.
     */
    public static function append_to_prompt(string $prompt, string $strategy): string {
        $prompt = trim($prompt);
        $block = self::get_instruction_block($strategy);

        /**
         * Filters the evidence instruction block appended to analysis prompts.
         *
         * @param string $block    Full evidence section (schema + strategy guidance).
         * @param string $strategy One of EvidenceInstructions::STRATEGY_* constants.
         * @param string $prompt   Base prompt before appending evidence instructions.
         */
        $block = (string) apply_filters('tainacan_ai_evidence_instructions', $block, $strategy, $prompt);

        if ($block === '') {
            return $prompt;
        }

        return $prompt . "\n\n" . $block;
    }

    /**
     * @return string[]
     */
    public static function get_strategies(): array {
        return [
            self::STRATEGY_IMAGE,
            self::STRATEGY_DOCUMENT_TEXT,
            self::STRATEGY_DOCUMENT_VISUAL,
        ];
    }

    private static function get_instruction_block(string $strategy): string {
        $schema = self::get_output_schema_section();
        $guidance = self::get_strategy_guidance($strategy);

        return $schema . "\n\n" . $guidance;
    }

    private static function get_output_schema_section(): string {
        return '## Formato oficial de resposta (obrigatório)' . "\n" .
            'Retorne APENAS um JSON válido. Cada chave de metadado deve ser **um único objeto** com exatamente duas propriedades:' . "\n" .
            '- `"value"`: o valor extraído (string, número, array de strings ou null se não encontrado)' . "\n" .
            '- `"evidence"`: justificativa da origem (string ou null)' . "\n\n" .
            '### Campo com um único valor' . "\n" .
            '```json' . "\n" .
            '{' . "\n" .
            '  "titulo": {' . "\n" .
            '    "value": "Título encontrado",' . "\n" .
            '    "evidence": "Breve descrição da fonte no conteúdo"' . "\n" .
            '  }' . "\n" .
            '}' . "\n" .
            '```' . "\n\n" .
            '### Campo com múltiplos valores' . "\n" .
            'Use **arrays paralelos** dentro do mesmo objeto (`value` e `evidence` com o mesmo número de itens, na mesma ordem).' . "\n" .
            'NÃO retorne um array de objetos `{ "value", "evidence" }` no lugar da chave do campo.' . "\n" .
            '```json' . "\n" .
            '{' . "\n" .
            '  "referencias_principais": {' . "\n" .
            '    "value": ["Referência A", "Referência B"],' . "\n" .
            '    "evidence": ["Trecho ou seção de A", "Trecho ou seção de B"]' . "\n" .
            '  }' . "\n" .
            '}' . "\n" .
            '```' . "\n\n" .
            'Use as chaves de campo solicitadas no prompt acima. Não retorne valores escalares soltos no nível raiz.';
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

    private static function get_strategy_guidance(string $strategy): string {
        switch ($strategy) {
            case self::STRATEGY_IMAGE:
                return '## Como preencher `evidence` (imagem)' . "\n" .
                    'Para cada campo, descreva de forma objetiva o que na imagem sustenta o `value`:' . "\n" .
                    '- região ou elemento visual (ex.: assinatura no canto inferior direito, moldura, inscrição)' . "\n" .
                    '- texto legível na imagem, se houver' . "\n" .
                    '- pistas de estilo, material, técnica ou conservação observadas' . "\n" .
                    'Se o valor for inferido sem suporte visual claro, indique isso em `evidence` (ex.: "Inferido pelo estilo, sem inscrição visível").' . "\n" .
                    'Se não houver base no conteúdo, use `value`: null e explique em `evidence`.';

            case self::STRATEGY_DOCUMENT_VISUAL:
                return '## Como preencher `evidence` (documento visual / PDF escaneado)' . "\n" .
                    'Para cada campo, cite a origem nas páginas fornecidas:' . "\n" .
                    '- número da página (ex.: "página 2")' . "\n" .
                    '- área ou seção (capa, cabeçalho, rodapé, legenda, tabela)' . "\n" .
                    '- trecho ou elemento visual que sustenta o valor' . "\n" .
                    'Quando o valor for inferido, deixe explícito em `evidence`. Use `value`: null se não houver suporte.';

            case self::STRATEGY_DOCUMENT_TEXT:
            default:
                return '## Como preencher `evidence` (texto do documento)' . "\n" .
                    'Para cada campo, cite a origem no texto:' . "\n" .
                    '- seção ou título (ex.: "Resumo", "Referências", "Metodologia")' . "\n" .
                    '- página ou posição aproximada, se identificável' . "\n" .
                    '- trecho curto ou paráfrase que sustenta o `value` (sem copiar o documento inteiro)' . "\n" .
                    'Quando o valor for inferido, indique em `evidence`. Use `value`: null se não houver base no texto.';
        }
    }
}

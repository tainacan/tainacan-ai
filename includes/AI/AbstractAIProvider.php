<?php
namespace Tainacan\AI\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe base abstrata para provedores de IA
 *
 * Fornece implementações comuns para os provedores de IA.
 *
 * @package Tainacan_AI
 * @since 3.2.0
 */
abstract class AbstractAIProvider implements AIProviderInterface {

    protected string $api_key = '';
    protected string $model = '';
    protected int $max_tokens = 2000;
    protected float $temperature = 0.1;
    protected int $timeout = 120;

    /**
     * Configura o provedor com as opções fornecidas
     */
    public function configure(array $options): void {
        $this->api_key = $options['api_key'] ?? '';
        $this->model = $options['model'] ?? $this->get_default_model();
        $this->max_tokens = (int) ($options['max_tokens'] ?? 2000);
        $this->temperature = (float) ($options['temperature'] ?? 0.1);
        $this->timeout = (int) ($options['timeout'] ?? 120);
    }

    /**
     * Verifica se o provedor está configurado
     */
    public function is_configured(): bool {
        return !empty($this->api_key);
    }

    /**
     * Calcula custo baseado no uso
     */
    public function calculate_cost(array $usage, string $model): float {
        $pricing = $this->get_pricing($model);

        $input_tokens = $usage['prompt_tokens'] ?? 0;
        $output_tokens = $usage['completion_tokens'] ?? 0;

        $input_cost = ($input_tokens / 1000) * $pricing['input'];
        $output_cost = ($output_tokens / 1000) * $pricing['output'];

        return round($input_cost + $output_cost, 6);
    }

    /**
     * Sanitiza string para UTF-8 válido
     */
    protected function sanitize_utf8(string $string): string {
        if (mb_check_encoding($string, 'UTF-8')) {
            $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
            return $string;
        }

        $encodings = ['ISO-8859-1', 'Windows-1252', 'ASCII'];
        foreach ($encodings as $encoding) {
            $converted = @mb_convert_encoding($string, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $converted);
            }
        }

        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
    }

    /**
     * Faz parse da resposta JSON
     */
    protected function parse_json_response(string $content): ?array {
        // Tenta parse direto
        $metadata = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $metadata;
        }

        // Tenta extrair JSON do texto
        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $metadata = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $metadata;
            }
        }

        // Tenta remover markdown code blocks
        $content = preg_replace('/```json?\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim($content);

        $metadata = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $metadata;
        }

        return null;
    }

    /**
     * Faz requisição HTTP com wp_remote_post
     */
    protected function make_request(string $url, array $headers, array $body, int $timeout = null): array|\WP_Error {
        $json_body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json_body === false) {
            return new \WP_Error(
                'json_encode_error',
                /* translators: %s: JSON error message */
                sprintf(__('Erro ao preparar dados para API: %s', 'tainacan-ai'), json_last_error_msg())
            );
        }

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $json_body,
            'timeout' => $timeout ?? $this->timeout,
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'api_connection_error',
                /* translators: %s: error message */
                sprintf(__('Erro de conexão com a API: %s', 'tainacan-ai'), $response->get_error_message())
            );
        }

        return [
            'code' => wp_remote_retrieve_response_code($response),
            'body' => json_decode(wp_remote_retrieve_body($response), true),
        ];
    }

    /**
     * Debug log
     */
    protected function debug_log(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[TainacanAI][' . $this->get_id() . '] ' . $message);
        }
    }
}

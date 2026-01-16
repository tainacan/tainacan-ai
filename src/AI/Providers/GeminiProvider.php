<?php
namespace Tainacan\AI\AI\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use Tainacan\AI\AI\AbstractAIProvider;

/**
 * Provedor Google Gemini
 *
 * Implementa a integração com a API do Google Gemini para análise de documentos.
 *
 * @package Tainacan_AI
 * @since 3.2.0
 */
class GeminiProvider extends AbstractAIProvider {

    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent';

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'gemini';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'Google Gemini';
    }

    /**
     * {@inheritdoc}
     */
    public function get_available_models(): array {
        return [
            'gemini-2.0-flash-exp' => 'Gemini 2.0 Flash (Experimental)',
            'gemini-1.5-pro' => 'Gemini 1.5 Pro (Recomendado)',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash (Rápido)',
            'gemini-1.5-flash-8b' => 'Gemini 1.5 Flash 8B (Econômico)',
            'gemini-1.0-pro' => 'Gemini 1.0 Pro',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_model(): string {
        return 'gemini-1.5-pro';
    }

    /**
     * {@inheritdoc}
     */
    public function supports_vision(): bool {
        // Todos os modelos Gemini 1.5+ suportam visão
        return in_array($this->model, [
            'gemini-2.0-flash-exp',
            'gemini-1.5-pro',
            'gemini-1.5-flash',
            'gemini-1.5-flash-8b',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function get_pricing(string $model): array {
        // Preços por 1000 tokens (aproximados)
        $pricing = [
            'gemini-2.0-flash-exp' => ['input' => 0.0, 'output' => 0.0], // Preview gratuito
            'gemini-1.5-pro' => ['input' => 0.00125, 'output' => 0.005],
            'gemini-1.5-flash' => ['input' => 0.000075, 'output' => 0.0003],
            'gemini-1.5-flash-8b' => ['input' => 0.0000375, 'output' => 0.00015],
            'gemini-1.0-pro' => ['input' => 0.0005, 'output' => 0.0015],
        ];

        return $pricing[$model] ?? ['input' => 0.001, 'output' => 0.002];
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_image(string $image_data, string $prompt, array $options = []): array|\WP_Error {
        if (!$this->supports_vision()) {
            return new \WP_Error(
                'vision_not_supported',
                /* translators: %s: model name */
                sprintf(__('O modelo %s não suporta análise de imagens.', 'tainacan-ai'), $this->model)
            );
        }

        // Extrai mime type e base64 do data URL
        $image_info = $this->parse_image_data($image_data);

        $parts = [
            ['text' => $prompt],
            [
                'inline_data' => [
                    'mime_type' => $image_info['mime_type'],
                    'data' => $image_info['base64'],
                ],
            ],
        ];

        return $this->call_api($parts, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_images(array $images, string $prompt, array $options = []): array|\WP_Error {
        if (!$this->supports_vision()) {
            return new \WP_Error(
                'vision_not_supported',
                /* translators: %s: model name */
                sprintf(__('O modelo %s não suporta análise de imagens.', 'tainacan-ai'), $this->model)
            );
        }

        $parts = [
            ['text' => $prompt],
        ];

        foreach ($images as $image) {
            $image_info = $this->parse_image_data($image['data']);
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image_info['mime_type'],
                    'data' => $image_info['base64'],
                ],
            ];
        }

        return $this->call_api($parts, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_text(string $text, string $prompt, array $options = []): array|\WP_Error {
        $text = $this->sanitize_utf8($text);
        $full_prompt = $prompt . "\n\n---\n\n**Documento:**\n\n" . $text;

        $parts = [
            ['text' => $full_prompt],
        ];

        return $this->call_api($parts, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('Chave API não configurada.', 'tainacan-ai'),
            ];
        }

        $parts = [
            ['text' => 'Responda apenas com a palavra "OK".'],
        ];

        $url = str_replace('{model}', $this->model, self::API_URL) . '?key=' . $this->api_key;

        $body = [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 10,
            ],
        ];

        $response = $this->make_request(
            $url,
            ['Content-Type' => 'application/json'],
            $body,
            30
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        if ($response['code'] !== 200) {
            $error_msg = $response['body']['error']['message'] ?? __('Erro desconhecido', 'tainacan-ai');
            return [
                'success' => false,
                'message' => $error_msg,
            ];
        }

        return [
            'success' => true,
            'message' => __('Conexão estabelecida com sucesso!', 'tainacan-ai'),
        ];
    }

    /**
     * Chama a API do Gemini
     */
    private function call_api(array $parts, array $options = []): array|\WP_Error {
        $url = str_replace('{model}', $this->model, self::API_URL) . '?key=' . $this->api_key;

        $body = [
            'contents' => [
                ['parts' => $parts],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? $this->max_tokens,
                'temperature' => $options['temperature'] ?? $this->temperature,
                'responseMimeType' => 'application/json',
            ],
        ];

        $response = $this->make_request(
            $url,
            ['Content-Type' => 'application/json'],
            $body
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = $response['code'];
        $body = $response['body'];

        if ($code !== 200) {
            return $this->handle_api_error($code, $body);
        }

        $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($content)) {
            return new \WP_Error('empty_response', __('Resposta vazia da API.', 'tainacan-ai'));
        }

        $metadata = $this->parse_json_response($content);

        if ($metadata === null) {
            return new \WP_Error(
                'json_parse_error',
                __('Erro ao interpretar resposta da API. O formato retornado não é JSON válido.', 'tainacan-ai')
            );
        }

        // Gemini retorna uso de tokens diferente
        $usage = [
            'prompt_tokens' => $body['usageMetadata']['promptTokenCount'] ?? 0,
            'completion_tokens' => $body['usageMetadata']['candidatesTokenCount'] ?? 0,
            'total_tokens' => $body['usageMetadata']['totalTokenCount'] ?? 0,
        ];

        return [
            'metadata' => $metadata,
            'usage' => $usage,
            'model' => $this->model,
            'provider' => $this->get_id(),
        ];
    }

    /**
     * Extrai informações da imagem (base64 e mime type)
     */
    private function parse_image_data(string $image_data): array {
        // Se for data URL, extrai mime type e base64
        if (preg_match('/^data:([^;]+);base64,(.+)$/', $image_data, $matches)) {
            return [
                'mime_type' => $matches[1],
                'base64' => $matches[2],
            ];
        }

        // Se for apenas base64, assume JPEG
        return [
            'mime_type' => 'image/jpeg',
            'base64' => $image_data,
        ];
    }

    /**
     * Trata erros da API
     */
    private function handle_api_error(int $code, array $body): \WP_Error {
        $error_msg = $body['error']['message'] ?? __('Erro desconhecido na API Gemini', 'tainacan-ai');

        if ($code === 400) {
            $error_msg = __('Requisição inválida. Verifique os parâmetros.', 'tainacan-ai');
        } elseif ($code === 401 || $code === 403) {
            $error_msg = __('Chave API inválida ou sem permissão. Verifique suas configurações.', 'tainacan-ai');
        } elseif ($code === 429) {
            $error_msg = __('Limite de requisições excedido. Aguarde alguns minutos.', 'tainacan-ai');
        } elseif ($code === 500 || $code === 503) {
            $error_msg = __('Serviço do Google temporariamente indisponível. Tente novamente.', 'tainacan-ai');
        }

        return new \WP_Error('api_error', $error_msg);
    }
}

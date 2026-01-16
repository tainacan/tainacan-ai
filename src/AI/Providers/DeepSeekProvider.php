<?php
namespace Tainacan\AI\AI\Providers;

if (!defined('ABSPATH')) {
    exit;
}

use Tainacan\AI\AI\AbstractAIProvider;

/**
 * Provedor DeepSeek
 *
 * Implementa a integração com a API do DeepSeek para análise de documentos.
 * A API do DeepSeek é compatível com o formato da OpenAI.
 *
 * @package Tainacan_AI
 * @since 3.2.0
 */
class DeepSeekProvider extends AbstractAIProvider {

    private const API_URL = 'https://api.deepseek.com/chat/completions';

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'deepseek';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'DeepSeek';
    }

    /**
     * {@inheritdoc}
     */
    public function get_available_models(): array {
        return [
            'deepseek-chat' => 'DeepSeek Chat (V3)',
            'deepseek-reasoner' => 'DeepSeek Reasoner (R1)',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_model(): string {
        return 'deepseek-chat';
    }

    /**
     * {@inheritdoc}
     */
    public function supports_vision(): bool {
        // DeepSeek atualmente não suporta análise de imagens via API
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function get_pricing(string $model): array {
        // Preços por 1000 tokens (muito competitivos)
        $pricing = [
            'deepseek-chat' => ['input' => 0.00014, 'output' => 0.00028],
            'deepseek-reasoner' => ['input' => 0.00055, 'output' => 0.00219],
        ];

        return $pricing[$model] ?? ['input' => 0.0002, 'output' => 0.0004];
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_image(string $image_data, string $prompt, array $options = []): array|\WP_Error {
        return new \WP_Error(
            'vision_not_supported',
            __('DeepSeek does not support image analysis. Use text extraction for PDFs or choose another provider for images.', 'tainacan-ai')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_images(array $images, string $prompt, array $options = []): array|\WP_Error {
        return new \WP_Error(
            'vision_not_supported',
            __('DeepSeek does not support image analysis. Use text extraction for PDFs or choose another provider for images.', 'tainacan-ai')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_text(string $text, string $prompt, array $options = []): array|\WP_Error {
        $text = $this->sanitize_utf8($text);
        $full_prompt = $prompt . "\n\n---\n\n**Documento:**\n\n" . $text;

        $messages = [
            [
                'role' => 'system',
                'content' => 'Você é um assistente especializado em análise de documentos e extração de metadados. Sempre responda em formato JSON válido.',
            ],
            [
                'role' => 'user',
                'content' => $full_prompt,
            ],
        ];

        return $this->call_api($messages, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => __('API key not configured.', 'tainacan-ai'),
            ];
        }

        $messages = [
            [
                'role' => 'user',
                'content' => 'Responda apenas com a palavra "OK".',
            ],
        ];

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 10,
        ];

        $response = $this->make_request(
            self::API_URL,
            [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
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
            $error_msg = $response['body']['error']['message'] ?? __('Unknown error', 'tainacan-ai');
            return [
                'success' => false,
                'message' => $error_msg,
            ];
        }

        return [
            'success' => true,
            'message' => __('Connection established successfully!', 'tainacan-ai'),
        ];
    }

    /**
     * Chama a API do DeepSeek
     */
    private function call_api(array $messages, array $options = []): array|\WP_Error {
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? $this->max_tokens,
            'temperature' => $options['temperature'] ?? $this->temperature,
            'response_format' => ['type' => 'json_object'],
        ];

        $response = $this->make_request(
            self::API_URL,
            [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
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

        $content = $body['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            return new \WP_Error('empty_response', __('Empty response from API.', 'tainacan-ai'));
        }

        $metadata = $this->parse_json_response($content);

        if ($metadata === null) {
            return new \WP_Error(
                'json_parse_error',
                __('Error interpreting API response. The returned format is not valid JSON.', 'tainacan-ai')
            );
        }

        return [
            'metadata' => $metadata,
            'usage' => $body['usage'] ?? [],
            'model' => $this->model,
            'provider' => $this->get_id(),
        ];
    }

    /**
     * Trata erros da API
     */
    private function handle_api_error(int $code, array $body): \WP_Error {
        $error_msg = $body['error']['message'] ?? __('Unknown error in DeepSeek API', 'tainacan-ai');

        if ($code === 401) {
            $error_msg = __('Invalid or expired API key. Check your settings.', 'tainacan-ai');
        } elseif ($code === 429) {
            $error_msg = __('Rate limit exceeded. Wait a few minutes.', 'tainacan-ai');
        } elseif ($code === 500 || $code === 503) {
            $error_msg = __('DeepSeek service temporarily unavailable. Try again.', 'tainacan-ai');
        }

        return new \WP_Error('api_error', $error_msg);
    }
}

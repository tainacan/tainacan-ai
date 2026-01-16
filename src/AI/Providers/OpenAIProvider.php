<?php
namespace Tainacan\AI\AI\Providers;

use Tainacan\AI\AI\AbstractAIProvider;

/**
 * OpenAI Provider (ChatGPT)
 *
 * Implements integration with OpenAI API for document analysis.
 *
 * @package Tainacan_AI
 * @since 1.0.0
 */
class OpenAIProvider extends AbstractAIProvider {

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'openai';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'OpenAI (ChatGPT)';
    }

    /**
     * {@inheritdoc}
     */
    public function get_available_models(): array {
        return [
            'gpt-4o' => 'GPT-4o (Recomendado)',
            'gpt-4o-mini' => 'GPT-4o Mini (Econômico)',
            'gpt-4-turbo' => 'GPT-4 Turbo',
            'gpt-4' => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_model(): string {
        return 'gpt-4o';
    }

    /**
     * {@inheritdoc}
     */
    public function supports_vision(): bool {
        return in_array($this->model, ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo']);
    }

    /**
     * {@inheritdoc}
     */
    public function get_pricing(string $model): array {
        $pricing = [
            'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
            'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
            'gpt-4-turbo' => ['input' => 0.01, 'output' => 0.03],
            'gpt-4' => ['input' => 0.03, 'output' => 0.06],
            'gpt-3.5-turbo' => ['input' => 0.0005, 'output' => 0.0015],
        ];

        return $pricing[$model] ?? ['input' => 0.005, 'output' => 0.015];
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_image(string $image_data, string $prompt, array $options = []): array|\WP_Error {
        if (!$this->supports_vision()) {
            return new \WP_Error(
                'vision_not_supported',
                sprintf(__('O modelo %s não suporta análise de imagens.', 'tainacan-ai'), $this->model)
            );
        }

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image_data,
                            'detail' => $options['detail'] ?? 'high',
                        ],
                    ],
                ],
            ],
        ];

        return $this->call_api($messages, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_images(array $images, string $prompt, array $options = []): array|\WP_Error {
        if (!$this->supports_vision()) {
            return new \WP_Error(
                'vision_not_supported',
                sprintf(__('O modelo %s não suporta análise de imagens.', 'tainacan-ai'), $this->model)
            );
        }

        $content = [
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ];

        foreach ($images as $image) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $image['data'],
                    'detail' => $options['detail'] ?? 'high',
                ],
            ];
        }

        $messages = [
            [
                'role' => 'user',
                'content' => $content,
            ],
        ];

        return $this->call_api($messages, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_text(string $text, string $prompt, array $options = []): array|\WP_Error {
        $text = $this->sanitize_utf8($text);

        $full_prompt = $prompt . "\n\n---\n\n**Documento:**\n\n" . $text;

        $messages = [
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
                'message' => __('Chave API não configurada.', 'tainacan-ai'),
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
     * Chama a API da OpenAI
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

        // Debug: Log da resposta completa
        $this->debug_log('API Response: ' . print_r($body, true));

        $content = $body['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            // Verifica se há informações de erro no body
            $debug_info = '';
            if (isset($body['error'])) {
                $debug_info = ' Erro: ' . ($body['error']['message'] ?? json_encode($body['error']));
            } elseif (isset($body['choices']) && empty($body['choices'])) {
                $debug_info = ' Choices vazio.';
            } elseif (!isset($body['choices'])) {
                $debug_info = ' Resposta sem "choices": ' . json_encode(array_keys($body ?? []));
            }

            $this->debug_log('Empty response. Body keys: ' . json_encode(array_keys($body ?? [])) . $debug_info);

            return new \WP_Error('empty_response', __('Resposta vazia da API.', 'tainacan-ai') . $debug_info);
        }

        $metadata = $this->parse_json_response($content);

        if ($metadata === null) {
            return new \WP_Error(
                'json_parse_error',
                __('Erro ao interpretar resposta da API. O formato retornado não é JSON válido.', 'tainacan-ai')
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
        $error_msg = $body['error']['message'] ?? __('Erro desconhecido na API OpenAI', 'tainacan-ai');

        if ($code === 401) {
            $error_msg = __('Chave API inválida ou expirada. Verifique suas configurações.', 'tainacan-ai');
        } elseif ($code === 429) {
            $error_msg = __('Limite de requisições excedido. Aguarde alguns minutos.', 'tainacan-ai');
        } elseif ($code === 500 || $code === 503) {
            $error_msg = __('Serviço da OpenAI temporariamente indisponível. Tente novamente.', 'tainacan-ai');
        }

        return new \WP_Error('api_error', $error_msg);
    }
}

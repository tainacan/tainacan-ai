<?php
namespace Tainacan\AI\AI\Providers;

use Tainacan\AI\AI\AbstractAIProvider;

/**
 * Provedor Ollama (Local)
 *
 * Implementa a integração com a API do Ollama para análise de documentos.
 * Permite usar modelos de IA localmente sem custos de API.
 *
 * @package Tainacan_AI
 * @since 3.2.0
 * @see https://ollama.com/
 */
class OllamaProvider extends AbstractAIProvider {

    private string $base_url = 'http://localhost:11434';

    /**
     * {@inheritdoc}
     */
    public function get_id(): string {
        return 'ollama';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name(): string {
        return 'Ollama (Local)';
    }

    /**
     * {@inheritdoc}
     */
    public function get_available_models(): array {
        return [
            'llama3.2' => 'Llama 3.2 (Recomendado)',
            'llama3.2-vision' => 'Llama 3.2 Vision (Imagens)',
            'llama3.1' => 'Llama 3.1',
            'llava' => 'LLaVA (Vision)',
            'mistral' => 'Mistral 7B',
            'mixtral' => 'Mixtral 8x7B',
            'phi3' => 'Phi-3',
            'gemma2' => 'Gemma 2',
            'qwen2.5' => 'Qwen 2.5',
            'deepseek-r1' => 'DeepSeek R1',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function get_default_model(): string {
        return 'llama3.2';
    }

    /**
     * {@inheritdoc}
     */
    public function supports_vision(): bool {
        // Modelos com suporte a visão
        $vision_models = ['llama3.2-vision', 'llava', 'bakllava', 'moondream'];
        return in_array($this->model, $vision_models);
    }

    /**
     * {@inheritdoc}
     */
    public function configure(array $options): void {
        parent::configure($options);

        // Ollama usa URL base ao invés de API key
        if (!empty($options['base_url'])) {
            $this->base_url = rtrim($options['base_url'], '/');
        }

        // Para Ollama, a "api_key" é usada como URL base se parecer com URL
        if (!empty($this->api_key) && filter_var($this->api_key, FILTER_VALIDATE_URL)) {
            $this->base_url = rtrim($this->api_key, '/');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function is_configured(): bool {
        // Ollama não precisa de API key, apenas que o serviço esteja rodando
        return !empty($this->base_url);
    }

    /**
     * {@inheritdoc}
     */
    public function get_pricing(string $model): array {
        // Ollama é gratuito (roda localmente)
        return ['input' => 0.0, 'output' => 0.0];
    }

    /**
     * {@inheritdoc}
     */
    public function analyze_image(string $image_data, string $prompt, array $options = []): array|\WP_Error {
        if (!$this->supports_vision()) {
            return new \WP_Error(
                'vision_not_supported',
                sprintf(
                    /* translators: %s: model name */
                    __('O modelo %s não suporta análise de imagens. Use llama3.2-vision ou llava.', 'tainacan-ai'),
                    $this->model
                )
            );
        }

        // Extrai base64 da imagem
        $image_base64 = $this->extract_base64($image_data);

        $messages = [
            [
                'role' => 'user',
                'content' => $prompt,
                'images' => [$image_base64],
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
                sprintf(
                    /* translators: %s: model name */
                    __('O modelo %s não suporta análise de imagens. Use llama3.2-vision ou llava.', 'tainacan-ai'),
                    $this->model
                )
            );
        }

        $image_bases = [];
        foreach ($images as $image) {
            $image_bases[] = $this->extract_base64($image['data']);
        }

        $messages = [
            [
                'role' => 'user',
                'content' => $prompt,
                'images' => $image_bases,
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
        // Tenta listar modelos disponíveis
        $url = $this->base_url . '/api/tags';

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: Ollama base URL */
                    __('Não foi possível conectar ao Ollama em %s. Verifique se o serviço está rodando.', 'tainacan-ai'),
                    $this->base_url
                ),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %d: HTTP status code */
                    __('Ollama retornou erro %d. Verifique a URL e se o serviço está rodando.', 'tainacan-ai'),
                    $code
                ),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $models = [];

        if (!empty($body['models'])) {
            foreach ($body['models'] as $model) {
                $models[] = $model['name'];
            }
        }

        // Verifica se o modelo configurado está disponível
        $model_available = empty($models) || in_array($this->model, $models);
        $model_warning = '';

        if (!empty($models) && !$model_available) {
            $model_warning = sprintf(
                /* translators: %1$s: model name, %2$s: list of available models */
                __(' Aviso: modelo "%1$s" não encontrado. Modelos disponíveis: %2$s', 'tainacan-ai'),
                $this->model,
                implode(', ', array_slice($models, 0, 5))
            );
        }

        return [
            'success' => true,
            'message' => __('Conexão com Ollama estabelecida!', 'tainacan-ai') . $model_warning,
            'models' => $models,
        ];
    }

    /**
     * Chama a API do Ollama
     */
    private function call_api(array $messages, array $options = []): array|\WP_Error {
        $url = $this->base_url . '/api/chat';

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'format' => 'json',
            'options' => [
                'num_predict' => $options['max_tokens'] ?? $this->max_tokens,
                'temperature' => $options['temperature'] ?? $this->temperature,
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

        $content = $body['message']['content'] ?? '';

        if (empty($content)) {
            return new \WP_Error('empty_response', __('Resposta vazia do Ollama.', 'tainacan-ai'));
        }

        $metadata = $this->parse_json_response($content);

        if ($metadata === null) {
            return new \WP_Error(
                'json_parse_error',
                __('Erro ao interpretar resposta do Ollama. O formato retornado não é JSON válido.', 'tainacan-ai')
            );
        }

        // Ollama retorna tokens de forma diferente
        $usage = [
            'prompt_tokens' => $body['prompt_eval_count'] ?? 0,
            'completion_tokens' => $body['eval_count'] ?? 0,
            'total_tokens' => ($body['prompt_eval_count'] ?? 0) + ($body['eval_count'] ?? 0),
        ];

        return [
            'metadata' => $metadata,
            'usage' => $usage,
            'model' => $this->model,
            'provider' => $this->get_id(),
        ];
    }

    /**
     * Extrai base64 de data URL ou retorna string original
     */
    private function extract_base64(string $image_data): string {
        if (preg_match('/^data:[^;]+;base64,(.+)$/', $image_data, $matches)) {
            return $matches[1];
        }
        return $image_data;
    }

    /**
     * Trata erros da API
     */
    private function handle_api_error(int $code, array $body): \WP_Error {
        $error_msg = $body['error'] ?? __('Erro desconhecido no Ollama', 'tainacan-ai');

        if ($code === 404) {
            $error_msg = sprintf(
                /* translators: %1$s: model name, %2$s: model name for command */
                __('Modelo "%1$s" não encontrado. Execute: ollama pull %2$s', 'tainacan-ai'),
                $this->model,
                $this->model
            );
        } elseif ($code === 500) {
            $error_msg = __('Erro interno do Ollama. Verifique os logs do serviço.', 'tainacan-ai');
        }

        return new \WP_Error('api_error', $error_msg);
    }
}

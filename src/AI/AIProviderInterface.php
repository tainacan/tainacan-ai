<?php
namespace Tainacan\AI\AI;

/**
 * Interface para provedores de IA
 *
 * Define o contrato que todos os provedores de IA devem implementar
 * para garantir compatibilidade com o sistema de análise de documentos.
 *
 * @package Tainacan_AI
 * @since 3.2.0
 */
interface AIProviderInterface {

    /**
     * Retorna o identificador único do provedor
     */
    public function get_id(): string;

    /**
     * Retorna o nome de exibição do provedor
     */
    public function get_name(): string;

    /**
     * Retorna a lista de modelos disponíveis
     *
     * @return array Array associativo [model_id => model_name]
     */
    public function get_available_models(): array;

    /**
     * Retorna o modelo padrão do provedor
     */
    public function get_default_model(): string;

    /**
     * Verifica se o provedor suporta análise de imagens (vision)
     */
    public function supports_vision(): bool;

    /**
     * Verifica se a configuração do provedor está válida
     */
    public function is_configured(): bool;

    /**
     * Configura o provedor com as opções fornecidas
     *
     * @param array $options Opções de configuração (api_key, model, etc.)
     */
    public function configure(array $options): void;

    /**
     * Analisa uma imagem
     *
     * @param string $image_data URL ou base64 da imagem
     * @param string $prompt Prompt para análise
     * @param array $options Opções adicionais (max_tokens, temperature, etc.)
     * @return array|WP_Error Resultado com 'metadata' e 'usage' ou erro
     */
    public function analyze_image(string $image_data, string $prompt, array $options = []): array|\WP_Error;

    /**
     * Analisa múltiplas imagens (para PDFs convertidos)
     *
     * @param array $images Array de imagens (cada uma com 'data' e 'mime')
     * @param string $prompt Prompt para análise
     * @param array $options Opções adicionais
     * @return array|WP_Error Resultado com 'metadata' e 'usage' ou erro
     */
    public function analyze_images(array $images, string $prompt, array $options = []): array|\WP_Error;

    /**
     * Analisa texto
     *
     * @param string $text Texto para análise
     * @param string $prompt Prompt para análise
     * @param array $options Opções adicionais
     * @return array|WP_Error Resultado com 'metadata' e 'usage' ou erro
     */
    public function analyze_text(string $text, string $prompt, array $options = []): array|\WP_Error;

    /**
     * Retorna informações de preço por 1000 tokens
     *
     * @param string $model ID do modelo
     * @return array ['input' => float, 'output' => float]
     */
    public function get_pricing(string $model): array;

    /**
     * Calcula custo estimado baseado no uso
     *
     * @param array $usage Array com 'prompt_tokens' e 'completion_tokens'
     * @param string $model ID do modelo
     * @return float Custo em USD
     */
    public function calculate_cost(array $usage, string $model): float;

    /**
     * Testa a conexão com a API
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public function test_connection(): array;
}

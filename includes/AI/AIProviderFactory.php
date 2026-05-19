<?php
namespace Tainacan\AI\AI;

if (!defined('ABSPATH')) {
    exit;
}

use Tainacan\AI\AI\Providers\OpenAIProvider;
use Tainacan\AI\AI\Providers\GeminiProvider;
use Tainacan\AI\AI\Providers\DeepSeekProvider;
use Tainacan\AI\AI\Providers\OllamaProvider;

/**
 * Factory for creating AI providers
 *
 * Manages creation and configuration of available AI providers.
 *
 * @package Tainacan_AI
 * @since 1.0.0
 */
class AIProviderFactory {

    /**
     * Registered providers
     */
    private static array $providers = [];

    /**
     * Register default providers
     */
    public static function init(): void {
        self::register('openai', OpenAIProvider::class);
        self::register('gemini', GeminiProvider::class);
        self::register('deepseek', DeepSeekProvider::class);
        self::register('ollama', OllamaProvider::class);

        // Allow other plugins to register providers
        do_action('tainacan_ai_register_providers');
    }

    /**
     * Register a new provider
     */
    public static function register(string $id, string $class): void {
        if (!is_subclass_of($class, AIProviderInterface::class)) {
            throw new \InvalidArgumentException(
                sprintf('Provider class %s must implement AIProviderInterface', esc_html($class))
            );
        }
        self::$providers[$id] = $class;
    }

    /**
     * Create a provider instance
     */
    public static function create(string $provider_id, array $options = []): ?AIProviderInterface {
        if (empty(self::$providers)) {
            self::init();
        }

        if (!isset(self::$providers[$provider_id])) {
            return null;
        }

        $class = self::$providers[$provider_id];
        $provider = new $class();
        $provider->configure($options);

        return $provider;
    }

    /**
     * Return list of available providers
     */
    public static function get_available_providers(): array {
        if (empty(self::$providers)) {
            self::init();
        }

        $available = [];

        foreach (self::$providers as $id => $class) {
            $provider = new $class();
            $available[$id] = [
                'id' => $id,
                'name' => $provider->get_name(),
                'models' => $provider->get_available_models(),
                'default_model' => $provider->get_default_model(),
                'supports_vision' => $provider->supports_vision(),
            ];
        }

        return $available;
    }

    /**
     * Return information about a specific provider
     */
    public static function get_provider_info(string $provider_id): ?array {
        $providers = self::get_available_providers();
        return $providers[$provider_id] ?? null;
    }

    /**
     * Check if a provider exists
     */
    public static function provider_exists(string $provider_id): bool {
        if (empty(self::$providers)) {
            self::init();
        }

        return isset(self::$providers[$provider_id]);
    }

    /**
     * Test connection with a provider
     */
    public static function test_provider(string $provider_id, string $api_key, string $model = ''): array {
        $provider = self::create($provider_id, [
            'api_key' => $api_key,
            'model' => $model,
        ]);

        if (!$provider) {
            return [
                'success' => false,
                'message' => __('Provider not found.', 'tainacan-ai'),
            ];
        }

        return $provider->test_connection();
    }
}

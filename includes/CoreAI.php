<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

// Stub for static analysis. WordPress provides the real function in WP 7.0+.
if (!function_exists('\wp_ai_client_prompt')) {
    /**
     * @internal
     * @param string $prompt_text
     * @return mixed
     */
    function wp_ai_client_prompt(string $prompt_text) {
        return null;
    }
}

/**
 * Core AI adapter for WordPress 7.0+
 *
 * Centralizes:
 * - Building prompts via wp_ai_client_prompt()
 * - Adding file inputs (images) to prompts
 * - Parsing JSON output into associative arrays
 * - Extracting token usage + provider/model metadata
 *
 * Note: We keep this intentionally small to avoid extra abstraction layers.
 */
class CoreAI {
    private const DUMMY_IMAGE_DATA_URI = 'data:image/jpeg;base64,AA==';

    /**
     * Best-effort: detect if at least one Core connector has an API key.
     *
     * WordPress stores connector credentials in options. For the migration
     * we don't want to depend exclusively on Core prompt-builder "support"
     * method names (they can differ by WP version).
     */
    private static function has_connectors_api_key(): bool {
        // Most common keys (as observed in the migration environment).
        $known_keys = [
            'connectors_ai_openai_api_key',
            'connectors_ai_google_api_key',
            'connectors_ai_anthropic_api_key',
        ];

        foreach ($known_keys as $key_name) {
            $value = get_option($key_name, '');
            if (!empty($value) && is_string($value)) {
                return true;
            }
            if (!empty($value) && !is_string($value)) {
                // Handles cases where the connector stores non-string values.
                return true;
            }
        }

        // Fallback: check if any "connectors_ai_*api_key" option exists.
        // Using a broad LIKE keeps this resilient across providers.
        try {
            global $wpdb;
            if (!isset($wpdb) || empty($wpdb) || empty($wpdb->options)) {
                return false;
            }

            $option_name = $wpdb->get_var(
                "SELECT option_name FROM {$wpdb->options}
                WHERE option_name LIKE 'connectors_ai%api_key'
                AND option_value <> ''
                LIMIT 1"
            );

            return !empty($option_name);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if text generation is supported for the current site configuration.
     *
     * Deterministic and does not make network calls.
     */
    public static function is_supported_text_generation(): bool {
        if (!function_exists('\wp_ai_client_prompt')) {
            return false;
        }

        $connectors_configured = self::has_connectors_api_key();

        try {
            $builder = wp_ai_client_prompt('test');
            $support_methods = [
                'is_supported_for_text_generation',
                'isSupportedForTextGeneration',
                // Some implementations may expose alternative names:
                'is_supported_for_text',
                'isSupportedForText',
            ];

            $hasMagicCall = method_exists($builder, '__call');

            foreach ($support_methods as $method) {
                if (!self::builderHas($builder, $method) && !$hasMagicCall) {
                    continue;
                }

                try {
                    $supported = (bool) $builder->$method();
                    return $supported || $connectors_configured;
                } catch (\Throwable $e) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            return $connectors_configured;
        }

        // If we can't detect support via the builder, use connectors presence.
        return $connectors_configured;
    }

    /**
     * Check if models that support image inputs are available.
     *
     * We build a prompt that includes a tiny dummy image file and check support.
     */
    public static function is_supported_image_analysis(): bool {
        if (!function_exists('\wp_ai_client_prompt')) {
            return false;
        }

        $connectors_configured = self::has_connectors_api_key();

        try {
            $builder = wp_ai_client_prompt('test');
            self::add_file_to_builder($builder, self::DUMMY_IMAGE_DATA_URI, 'image/jpeg');

            $image_support_methods = [
                // Snake_case variants
                'is_supported_for_image_analysis',
                'is_supported_for_image_generation',
                'is_supported_for_vision',
                'is_supported_for_images',
                // CamelCase variants
                'isSupportedForImageAnalysis',
                'isSupportedForImageGeneration',
                'isSupportedForVision',
                'isSupportedForImages',
            ];

            $hasMagicCall = method_exists($builder, '__call');

            foreach ($image_support_methods as $method) {
                if (!self::builderHas($builder, $method) && !$hasMagicCall) {
                    continue;
                }

                try {
                    $supported = (bool) $builder->$method();
                    return $supported || $connectors_configured;
                } catch (\Throwable $e) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            // Don't block image attempts if connectors are configured.
            return self::is_supported_text_generation();
        }

        // If we can't detect image support method names, don't block.
        return self::is_supported_text_generation() || $connectors_configured;
    }

    /**
     * Generate JSON from a text-only prompt.
     *
     * @return array{metadata: array, usage: array{prompt_tokens:int,completion_tokens:int,total_tokens:int}, model: string, provider: string}| \WP_Error
     */
    public static function generate_json_from_text(
        string $prompt_text,
        array $options = []
    ): array|\WP_Error {
        return self::generate_json_from_prompt($prompt_text, [], $options);
    }

    /**
     * Generate JSON from a prompt plus one or more file inputs (e.g. images).
     *
     * @param array $files Each item: ['data' => string, 'mime' => string]
     * @return array{metadata: array, usage: array{prompt_tokens:int,completion_tokens:int,total_tokens:int}, model: string, provider: string}| \WP_Error
     */
    public static function generate_json_from_text_and_files(
        string $prompt_text,
        array $files,
        array $options = []
    ): array|\WP_Error {
        return self::generate_json_from_prompt($prompt_text, $files, $options);
    }

    /**
     * @param array $files Each item: ['data' => string, 'mime' => string]
     */
    private static function generate_json_from_prompt(
        string $prompt_text,
        array $files,
        array $options
    ): array|\WP_Error {
        if (!function_exists('\wp_ai_client_prompt')) {
            return new \WP_Error('no_core_ai_client', __('WordPress Core AI Client is not available.', 'tainacan-ai'));
        }

        $log_context = null;
        if (
            isset($options[CoreAIRequestLogging::OPTIONS_CONTEXT_KEY])
            && is_array($options[CoreAIRequestLogging::OPTIONS_CONTEXT_KEY])
        ) {
            $log_context = $options[CoreAIRequestLogging::OPTIONS_CONTEXT_KEY];
        }
        unset($options[CoreAIRequestLogging::OPTIONS_CONTEXT_KEY]);

        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : null;
        $max_tokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : null;

        $log_scope = null;
        if ($log_context !== null && CoreAIRequestLogging::is_active()) {
            $log_scope = CoreAIRequestLogging::begin($log_context);
        }

        try {
            $builder = wp_ai_client_prompt($prompt_text);

            if ($temperature !== null) {
                self::builderUsingTemperature($builder, $temperature);
            }
            if ($max_tokens !== null) {
                self::builderUsingMaxTokens($builder, $max_tokens);
            }

            foreach ($files as $file) {
                if (empty($file['data']) || empty($file['mime'])) {
                    continue;
                }
                self::add_file_to_builder($builder, $file['data'], $file['mime']);
            }

            // Use result object (we need usage + metadata).
            $result = self::builderGenerateTextResult($builder);

            if (is_wp_error($result)) {
                return $result;
            }

            $raw_text = self::extract_result_text($result);
            if ($raw_text === '') {
                return new \WP_Error('empty_response', __('Empty response from AI.', 'tainacan-ai'));
            }

            $metadata = self::parse_json_response($raw_text);
            if ($metadata === null) {
                return new \WP_Error('json_parse_error', __('AI returned invalid JSON.', 'tainacan-ai'));
            }

            $usage_obj = method_exists($result, 'getTokenUsage') ? $result->getTokenUsage() : null;
            $usage = self::extract_usage_array($usage_obj);

            $provider = '';
            $model = '';

            $provider_meta = method_exists($result, 'getProviderMetadata') ? $result->getProviderMetadata() : null;
            if ($provider_meta) {
                $provider = self::maybe_get_meta_id($provider_meta);
            }

            $model_meta = method_exists($result, 'getModelMetadata') ? $result->getModelMetadata() : null;
            if ($model_meta) {
                $model = self::maybe_get_meta_id($model_meta);
            }

            return [
                'metadata' => $metadata,
                'usage' => $usage,
                'model' => $model,
                'provider' => $provider,
            ];
        } catch (\Throwable $e) {
            return new \WP_Error('ai_generation_error', $e->getMessage());
        } finally {
            if ($log_scope !== null) {
                $log_scope->release();
            }
        }
    }

    private static function builderHas(object $builder, string $method): bool {
        return is_object($builder) && method_exists($builder, $method);
    }

    private static function builderUsingTemperature(object $builder, float $temperature): void {
        if (self::builderHas($builder, 'using_temperature')) {
            $builder->using_temperature($temperature);
            return;
        }
        if (self::builderHas($builder, 'usingTemperature')) {
            $builder->usingTemperature($temperature);
            return;
        }
    }

    private static function builderUsingMaxTokens(object $builder, int $max_tokens): void {
        if (self::builderHas($builder, 'using_max_tokens')) {
            $builder->using_max_tokens($max_tokens);
            return;
        }
        if (self::builderHas($builder, 'usingMaxTokens')) {
            $builder->usingMaxTokens($max_tokens);
            return;
        }
    }

    private static function builderGenerateTextResult(object $builder): mixed {
        // Method names vary across WP/AI Client SDK versions, and some
        // implementations can rely on __call. We'll attempt known method
        // names without relying solely on method_exists().
        $candidates = [
            'generate_text_result',
            'generateTextResult',
            'generate_text',
            'generateText',
            'generate',
        ];

        $hasMagicCall = method_exists($builder, '__call');

        foreach ($candidates as $method_name) {
            try {
                if (!self::builderHas($builder, $method_name) && !$hasMagicCall) {
                    continue;
                }
                $result = $builder->$method_name();
                // If __call returns null/false, keep searching.
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                // Try next candidate.
                continue;
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $methods = get_class_methods($builder);
            error_log('[TainacanAI][CoreAI] No generate text method found. Methods: ' . wp_json_encode(array_slice($methods, 0, 50)));
        }

        return new \WP_Error(
            'core_ai_no_generate_text_result',
            __('Core builder does not expose a supported text-generation method.', 'tainacan-ai')
        );
    }

    /**
     * @return string
     */
    private static function extract_result_text(object $result): string {
        if (method_exists($result, 'toText')) {
            try {
                return (string) $result->toText();
            } catch (\Throwable $e) {
                return '';
            }
        }
        if (method_exists($result, 'to_text')) {
            try {
                return (string) $result->to_text();
            } catch (\Throwable $e) {
                return '';
            }
        }

        if (method_exists($result, 'toMessage')) {
            try {
                $message = $result->toMessage();
                if (method_exists($message, 'getParts')) {
                    $parts = $message->getParts();
                    foreach ($parts as $part) {
                        if (method_exists($part, 'getText')) {
                            $text = $part->getText();
                            if ($text !== null && $text !== '') {
                                return (string) $text;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return '';
    }

    /**
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     */
    private static function extract_usage_array(mixed $usage_obj): array {
        $prompt = 0;
        $completion = 0;
        $total = 0;

        if (is_object($usage_obj)) {
            if (method_exists($usage_obj, 'getPromptTokens')) {
                $prompt = (int) $usage_obj->getPromptTokens();
            } elseif (method_exists($usage_obj, 'get_prompt_tokens')) {
                $prompt = (int) $usage_obj->get_prompt_tokens();
            }

            if (method_exists($usage_obj, 'getCompletionTokens')) {
                $completion = (int) $usage_obj->getCompletionTokens();
            } elseif (method_exists($usage_obj, 'get_completion_tokens')) {
                $completion = (int) $usage_obj->get_completion_tokens();
            }

            if (method_exists($usage_obj, 'getTotalTokens')) {
                $total = (int) $usage_obj->getTotalTokens();
            } elseif (method_exists($usage_obj, 'get_total_tokens')) {
                $total = (int) $usage_obj->get_total_tokens();
            }
        }

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ];
    }

    private static function maybe_get_meta_id(object $meta): string {
        if (method_exists($meta, 'getId')) {
            return (string) $meta->getId();
        }
        if (method_exists($meta, 'get_id')) {
            return (string) $meta->get_id();
        }

        if (property_exists($meta, 'id')) {
            $id = $meta->id;
            return is_scalar($id) ? (string) $id : '';
        }

        return '';
    }

    /**
     * @return void
     */
    private static function add_file_to_builder(object $builder, string $file_data, string $mime_type): void {
        // Core builder uses file support under the hood.
        // Some implementations expose methods directly, others may rely on
        // __call() magic. So we try calling candidate methods instead of
        // relying exclusively on method_exists().
        $method_candidates = [
            'with_file',
            'withFile',
            // Additional common variants (defensive).
            'withFileInput',
            'withFileInputs',
            'withFiles',
            'add_file',
            'addFile',
            'attachFile',
            'attach_file',
        ];

        $arg_sets = [
            [$file_data, $mime_type],
            [$file_data],
        ];

        foreach ($method_candidates as $method) {
            foreach ($arg_sets as $args) {
                try {
                    $builder->$method(...$args);
                    return;
                } catch (\Throwable $e) {
                    // Try next candidate/method signature.
                    continue;
                }
            }
        }

        $available_methods = [];
        try {
            $available_methods = get_class_methods($builder) ?: [];
        } catch (\Throwable $e) {
            $available_methods = [];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(
                '[TainacanAI][CoreAI] File attach failed. ' .
                'Tried methods: ' . wp_json_encode($method_candidates) . '. ' .
                'Builder methods (sample): ' . wp_json_encode(array_slice($available_methods, 0, 50))
            );
        }

        throw new \RuntimeException(
            'Core AI builder does not expose any supported file-input method (tried with_file/withFile and variants).'
        );
    }

    /**
     * Parses a response that should contain JSON (possibly wrapped in code blocks).
     */
    private static function parse_json_response(string $content): ?array {
        $metadata = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $metadata;
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $metadata = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $metadata;
            }
        }

        // Remove markdown code blocks.
        $content = preg_replace('/```json?\s*/', '', $content);
        $content = preg_replace('/```\s*/', '', $content);
        $content = trim((string) $content);

        $metadata = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $metadata;
        }

        return null;
    }
}


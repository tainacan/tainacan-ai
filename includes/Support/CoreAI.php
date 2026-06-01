<?php
namespace Tainacan\AI\Support;

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
    public const OPTIONS_SYSTEM_INSTRUCTION_KEY = 'system_instruction';
    /** No vision-capable model in connector metadata (configured active connectors). */
    public const IMAGE_SUPPORT_UNAVAILABLE = 'unavailable';

    /**
     * At least one configured connector declares a vision model in metadata.
     * Runtime model selection is not guaranteed — confirm by testing on an item.
     */
    public const IMAGE_SUPPORT_CATALOG = 'catalog';

    /** Cannot scan connectors or AI client metadata is unavailable. */
    public const IMAGE_SUPPORT_UNKNOWN = 'unknown';

    /** Upper cap for AI HTTP timeout when PHP max_execution_time is unlimited (0). */
    private const REQUEST_TIMEOUT_CAP = 300;

    /** Preferred minimum AI HTTP timeout (seconds). */
    private const REQUEST_TIMEOUT_MIN = 10;

    /**
     * Allowed range for the AI HTTP request timeout setting.
     *
     * The maximum is limited by PHP max_execution_time when that ini value is set.
     *
     * @return array{min: int, max: int}
     */
    public static function get_request_timeout_bounds(): array {
        $php_limit = (int) ini_get('max_execution_time');
        $max = ($php_limit > 0)
            ? min(self::REQUEST_TIMEOUT_CAP, $php_limit)
            : self::REQUEST_TIMEOUT_CAP;
        $min = min(self::REQUEST_TIMEOUT_MIN, $max);

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Allowed range for temperature (WordPress AI client ModelConfig schema).
     *
     * @return array{min: float, max: float, step: float}
     */
    public static function get_temperature_bounds(): array {
        return [
            'min' => 0.0,
            'max' => 2.0,
            'step' => 0.1,
        ];
    }

    /**
     * Whether any active AI connector has credentials configured.
     *
     * Prefers WordPress\AI\has_ai_credentials() when the AI plugin helpers are loaded;
     * falls back to option-name heuristics for Core-only Connectors setups.
     */
    private static function has_connector_credentials(): bool {
        if (function_exists('\WordPress\AI\has_ai_credentials')) {
            return (bool) \WordPress\AI\has_ai_credentials();
        }

        return self::has_connectors_api_key_fallback();
    }

    /**
     * Best-effort credential detection when WordPress\AI\has_ai_credentials() is unavailable.
     */
    private static function has_connectors_api_key_fallback(): bool {
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
     * Does not perform analysis HTTP requests. When available, uses the same
     * builder probe as WordPress\AI\has_valid_ai_credentials().
     */
    public static function is_supported_text_generation(): bool {
        if (!function_exists('\wp_ai_client_prompt')) {
            return false;
        }

        if (function_exists('\WordPress\AI\has_valid_ai_credentials')) {
            try {
                if (\WordPress\AI\has_valid_ai_credentials()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // Fall through to heuristics (metadata can false-negative at runtime).
            }
        }

        $has_credentials = self::has_connector_credentials();
        $has_connectors = self::get_active_ai_connector_ids() !== [];

        if ($has_connectors && $has_credentials) {
            return true;
        }

        if (self::probe_builder_supports_text_generation()) {
            return true;
        }

        return $has_credentials;
    }

    /**
     * Probe wp_ai_client_prompt() builder support flags (no outbound AI request).
     */
    private static function probe_builder_supports_text_generation(): bool {
        try {
            $builder = wp_ai_client_prompt('test');
            $support_methods = [
                'is_supported_for_text_generation',
                'isSupportedForTextGeneration',
                'is_supported_for_text',
                'isSupportedForText',
            ];

            $has_magic_call = method_exists($builder, '__call');

            foreach ($support_methods as $method) {
                if (!self::builderHas($builder, $method) && !$has_magic_call) {
                    continue;
                }

                try {
                    if ((bool) $builder->$method()) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Vision support from connector model metadata (WP AI capability "vision" rules).
     *
     * @return self::IMAGE_SUPPORT_* One of the IMAGE_SUPPORT_* constants.
     */
    public static function get_image_analysis_support_status(): string {
        if (!class_exists(\WordPress\AiClient\AiClient::class)) {
            return self::IMAGE_SUPPORT_UNKNOWN;
        }

        $requirements = self::build_vision_model_requirements();
        if ($requirements === null) {
            return self::IMAGE_SUPPORT_UNKNOWN;
        }

        $connector_ids = self::get_active_ai_connector_ids();
        if ($connector_ids === []) {
            return self::IMAGE_SUPPORT_UNKNOWN;
        }

        $registry = \WordPress\AiClient\AiClient::defaultRegistry();

        foreach ($connector_ids as $connector_id) {
            try {
                $models = $registry->findProviderModelsMetadataForSupport($connector_id, $requirements);
                if (!empty($models)) {
                    return self::IMAGE_SUPPORT_CATALOG;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return self::IMAGE_SUPPORT_UNAVAILABLE;
    }

    /**
     * Whether image analysis may be attempted (not known to be impossible from metadata).
     */
    public static function is_supported_image_analysis(): bool {
        return self::get_image_analysis_support_status() !== self::IMAGE_SUPPORT_UNAVAILABLE;
    }

    /**
     * Admin UI label and status class for image analysis via connector.
     *
     * @return array{class: string, label: string}
     */
    public static function get_image_analysis_support_display(): array {
        switch (self::get_image_analysis_support_status()) {
            case self::IMAGE_SUPPORT_CATALOG:
                return [
                    'class' => 'status-unknown',
                    'label' => __('Requires testing', 'tainacan-ai'),
                ];
            case self::IMAGE_SUPPORT_UNAVAILABLE:
                return [
                    'class' => 'status-warn',
                    'label' => __('Unavailable', 'tainacan-ai'),
                ];
            default:
                return [
                    'class' => 'status-unknown',
                    'label' => __('Requires testing', 'tainacan-ai'),
                ];
        }
    }

    /**
     * Vision requirements: text generation + input modalities text and image.
     *
     * @return \WordPress\AiClient\Providers\Models\DTO\ModelRequirements|null
     */
    private static function build_vision_model_requirements(): ?object {
        if (
            !class_exists(\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::class)
            || !class_exists(\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::class)
            || !class_exists(\WordPress\AiClient\Providers\Models\Enums\OptionEnum::class)
            || !class_exists(\WordPress\AiClient\Providers\Models\DTO\RequiredOption::class)
            || !class_exists(\WordPress\AiClient\Messages\Enums\ModalityEnum::class)
        ) {
            return null;
        }

        return new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
            [
                \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
            ],
            [
                new \WordPress\AiClient\Providers\Models\DTO\RequiredOption(
                    \WordPress\AiClient\Providers\Models\Enums\OptionEnum::inputModalities(),
                    [
                        \WordPress\AiClient\Messages\Enums\ModalityEnum::text(),
                        \WordPress\AiClient\Messages\Enums\ModalityEnum::image(),
                    ]
                ),
            ]
        );
    }

    /**
     * Active AI provider connector IDs.
     *
     * Prefers WordPress\AI\get_ai_connectors() when loaded (same rules as core Tainacan
     * and the AI plugin REST models API); falls back to wp_get_connectors().
     *
     * @return list<string>
     */
    private static function get_active_ai_connector_ids(): array {
        if (function_exists('\WordPress\AI\get_ai_connectors')) {
            $connectors = \WordPress\AI\get_ai_connectors();

            return is_array($connectors) ? array_keys($connectors) : [];
        }

        if (!function_exists('wp_get_connectors')) {
            return [];
        }

        $connector_ids = [];
        foreach ((array) wp_get_connectors() as $connector_id => $data) {
            if (!is_string($connector_id) || !is_array($data)) {
                continue;
            }
            if (($data['type'] ?? '') !== 'ai_provider') {
                continue;
            }
            if (
                function_exists('\WordPress\AI\is_connector_plugin_active')
                && !\WordPress\AI\is_connector_plugin_active($data)
            ) {
                continue;
            }
            $connector_ids[] = $connector_id;
        }

        return $connector_ids;
    }

    /**
     * Prefer vision-capable models when the WordPress AI plugin exposes preferences.
     *
     * @param object $builder Prompt builder from wp_ai_client_prompt().
     */
    private static function apply_vision_model_preferences(object $builder): void {
        $preferences = self::get_vision_model_preferences();
        if ($preferences === []) {
            return;
        }

        $methods = ['using_model_preference', 'usingModelPreference'];
        $has_magic_call = method_exists($builder, '__call');

        foreach ($methods as $method) {
            if (!self::builderHas($builder, $method) && !$has_magic_call) {
                continue;
            }

            try {
                $builder->$method(...$preferences);
                return;
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function get_vision_model_preferences(): array {
        if (!function_exists('\WordPress\AI\get_preferred_vision_models')) {
            return [];
        }

        $preferences = \WordPress\AI\get_preferred_vision_models();

        return is_array($preferences) ? $preferences : [];
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

        $system_instruction = isset($options[self::OPTIONS_SYSTEM_INSTRUCTION_KEY])
            ? trim((string) $options[self::OPTIONS_SYSTEM_INSTRUCTION_KEY])
            : '';
        unset($options[self::OPTIONS_SYSTEM_INSTRUCTION_KEY]);

        $temperature = isset($options['temperature']) ? (float) $options['temperature'] : null;
        $max_tokens = isset($options['max_tokens']) ? (int) $options['max_tokens'] : null;
        $request_timeout = isset($options['request_timeout']) ? (int) $options['request_timeout'] : null;

        $log_scope = null;
        if ($log_context !== null && CoreAIRequestLogging::is_active()) {
            $log_scope = CoreAIRequestLogging::begin($log_context);
        }

        $planned_request_meta = [];

        try {
            $builder = wp_ai_client_prompt($prompt_text);

            if ($system_instruction !== '') {
                self::builderUsingSystemInstruction($builder, $system_instruction);
            }

            if ($temperature !== null) {
                self::builderUsingTemperature($builder, $temperature);
            }
            if ($max_tokens !== null) {
                self::builderUsingMaxTokens($builder, $max_tokens);
            }
            if ($request_timeout !== null && $request_timeout > 0) {
                self::builderUsingRequestTimeout($builder, (float) $request_timeout);
            }

            $has_image_input = false;
            foreach ($files as $file) {
                if (empty($file['data']) || empty($file['mime'])) {
                    continue;
                }
                self::add_file_to_builder($builder, $file['data'], $file['mime']);
                if (str_starts_with((string) $file['mime'], 'image/')) {
                    $has_image_input = true;
                }
            }

            if (!$has_image_input) {
                self::bind_verified_text_generation_model($builder);
            }

            if ($has_image_input) {
                self::apply_vision_model_preferences($builder);
            }

            // Model/provider the SDK will use for the upcoming HTTP request (before generate).
            $planned_request_meta = self::extract_planned_request_meta_from_builder(
                $builder,
                $has_image_input
            );

            $started_at = microtime(true);

            // Use result object (we need usage + metadata).
            $result = self::builderGenerateTextResult($builder);

            if (is_wp_error($result)) {
                return self::enrich_wp_error_request_meta($result, $planned_request_meta);
            }

            $raw_text = self::extract_result_text($result);
            $response_meta = self::finalize_request_meta(
                self::extract_request_meta_from_result($result),
                $raw_text,
                $started_at
            );
            $request_meta = self::merge_request_meta_prefer_overlay(
                $planned_request_meta,
                $response_meta
            );

            if ($raw_text === '') {
                return new \WP_Error(
                    'empty_response',
                    __('Empty response from AI.', 'tainacan-ai'),
                    AnalysisErrorDebug::data(
                        array(
                            'response_length' => '0',
                        ),
                        502,
                        $request_meta
                    )
                );
            }

            $metadata = self::parse_json_response($raw_text);
            if ($metadata === null) {
                return new \WP_Error(
                    'json_parse_error',
                    __('AI returned invalid JSON.', 'tainacan-ai'),
                    AnalysisErrorDebug::data(
                        self::collect_json_parse_debug($raw_text),
                        502,
                        $request_meta
                    )
                );
            }

            return [
                'metadata' => $metadata,
                'usage' => array(
                    'prompt_tokens' => (int) ( $request_meta['prompt_tokens'] ?? 0 ),
                    'completion_tokens' => (int) ( $request_meta['completion_tokens'] ?? 0 ),
                    'total_tokens' => (int) ( $request_meta['tokens_used'] ?? 0 ),
                ),
                'model' => (string) ( $request_meta['model_used'] ?? '' ),
                'provider' => (string) ( $request_meta['provider_used'] ?? '' ),
                'request_meta' => $request_meta,
            ];
        } catch (\Throwable $e) {
            return self::enrich_wp_error_request_meta(
                AnalysisErrorDebug::from_throwable(
                    $e,
                    'ai_generation_error',
                    null,
                    500
                ),
                $planned_request_meta
            );
        } finally {
            if ($log_scope !== null) {
                $log_scope->release();
            }
        }
    }

    private static function builderHas(object $builder, string $method): bool {
        return is_object($builder) && method_exists($builder, $method);
    }

    private static function builderUsingSystemInstruction(object $builder, string $system_instruction): void {
        if (self::builderHas($builder, 'using_system_instruction')) {
            $builder->using_system_instruction($system_instruction);
            return;
        }
        if (self::builderHas($builder, 'usingSystemInstruction')) {
            $builder->usingSystemInstruction($system_instruction);
        }
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

    /**
     * HTTP timeout for the connector API request (overrides Core default, usually 30s).
     */
    private static function builderUsingRequestTimeout(object $builder, float $timeout_seconds): void {
        if (!class_exists(\WordPress\AiClient\Providers\Http\DTO\RequestOptions::class)) {
            return;
        }

        try {
            $request_options = \WordPress\AiClient\Providers\Http\DTO\RequestOptions::fromArray([
                \WordPress\AiClient\Providers\Http\DTO\RequestOptions::KEY_TIMEOUT => $timeout_seconds,
            ]);
        } catch (\Throwable $e) {
            return;
        }

        $methods = ['using_request_options', 'usingRequestOptions'];
        $has_magic_call = method_exists($builder, '__call');

        foreach ($methods as $method) {
            if (!self::builderHas($builder, $method) && !$has_magic_call) {
                continue;
            }

            try {
                $builder->$method($request_options);
                return;
            } catch (\Throwable $e) {
                continue;
            }
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
            DebugLog::log('[CoreAI] No generate text method found. Methods: ' . wp_json_encode(array_slice($methods, 0, 50)));
        }

        return new \WP_Error(
            'core_ai_no_generate_text_result',
            __('Core builder does not expose a supported text-generation method.', 'tainacan-ai')
        );
    }

    /**
     * Bind a verified text-capable model to avoid false positives from provider metadata.
     *
     * Some providers may report text-generation support in metadata but return a runtime
     * model object that is not a TextGenerationModelInterface instance.
     */
    private static function bind_verified_text_generation_model(object $builder): void {
        $methods = ['using_model', 'usingModel'];
        $has_magic_call = method_exists($builder, '__call');
        $has_supported_method = false;

        foreach ($methods as $method) {
            if (self::builderHas($builder, $method) || $has_magic_call) {
                $has_supported_method = true;
                break;
            }
        }

        if (!$has_supported_method) {
            return;
        }

        $model = self::resolve_verified_text_generation_model();
        if ($model === null) {
            return;
        }

        foreach ($methods as $method) {
            if (!self::builderHas($builder, $method) && !$has_magic_call) {
                continue;
            }

            try {
                $builder->$method($model);
                return;
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    /**
     * Resolve a model that is verifiably text-capable at runtime.
     */
    private static function resolve_verified_text_generation_model(): ?object {
        if (
            !class_exists(\WordPress\AiClient\AiClient::class)
            || !class_exists(\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::class)
            || !class_exists(\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::class)
            || !interface_exists(\WordPress\AiClient\Providers\Models\Contracts\TextGenerationModelInterface::class)
        ) {
            return null;
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();
            $requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
                [
                    \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
                ],
                []
            );

            $connector_ids = self::get_active_ai_connector_ids();
            foreach ($connector_ids as $connector_id) {
                $models = $registry->findProviderModelsMetadataForSupport($connector_id, $requirements);
                foreach ($models as $model_meta) {
                    try {
                        $model = $registry->getProviderModel($connector_id, $model_meta->getId());
                        if ($model instanceof \WordPress\AiClient\Providers\Models\Contracts\TextGenerationModelInterface) {
                            return $model;
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
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
     * Resolve provider/model from the prompt builder state (same selection the SDK will use).
     *
     * @return array{provider_used?: string, provider_name?: string, model_used?: string, model_name?: string}
     */
    private static function extract_planned_request_meta_from_builder(
        object $builder,
        bool $prefer_vision
    ): array {
        $from_builder = self::extract_request_meta_from_prompt_builder($builder);
        if ($from_builder !== []) {
            return $from_builder;
        }

        return self::resolve_configured_request_meta($prefer_vision);
    }

    /**
     * @return array{provider_used?: string, provider_name?: string, model_used?: string, model_name?: string}
     */
    private static function extract_request_meta_from_prompt_builder(object $builder): array {
        $inner = self::unwrap_wp_ai_prompt_builder($builder);
        if ($inner === null) {
            return [];
        }

        if (!class_exists(\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::class)) {
            return [];
        }

        try {
            $capability = \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration();
            $reflection = new \ReflectionClass($inner);
            if (!$reflection->hasMethod('getConfiguredModel')) {
                return [];
            }

            $method = $reflection->getMethod('getConfiguredModel');
            $method->setAccessible(true);
            $model = $method->invoke($inner, $capability);

            if (!is_object($model)) {
                return [];
            }

            return self::extract_request_meta_from_model($model);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function unwrap_wp_ai_prompt_builder(object $builder): ?object {
        if (class_exists('\WP_AI_Client_Prompt_Builder', false)
            && $builder instanceof \WP_AI_Client_Prompt_Builder
        ) {
            try {
                $reflection = new \ReflectionClass($builder);
                if (!$reflection->hasProperty('builder')) {
                    return null;
                }

                $property = $reflection->getProperty('builder');
                $property->setAccessible(true);
                $inner = $property->getValue($builder);

                return is_object($inner) ? $inner : null;
            } catch (\ReflectionException $e) {
                return null;
            }
        }

        if ($builder instanceof \WordPress\AiClient\Builders\PromptBuilder) {
            return $builder;
        }

        return null;
    }

    /**
     * @return array{provider_used?: string, provider_name?: string, model_used?: string, model_name?: string}
     */
    private static function extract_request_meta_from_model(object $model): array {
        if (!method_exists($model, 'metadata') || !method_exists($model, 'providerMetadata')) {
            return [];
        }

        try {
            $model_meta = $model->metadata();
            $provider_meta = $model->providerMetadata();

            return self::filter_configured_request_meta(
                array(
                    'model_used' => self::maybe_get_meta_id($model_meta),
                    'model_name' => self::maybe_get_meta_name($model_meta),
                    'provider_used' => self::maybe_get_meta_id($provider_meta),
                    'provider_name' => self::maybe_get_meta_name($provider_meta),
                )
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Catalog / vision-preference fallback when the builder cannot be introspected.
     *
     * @return array{provider_used?: string, provider_name?: string, model_used?: string, model_name?: string}
     */
    private static function resolve_configured_request_meta(bool $prefer_vision): array {
        if ($prefer_vision) {
            $preferences = self::get_vision_model_preferences();
            if ($preferences !== []) {
                $first = $preferences[0];
                if (is_array($first) && isset($first[0], $first[1])) {
                    return self::filter_configured_request_meta(
                        array(
                            'provider_used' => (string) $first[0],
                            'model_used' => (string) $first[1],
                        )
                    );
                }
            }
        }

        return self::resolve_text_catalog_request_meta();
    }

    /**
     * @return array{provider_used?: string, provider_name?: string, model_used?: string, model_name?: string}
     */
    private static function resolve_text_catalog_request_meta(): array {
        if (
            !class_exists(\WordPress\AiClient\AiClient::class)
            || !class_exists(\WordPress\AiClient\Providers\Models\DTO\ModelRequirements::class)
            || !class_exists(\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::class)
            || !interface_exists(\WordPress\AiClient\Providers\Models\Contracts\TextGenerationModelInterface::class)
        ) {
            return [];
        }

        try {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();
            $requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
                [
                    \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration(),
                ],
                []
            );

            $connector_ids = self::get_active_ai_connector_ids();
            foreach ($connector_ids as $connector_id) {
                $models = $registry->findProviderModelsMetadataForSupport($connector_id, $requirements);
                foreach ($models as $model_meta) {
                    try {
                        $model = $registry->getProviderModel($connector_id, $model_meta->getId());
                        if ($model instanceof \WordPress\AiClient\Providers\Models\Contracts\TextGenerationModelInterface) {
                            return self::filter_configured_request_meta(
                                array(
                                    'provider_used' => (string) $connector_id,
                                    'provider_name' => self::resolve_connector_display_name($connector_id),
                                    'model_used' => (string) $model_meta->getId(),
                                    'model_name' => self::maybe_get_meta_name($model_meta),
                                )
                            );
                        }
                    } catch (\Throwable $e) {
                        continue;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /**
     * @param array<string, string> $meta
     * @return array{provider_used?: string, provider_name?: string, model_used?: string, model_name?: string}
     */
    private static function filter_configured_request_meta(array $meta): array {
        $filtered = array();

        foreach (array('provider_used', 'provider_name', 'model_used', 'model_name') as $key) {
            if (!isset($meta[$key])) {
                continue;
            }

            $value = trim((string) $meta[$key]);
            if ($value !== '') {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Fill empty identity fields in $primary from $fallback (used for error enrichment).
     *
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private static function merge_request_meta(array $primary, array $fallback): array {
        if ($fallback === []) {
            return $primary;
        }

        $merged = $primary;
        foreach (array('provider_used', 'provider_name', 'model_used', 'model_name') as $key) {
            $current = isset($merged[$key]) ? trim((string) $merged[$key]) : '';
            if ($current === '' && isset($fallback[$key]) && trim((string) $fallback[$key]) !== '') {
                $merged[$key] = (string) $fallback[$key];
            }
        }

        return $merged;
    }

    /**
     * Start from planned request metadata; overlay non-empty values from the API result.
     *
     * @param array<string, mixed> $planned
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private static function merge_request_meta_prefer_overlay(array $planned, array $response): array {
        if ($response === []) {
            return $planned;
        }

        if ($planned === []) {
            return $response;
        }

        $merged = array_merge($planned, $response);

        foreach (array('provider_used', 'provider_name', 'model_used', 'model_name', 'finish_reason', 'analysis_mode') as $key) {
            $overlay = isset($response[$key]) ? trim((string) $response[$key]) : '';
            if ($overlay !== '') {
                $merged[$key] = $overlay;
            }
        }

        foreach (array('tokens_used', 'prompt_tokens', 'completion_tokens', 'request_characters', 'response_characters', 'duration_ms') as $key) {
            if (!isset($response[$key])) {
                continue;
            }

            $value = (int) $response[$key];
            if ($value > 0) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private static function enrich_wp_error_request_meta(\WP_Error $error, array $configured_meta): \WP_Error {
        if ($configured_meta === []) {
            return $error;
        }

        $data = $error->get_error_data();
        if (!is_array($data)) {
            $data = array();
        }

        $existing = isset($data['request_meta']) && is_array($data['request_meta'])
            ? $data['request_meta']
            : array();

        $merged = self::merge_request_meta($existing, $configured_meta);
        $normalized = AnalysisErrorDebug::normalize_request_meta($merged);
        if ($normalized !== null) {
            $data['request_meta'] = $normalized;
        }

        return new \WP_Error(
            (string) $error->get_error_code(),
            $error->get_error_message(),
            $data
        );
    }

    private static function resolve_connector_display_name(string $connector_id): string {
        if (function_exists('\WordPress\AI\get_ai_connectors')) {
            $connectors = \WordPress\AI\get_ai_connectors();
            if (is_array($connectors) && isset($connectors[$connector_id]['name'])) {
                return (string) $connectors[$connector_id]['name'];
            }
        }

        return $connector_id;
    }

    /**
     * Tokens, model, provider, and finish reason from a Core AI text result.
     *
     * @return array{
     *     tokens_used: int,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     model_used: string,
     *     model_name: string,
     *     provider_used: string,
     *     provider_name: string,
     *     finish_reason: string
     * }
     */
    private static function extract_request_meta_from_result(object $result): array {
        $usage_obj = method_exists($result, 'getTokenUsage') ? $result->getTokenUsage() : null;
        $usage = self::extract_usage_array($usage_obj);

        $provider = '';
        $provider_name = '';
        $model = '';
        $model_name = '';

        $provider_meta = method_exists($result, 'getProviderMetadata') ? $result->getProviderMetadata() : null;
        if ($provider_meta) {
            $provider = self::maybe_get_meta_id($provider_meta);
            $provider_name = self::maybe_get_meta_name($provider_meta);
        }

        $model_meta = method_exists($result, 'getModelMetadata') ? $result->getModelMetadata() : null;
        if ($model_meta) {
            $model = self::maybe_get_meta_id($model_meta);
            $model_name = self::maybe_get_meta_name($model_meta);
        }

        return [
            'tokens_used' => (int) ($usage['total_tokens'] ?? 0),
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'model_used' => $model,
            'model_name' => $model_name,
            'provider_used' => $provider,
            'provider_name' => $provider_name,
            'finish_reason' => self::extract_finish_reason($result),
        ];
    }

    /**
     * @param array<string, mixed> $request_meta
     * @return array<string, mixed>
     */
    private static function finalize_request_meta(array $request_meta, string $raw_text, float $started_at): array {
        $duration_ms = (int) round((microtime(true) - $started_at) * 1000);
        if ($duration_ms > 0) {
            $request_meta['duration_ms'] = $duration_ms;
        }

        if ($raw_text !== '') {
            $response_characters = mb_strlen($raw_text, 'UTF-8');
            if ($response_characters > 0) {
                $request_meta['response_characters'] = $response_characters;
            }
        }

        return $request_meta;
    }

    private static function extract_finish_reason(object $result): string {
        if (!method_exists($result, 'getCandidates')) {
            return '';
        }

        try {
            $candidates = $result->getCandidates();
            if (!is_array($candidates) || $candidates === []) {
                return '';
            }

            $candidate = $candidates[0];
            if (!is_object($candidate) || !method_exists($candidate, 'getFinishReason')) {
                return '';
            }

            $reason = $candidate->getFinishReason();
            if ($reason instanceof \BackedEnum) {
                return (string) $reason->value;
            }

            if (is_object($reason)) {
                if (property_exists($reason, 'value')) {
                    $value = $reason->value;
                    return is_scalar($value) ? (string) $value : '';
                }
                if (method_exists($reason, 'getValue')) {
                    return (string) $reason->getValue();
                }
            }

            return is_scalar($reason) ? (string) $reason : '';
        } catch (\Throwable $e) {
            return '';
        }
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

    private static function maybe_get_meta_name(object $meta): string {
        if (method_exists($meta, 'getName')) {
            return (string) $meta->getName();
        }
        if (method_exists($meta, 'get_name')) {
            return (string) $meta->get_name();
        }

        if (property_exists($meta, 'name')) {
            $name = $meta->name;
            return is_scalar($name) ? (string) $name : '';
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
            DebugLog::log(
                '[CoreAI] File attach failed. ' .
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
        foreach (self::json_response_candidates($content) as $candidate) {
            $metadata = self::decode_json_response($candidate);
            if ($metadata !== null) {
                return $metadata;
            }
        }

        return null;
    }

    /**
     * Candidate JSON payloads extracted from a model response.
     *
     * @return list<string>
     */
    private static function json_response_candidates(string $content): array {
        $candidates = array($content);

        if (preg_match('/\{[\s\S]*\}/', $content, $matches)) {
            $candidates[] = $matches[0];
        }

        $stripped = preg_replace('/```json?\s*/', '', $content);
        $stripped = preg_replace('/```\s*/', '', (string) $stripped);
        $stripped = trim((string) $stripped);

        if ($stripped !== $content) {
            $candidates[] = $stripped;

            if (preg_match('/\{[\s\S]*\}/', $stripped, $matches)) {
                $candidates[] = $matches[0];
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode_json_response(string $content): ?array {
        $metadata = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($metadata)) {
            return $metadata;
        }

        $repaired = self::repair_json_string_literals($content);
        if ($repaired === $content) {
            return null;
        }

        $metadata = json_decode($repaired, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($metadata)) {
            return $metadata;
        }

        return null;
    }

    /**
     * Escapes raw control characters inside JSON string literals.
     *
     * Smaller local models often copy tabs/newlines from source text into values
     * without JSON escaping, which makes json_decode() fail.
     */
    private static function repair_json_string_literals(string $json): string {
        $out       = '';
        $length    = strlen($json);
        $in_string = false;
        $escape    = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            $ord  = ord($char);

            if ($escape) {
                $out    .= $char;
                $escape  = false;
                continue;
            }

            if ($in_string && $char === '\\') {
                $out    .= $char;
                $escape  = true;
                continue;
            }

            if ($char === '"') {
                $in_string = ! $in_string;
                $out      .= $char;
                continue;
            }

            if ($in_string && $ord < 0x20) {
                if ($char === "\t") {
                    $out .= '\\t';
                } elseif ($char === "\n") {
                    $out .= '\\n';
                } elseif ($char === "\r") {
                    $out .= '\\r';
                } else {
                    $out .= sprintf('\\u%04x', $ord);
                }
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Debug context when parse_json_response() fails (advanced debug only in REST).
     *
     * @return array<string, string|array{label: string, content: string, truncated: bool}>
     */
    private static function collect_json_parse_debug(string $content): array {
        $last_json_error = '';

        $record_attempt = static function (string $text) use (&$last_json_error): void {
            json_decode($text, true);
            $last_json_error = json_last_error_msg();
        };

        foreach (self::json_response_candidates($content) as $candidate) {
            $record_attempt($candidate);

            $repaired = self::repair_json_string_literals($candidate);
            if ($repaired !== $candidate) {
                $record_attempt($repaired);
            }
        }

        $truncated = AnalysisErrorDebug::truncate($content);

        return array(
            'raw_response'    => array(
                'label'     => AnalysisErrorDebug::label_for('raw_response'),
                'content'   => $truncated['content'],
                'truncated' => $truncated['truncated'],
            ),
            'json_error'      => $last_json_error !== '' ? $last_json_error : __('Unknown JSON error.', 'tainacan-ai'),
            'response_length' => (string) $truncated['length'],
        );
    }
}


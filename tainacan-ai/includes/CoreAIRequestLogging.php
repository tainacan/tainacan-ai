<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enriches WordPress AI request logs with Tainacan analysis context.
 *
 * Requires WordPress AI 1.0+ and the "AI Request Logging" feature enabled
 * under Settings → AI (Tools → AI Request Logs).
 */
final class CoreAIRequestLogging {

    public const OPTIONS_CONTEXT_KEY = '_tainacan_ai_log_context';

    private const EXPERIMENT_CLASS = 'WordPress\AI\Experiments\AI_Request_Logging\AI_Request_Logging';

    /**
     * Pending contexts for in-flight HTTP requests (LIFO).
     *
     * @var array<int, array<string, mixed>>
     */
    private static array $pending_stack = [];

    private static bool $filter_registered = false;

    /**
     * Whether Core AI client and logging hooks exist on this site.
     */
    public static function is_available(): bool {
        if (!function_exists('wp_ai_client_prompt')) {
            return false;
        }

        return class_exists('WordPress\AI\Logging\Log_Data_Extractor');
    }

    /**
     * Whether the AI Request Logging experiment is enabled.
     */
    public static function is_experiment_enabled(): bool {
        if (!class_exists(self::EXPERIMENT_CLASS)) {
            return false;
        }

        try {
            $class = self::EXPERIMENT_CLASS;
            $experiment = new $class();
            return $experiment->is_enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether request-log enrichment should run for this request.
     */
    public static function is_active(): bool {
        return self::is_available() && self::is_experiment_enabled();
    }

    /**
     * Register the log-context filter after WordPress AI initializes.
     */
    public static function register_integration(): void {
        if (self::$filter_registered || !self::is_active()) {
            return;
        }

        add_filter('wpai_request_log_context', [self::class, 'filter_log_context'], 10, 3);
        self::$filter_registered = true;
    }

    /**
     * Attach Tainacan context to Core AI generation options.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function options_with_context(array $context, array $options = []): array {
        if (!self::is_active() || empty($context)) {
            return $options;
        }

        $options[self::OPTIONS_CONTEXT_KEY] = $context;
        return $options;
    }

    /**
     * Begin tracking context for the next Core AI HTTP response.
     *
     * @param array<string, mixed> $context
     */
    public static function begin(array $context): RequestLogScope {
        return new RequestLogScope($context);
    }

    /**
     * @internal
     * @param array<string, mixed> $context
     */
    public static function push(array $context): void {
        self::$pending_stack[] = $context;
    }

    /**
     * @internal
     */
    public static function discard_one(): void {
        if (self::$pending_stack !== []) {
            array_pop(self::$pending_stack);
        }
    }

    /**
     * @internal
     * @return array<string, mixed>|null
     */
    public static function consume_pending(): ?array {
        if (self::$pending_stack === []) {
            return null;
        }

        return array_pop(self::$pending_stack);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $decoded
     * @param array<string, mixed> $log_data
     * @return array<string, mixed>
     */
    public static function filter_log_context(array $context, array $decoded, array $log_data): array {
        $pending = self::consume_pending();
        if ($pending === null) {
            return $context;
        }

        $context['tainacan_ai'] = $pending;

        return $context;
    }
}

/**
 * Tracks one in-flight log context entry (released if no HTTP log is written).
 */
final class RequestLogScope {

    private bool $released = false;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(array $context) {
        CoreAIRequestLogging::push($context);
    }

    public function release(): void {
        if ($this->released) {
            return;
        }

        CoreAIRequestLogging::discard_one();
        $this->released = true;
    }
}

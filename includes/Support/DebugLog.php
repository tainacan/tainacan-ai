<?php
namespace Tainacan\AI\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP_DEBUG-only messages to the PHP error log.
 */
final class DebugLog {

    public static function log(string $message): void {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug channel when WP_DEBUG is on.
        error_log('[TainacanAI] ' . $message);
    }
}

<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitized reads of admin-ajax POST values.
 */
final class RequestInput {

    /**
     * Read a JSON object sent as a POST string (e.g. JSON.stringify from admin JS).
     *
     * @param string               $key     POST field name.
     * @param array<string, mixed> $default Returned when the field is missing or invalid.
     * @return array<string, mixed>
     */
    public static function json_post_array(string $key, array $default = []): array {
        $raw = filter_input(INPUT_POST, $key, FILTER_DEFAULT);

        if (is_array($raw)) {
            return wp_unslash($raw);
        }

        if (!is_string($raw) || '' === $raw) {
            return $default;
        }

        $decoded = json_decode(wp_unslash($raw), true);

        return is_array($decoded) ? $decoded : $default;
    }
}

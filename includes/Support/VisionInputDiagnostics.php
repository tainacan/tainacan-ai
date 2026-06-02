<?php
declare(strict_types=1);

namespace Tainacan\AI\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects when image input likely reached a text-only model or was dropped by the connector.
 *
 * Uses connector model catalog metadata and prompt token counts — not natural-language heuristics.
 */
final class VisionInputDiagnostics {

    /**
     * With image attachments, legitimate multimodal prompts are typically much larger than this.
     */
    private const LOW_PROMPT_TOKEN_THRESHOLD = 250;

    /**
     * @param array<string, mixed> $request_meta
     */
    public static function assess_image_request_response(
        array $request_meta,
        string $raw_text,
        int $image_attachment_count
    ): ?\WP_Error {
        unset($raw_text);

        if ($image_attachment_count <= 0) {
            return null;
        }

        $connector_used = trim((string) ($request_meta['provider_used'] ?? ''));
        $model_used = trim((string) ($request_meta['model_used'] ?? ''));
        $prompt_tokens = (int) ($request_meta['prompt_tokens'] ?? 0);

        if (self::likely_dropped_images($prompt_tokens, $image_attachment_count)) {
            return self::build_error(
                'vision_images_not_forwarded',
                self::images_not_forwarded_message(),
                $request_meta,
                array(
                    'image_attachments' => (string) $image_attachment_count,
                    'detection' => 'low_prompt_tokens',
                )
            );
        }

        $supports_image = CoreAI::connector_model_supports_image_input($connector_used, $model_used);

        if ($supports_image === false) {
            return self::build_error(
                'vision_text_model_refusal',
                self::text_only_model_message(),
                $request_meta,
                array(
                    'image_attachments' => (string) $image_attachment_count,
                    'detection' => 'catalog_no_image_input',
                )
            );
        }

        return null;
    }

    public static function likely_dropped_images(int $prompt_tokens, int $image_attachment_count): bool {
        if ($image_attachment_count <= 0) {
            return false;
        }

        return $prompt_tokens > 0 && $prompt_tokens < self::LOW_PROMPT_TOKEN_THRESHOLD;
    }

    private static function text_only_model_message(): string {
        return __(
            'Images were sent, but the model in use is not cataloged as accepting image input. Choose a vision-capable model in WordPress Connectors (Settings → Connectors → AI).',
            'tainacan-ai'
        );
    }

    private static function images_not_forwarded_message(): string {
        return __(
            'Images were attached, but the reported prompt size is too small for a multimodal request — the connector likely did not forward image data to the model.',
            'tainacan-ai'
        );
    }

    /**
     * @param array<string, mixed> $request_meta
     * @param array<string, string> $debug_fields
     */
    private static function build_error(
        string $code,
        string $message,
        array $request_meta,
        array $debug_fields
    ): \WP_Error {
        return new \WP_Error(
            $code,
            $message,
            AnalysisErrorDebug::data($debug_fields, 502, $request_meta)
        );
    }
}

<?php
namespace Tainacan\AI\Hooks;

use Tainacan\AI\Extraction\ExtractionMetadata;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-metadatum toggle for AI extraction on the metadata edition form.
 */
class MetadatumFormHook {

    public function __construct() {
        add_action('tainacan-register-admin-hooks', [$this, 'register_hook']);
        add_action('tainacan-insert-tainacan-metadatum', [$this, 'save_data']);
        add_filter('tainacan-api-response-metadatum-meta', [$this, 'add_meta_to_response'], 10, 2);
    }

    public function register_hook(): void {
        if (!function_exists('tainacan_register_admin_hook')) {
            return;
        }

        tainacan_register_admin_hook(
            'metadatum',
            [$this, 'render_form'],
            'end-left'
        );
    }

    /**
     * @param array<string> $extra_meta
     * @return array<string>
     */
    public function add_meta_to_response(array $extra_meta, $request): array {
        $extra_meta[] = ExtractionMetadata::meta_key();

        return $extra_meta;
    }

    /**
     * @param \Tainacan\Entities\Metadatum $metadatum
     */
    public function save_data($metadatum): void {
        if (!function_exists('tainacan_get_api_postdata')) {
            return;
        }

        $post = tainacan_get_api_postdata();

        if (!$metadatum->can_edit()) {
            return;
        }

        $id = (int) $metadatum->get_id();
        $meta_key = ExtractionMetadata::meta_key();

        // Checkbox omitted when unchecked (default: include in extraction).
        $exclude = isset($post->{$meta_key}) && rest_sanitize_boolean($post->{$meta_key});

        if ($exclude) {
            update_post_meta($id, $meta_key, '1');
        } else {
            delete_post_meta($id, $meta_key);
        }
    }

    public function render_form(): string {
        $meta_key = ExtractionMetadata::meta_key();

        ob_start();
        ?>
        <div class="field tainacan-collection--section-header">
            <h4><?php esc_html_e('Tainacan AI', 'tainacan-ai'); ?></h4>
            <hr>
        </div>
        <div class="field tainacan-ai-metadatum-exclude">
            <label class="b-checkbox checkbox">
                <input
                    type="checkbox"
                    id="<?php echo esc_attr($meta_key); ?>"
                    name="<?php echo esc_attr($meta_key); ?>"
                    value="1"
                />
                <span class="check"></span>
                <span class="control-label">
                    <?php esc_html_e('Exclude from AI extraction', 'tainacan-ai'); ?>
                </span>
            </label>
            <p class="help">
                <?php
                esc_html_e(
                    'All metadata is included in analysis by default. Check this box to omit the field from prompts and fill actions.',
                    'tainacan-ai'
                );
                ?>
            </p>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

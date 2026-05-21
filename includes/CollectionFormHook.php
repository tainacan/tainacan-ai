<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collection edition form: per-collection AI prompts via Admin Form Hooks.
 *
 * @see https://tainacan.github.io/tainacan-wiki/dev/admin-form-hooks.html
 */
class CollectionFormHook {

    public function __construct() {
        add_action('tainacan-register-admin-hooks', [$this, 'register_hook']);
        add_action('tainacan-insert-tainacan-collection', [$this, 'save_data']);
        add_filter('tainacan-api-response-collection-meta', [$this, 'add_meta_to_response'], 10, 2);
    }

    public function register_hook(): void {
        if (!function_exists('tainacan_register_admin_hook')) {
            return;
        }

        tainacan_register_admin_hook(
            'collection',
            [$this, 'render_form'],
            'end-right'
        );
    }

    /**
     * Expose prompt meta keys on the collection REST object (populates hook fields on edit).
     *
     * @param array<string> $extra_meta
     * @return array<string>
     */
    public function add_meta_to_response(array $extra_meta, $request): array {
        $extra_meta[] = CollectionPrompts::meta_key_text();

        return $extra_meta;
    }

    /**
     * @param \Tainacan\Entities\Collection $collection
     */
    public function save_data($collection): void {
        if (!function_exists('tainacan_get_api_postdata')) {
            return;
        }

        $post = tainacan_get_api_postdata();

        if (!$collection->can_edit()) {
            return;
        }

        $collection_id = (int) $collection->get_id();
        $text_key = CollectionPrompts::meta_key_text();

        if (!isset($post->{$text_key})) {
            return;
        }

        $prompt_text = wp_kses_post((string) $post->{$text_key});

        if ($prompt_text === '') {
            delete_post_meta($collection_id, $text_key);
            return;
        }

        update_post_meta($collection_id, $text_key, $prompt_text);
    }

    public function render_form(): string {
        if (!function_exists('tainacan_get_api_postdata')) {
            return '';
        }

        $ai_tools_url = admin_url('admin.php?page=tainacan_ai');

        ob_start();
        ?>
        <div class="field tainacan-collection--section-header">
            <h4><?php esc_html_e('Tainacan AI prompt', 'tainacan-ai'); ?></h4>
            <hr>
        </div>

        <p class="help">
            <?php
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: link to Tainacan AI settings page */
                    __('Override the site-wide prompt for this collection. Leave blank to use <a href="%s">Tainacan AI settings</a>. Field mapping is edited there.', 'tainacan-ai'),
                    esc_url($ai_tools_url)
                )
            );
            ?>
        </p>
        <div class="field">
            <label class="label" for="<?php echo esc_attr(CollectionPrompts::meta_key_text()); ?>">
                <?php esc_html_e('Analysis prompt', 'tainacan-ai'); ?>
            </label>
            <div class="control">
                <textarea
                    id="<?php echo esc_attr(CollectionPrompts::meta_key_text()); ?>"
                    class="textarea"
                    name="<?php echo esc_attr(CollectionPrompts::meta_key_text()); ?>"
                    rows="6"
                    placeholder="<?php echo esc_attr__('Leave empty to use the default prompt…', 'tainacan-ai'); ?>"
                ></textarea>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

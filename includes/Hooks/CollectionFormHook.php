<?php
namespace Tainacan\AI\Hooks;

use Tainacan\AI\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-collection prompt preambles: post meta, admin form, and REST exposure.
 *
 * @see https://tainacan.github.io/tainacan-wiki/dev/admin-form-hooks.html
 */
class CollectionFormHook {

    public const POST_TYPE = 'tainacan-collection';
    public const META_KEY_PREAMBLE = 'tainacan_ai_prompt_preamble';

    public function __construct() {
        add_action('init', [$this, 'register_collection_preamble_meta']);
        add_action('tainacan-register-admin-hooks', [$this, 'register_hook']);
        add_action('tainacan-insert-tainacan-collection', [$this, 'save_data']);
        add_filter('tainacan-api-response-collection-meta', [$this, 'add_meta_to_response'], 10, 2);
    }

    /**
     * Register post meta keys on the collection post type.
     */
    public function register_collection_preamble_meta(): void {
        $auth_callback = static function (): bool {
            return current_user_can('edit_posts');
        };

        register_post_meta(
            self::POST_TYPE,
            self::META_KEY_PREAMBLE,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => $auth_callback,
            ]
        );
    }

    public static function meta_key_preamble(): string {
        return self::META_KEY_PREAMBLE;
    }

    /**
     * Collection preamble when set, otherwise the site default from AI Tools.
     */
    public static function get_effective_preamble(int $collection_id): string {
        if ($collection_id > 0 && get_post_type($collection_id) === self::POST_TYPE) {
            $preamble = (string) get_post_meta($collection_id, self::META_KEY_PREAMBLE, true);

            if ($preamble !== '') {
                return $preamble;
            }
        }

        return (string) (Plugin::get_options()['default_preamble'] ?? '');
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
     * Expose preamble meta on the collection REST object (populates hook fields on edit).
     *
     * @param array<string> $extra_meta
     * @return array<string>
     */
    public function add_meta_to_response(array $extra_meta, $request): array {
        $extra_meta[] = self::meta_key_preamble();

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
        $meta_key = self::meta_key_preamble();

        if (!isset($post->{$meta_key})) {
            return;
        }

        $preamble = wp_kses_post((string) $post->{$meta_key});

        if ($preamble === '') {
            delete_post_meta($collection_id, $meta_key);
            return;
        }

        update_post_meta($collection_id, $meta_key, $preamble);
    }

    public function render_form(): string {
        if (!function_exists('tainacan_get_api_postdata')) {
            return '';
        }

        $ai_tools_url = admin_url('admin.php?page=tainacan_ai');
        $meta_key = self::meta_key_preamble();

        ob_start();
        ?>
        <div class="field tainacan-collection--section-header">
            <h4><?php esc_html_e('Tainacan AI preamble', 'tainacan-ai'); ?></h4>
            <hr>
        </div>

        <p class="help">
            <?php
            echo wp_kses_post(
                sprintf(
                    /* translators: %s: link to Tainacan AI settings page */
                    __('Override the site-wide preamble for this collection. Leave blank to use <a href="%s">Tainacan AI settings</a>. Enable extraction per metadata on each metadata edition form.', 'tainacan-ai'),
                    esc_url($ai_tools_url)
                )
            );
            ?>
        </p>
        <p class="help">
            <?php
            esc_html_e(
                'Write role, domain context, and extraction priorities here. The plugin appends task rules, global rules, metadata field blocks, evidence rules, and output keys automatically at analysis time.',
                'tainacan-ai'
            );
            ?>
        </p>
        <div class="field">
            <label class="label" for="<?php echo esc_attr($meta_key); ?>">
                <?php esc_html_e('Prompt preamble', 'tainacan-ai'); ?>
            </label>
            <div class="control">
                <textarea
                    id="<?php echo esc_attr($meta_key); ?>"
                    class="textarea"
                    name="<?php echo esc_attr($meta_key); ?>"
                    rows="6"
                    placeholder="<?php echo esc_attr__('Leave empty to use the site default preamble…', 'tainacan-ai'); ?>"
                ></textarea>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}

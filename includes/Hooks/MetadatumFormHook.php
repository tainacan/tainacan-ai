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
        add_filter('rest_post_dispatch', [$this, 'normalize_exclude_meta_for_form_hooks'], 10, 3);
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
        $exclude = self::read_request_boolean($post->{$meta_key} ?? null);

        if ($exclude) {
            update_post_meta($id, $meta_key, ExtractionMetadata::exclude_flag_storage_value());
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

    /**
     * Legacy scalar "1" post meta → ["1"] on edit REST responses (checkbox form hooks expect arrays).
     *
     * @param mixed $response
     */
    public function normalize_exclude_meta_for_form_hooks($response, $server, $request) {
        unset($server);

        if (!$response instanceof \WP_REST_Response || !($request instanceof \WP_REST_Request)) {
            return $response;
        }

        if ($request->get_param('context') !== 'edit') {
            return $response;
        }

        $route = (string) $request->get_route();
        if (!preg_match('#/tainacan/v2/collection/\d+/metadata#', $route)) {
            return $response;
        }

        $data = $response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $meta_key = ExtractionMetadata::meta_key();
        $this->normalize_exclude_meta_item_for_form($data, $meta_key);
        $response->set_data($data);

        return $response;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function normalize_exclude_meta_item_for_form(array &$item, string $meta_key): void {
        if (array_key_exists($meta_key, $item) && !is_array($item[$meta_key])) {
            $raw = $item[$meta_key];
            if ($raw === '' || $raw === false || $raw === null || $raw === '0' || $raw === 0) {
                unset($item[$meta_key]);
            } elseif (ExtractionMetadata::stored_value_is_exclude_flag($raw)) {
                $item[$meta_key] = ExtractionMetadata::exclude_flag_storage_value();
            }
        }

        $children = $item['metadata_type_options']['children_objects'] ?? null;
        if (!is_array($children)) {
            return;
        }

        foreach ($children as $index => $child) {
            if (!is_array($child)) {
                continue;
            }

            $this->normalize_exclude_meta_item_for_form($child, $meta_key);
            $children[$index] = $child;
        }

        $item['metadata_type_options']['children_objects'] = $children;
    }

    /**
     * REST/form hook checkboxes may send a scalar or an array of values.
     */
    private static function read_request_boolean(mixed $raw): bool {
        return ExtractionMetadata::stored_value_is_exclude_flag($raw);
    }
}

<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom prompts per collection (post meta on tainacan-collection posts).
 */
class CollectionPrompts {

    public const POST_TYPE = 'tainacan-collection';
    public const META_KEY_TEXT = 'tainacan_ai_prompt_text';

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action('init', [$this, 'register_collection_prompt_meta']);
        add_action('wp_ajax_tainacan_ai_get_collection_metadata', [$this, 'ajax_get_metadata']);
    }

    /**
     * Register post meta keys on the collection post type.
     */
    public function register_collection_prompt_meta(): void {
        $auth_callback = static function (): bool {
            return current_user_can('edit_posts');
        };

        register_post_meta(
            self::POST_TYPE,
            self::META_KEY_TEXT,
            [
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => $auth_callback,
            ]
        );
    }

    public static function meta_key_text(): string {
        return self::META_KEY_TEXT;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_prompt(int $collection_id): ?array {
        if (!$this->is_collection($collection_id)) {
            return null;
        }

        $prompt_text = (string) get_post_meta($collection_id, self::meta_key_text(), true);

        if ($prompt_text === '') {
            return null;
        }

        return [
            'collection_id' => $collection_id,
            'prompt_text' => $prompt_text,
            'is_active' => 1,
        ];
    }

    public function get_effective_prompt(int $collection_id): string {
        $custom_prompt = $this->get_prompt($collection_id);

        if ($custom_prompt && !empty($custom_prompt['prompt_text'])) {
            return $custom_prompt['prompt_text'];
        }

        $options = \Tainacan_AI::get_options();
        return (string) ($options['default_prompt'] ?? '');
    }

    public function get_collection_metadata(int $collection_id): array {
        if (!class_exists('\Tainacan\Repositories\Metadata')) {
            return [];
        }

        $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
        $collection_repo = \Tainacan\Repositories\Collections::get_instance();

        $collection = $collection_repo->fetch($collection_id);
        if (!$collection) {
            return [];
        }

        $metadata = $metadata_repo->fetch_by_collection($collection, [], 'OBJECT');

        $result = [];
        foreach ($metadata as $meta) {
            $result[] = [
                'id' => $meta->get_id(),
                'name' => $meta->get_name(),
                'slug' => $meta->get_slug(),
                'type' => $meta->get_metadata_type(),
                'description' => $meta->get_description(),
                'required' => $meta->get_required() === 'yes',
                'multiple' => $meta->get_multiple() === 'yes',
            ];
        }

        return $result;
    }

    public function ajax_get_metadata(): void {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint(wp_unslash($_POST['collection_id'] ?? 0));

        if (!$collection_id) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        $metadata = $this->get_collection_metadata($collection_id);

        wp_send_json_success(['metadata' => $metadata]);
    }

    private function is_collection(int $collection_id): bool {
        return $collection_id > 0 && get_post_type($collection_id) === self::POST_TYPE;
    }
}

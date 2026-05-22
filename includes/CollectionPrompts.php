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
     * Collection post meta when set, otherwise the site default from AI Tools.
     */
    public function get_effective_prompt(int $collection_id): string {
        if ($this->is_collection($collection_id)) {
            $prompt_text = (string) get_post_meta($collection_id, self::META_KEY_TEXT, true);

            if ($prompt_text !== '') {
                return $prompt_text;
            }
        }

        return (string) (\Tainacan_AI::get_options()['default_prompt'] ?? '');
    }

    private function is_collection(int $collection_id): bool {
        return $collection_id > 0 && get_post_type($collection_id) === self::POST_TYPE;
    }
}

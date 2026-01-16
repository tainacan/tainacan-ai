<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom prompts manager per collection
 *
 * Allows each Tainacan collection to have its own prompts
 * for image and document analysis, with metadata mapping.
 */
class CollectionPrompts {

    private string $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'tainacan_ai_collection_prompts';

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // AJAX handlers
        add_action('wp_ajax_tainacan_ai_get_collection_prompt', [$this, 'ajax_get_prompt']);
        add_action('wp_ajax_tainacan_ai_save_collection_prompt', [$this, 'ajax_save_prompt']);
        add_action('wp_ajax_tainacan_ai_delete_collection_prompt', [$this, 'ajax_delete_prompt']);
        add_action('wp_ajax_tainacan_ai_get_collection_metadata', [$this, 'ajax_get_metadata']);
        add_action('wp_ajax_tainacan_ai_generate_prompt_suggestion', [$this, 'ajax_generate_suggestion']);
    }

    /**
     * Get prompt for a collection
     */
    public function get_prompt(int $collection_id, string $type = 'image'): ?array {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name}
                WHERE collection_id = %d AND prompt_type = %s AND is_active = 1",
                $collection_id,
                $type
            ),
            ARRAY_A
        );

        if ($result && !empty($result['metadata_mapping'])) {
            $result['metadata_mapping'] = json_decode($result['metadata_mapping'], true);
        }

        return $result;
    }

    /**
     * Get effective prompt (from collection or default)
     */
    public function get_effective_prompt(int $collection_id, string $type = 'image'): string {
        $custom_prompt = $this->get_prompt($collection_id, $type);

        if ($custom_prompt && !empty($custom_prompt['prompt_text'])) {
            return $custom_prompt['prompt_text'];
        }

        // Return default prompt
        $options = \Tainacan_AI::get_options();

        return $type === 'image'
            ? ($options['default_image_prompt'] ?? '')
            : ($options['default_document_prompt'] ?? '');
    }

    /**
     * Get metadata mapping
     */
    public function get_metadata_mapping(int $collection_id, string $type = 'image'): array {
        $prompt_data = $this->get_prompt($collection_id, $type);

        return $prompt_data['metadata_mapping'] ?? [];
    }

    /**
     * Save prompt for a collection
     */
    public function save_prompt(int $collection_id, string $type, string $prompt_text, array $metadata_mapping = []): bool {
        global $wpdb;

        $existing = $this->get_prompt($collection_id, $type);

        $data = [
            'collection_id' => $collection_id,
            'prompt_type' => $type,
            'prompt_text' => $prompt_text,
            'metadata_mapping' => json_encode($metadata_mapping),
            'is_active' => 1,
        ];

        if ($existing) {
            $result = $wpdb->update(
                $this->table_name,
                $data,
                ['id' => $existing['id']],
                ['%d', '%s', '%s', '%s', '%d'],
                ['%d']
            );
        } else {
            $result = $wpdb->insert(
                $this->table_name,
                $data,
                ['%d', '%s', '%s', '%s', '%d']
            );
        }

        return $result !== false;
    }

    /**
     * Remove prompt from a collection
     */
    public function delete_prompt(int $collection_id, string $type): bool {
        global $wpdb;

        return $wpdb->delete(
            $this->table_name,
            [
                'collection_id' => $collection_id,
                'prompt_type' => $type,
            ],
            ['%d', '%s']
        ) !== false;
    }

    /**
     * List all collections with custom prompts
     */
    public function get_collections_with_prompts(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT DISTINCT collection_id FROM {$this->table_name} WHERE is_active = 1",
            ARRAY_A
        );
    }

    /**
     * Get metadata from a Tainacan collection
     */
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

    /**
     * Generate prompt suggestion based on collection metadata
     */
    public function generate_prompt_suggestion(int $collection_id, string $type = 'image'): string {
        $metadata = $this->get_collection_metadata($collection_id);

        if (empty($metadata)) {
            return $type === 'image'
                ? \Tainacan_AI::get_option('default_image_prompt', '')
                : \Tainacan_AI::get_option('default_document_prompt', '');
        }

        // Build field list
        $fields_json = [];
        $fields_descriptions = [];

        foreach ($metadata as $meta) {
            $field_type = $meta['multiple'] ? 'array' : 'string';
            $fields_json[$meta['slug']] = $meta['multiple'] ? ['exemplo1', 'exemplo2'] : 'valor';
            $fields_descriptions[] = sprintf(
                '- **%s** (%s): %s%s',
                $meta['name'],
                $meta['slug'],
                $meta['description'] ?: 'Sem descrição',
                $meta['required'] ? ' *[Obrigatório]*' : ''
            );
        }

        $fields_list = implode("\n", $fields_descriptions);
        $json_example = json_encode($fields_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($type === 'image') {
            return <<<PROMPT
Você é um especialista em catalogação de acervos. Analise esta imagem e extraia metadados para os campos específicos desta coleção.

## Campos da Coleção:
{$fields_list}

## Instruções:
1. Analise cuidadosamente a imagem
2. Preencha todos os campos relevantes
3. Para campos não identificáveis, use null
4. Seja preciso e objetivo nas descrições

## Retorne um JSON com esta estrutura:
{$json_example}

Responda APENAS com o JSON válido, sem texto adicional.
PROMPT;
        } else {
            return <<<PROMPT
Você é um especialista em análise documental. Analise este documento e extraia metadados para os campos específicos desta coleção.

## Campos da Coleção:
{$fields_list}

## Instruções:
1. Leia e compreenda o documento
2. Extraia informações para cada campo
3. Para campos não encontrados, use null
4. Seja preciso nas citações e referências

## Retorne um JSON com esta estrutura:
{$json_example}

Responda APENAS com o JSON válido, sem texto adicional.
PROMPT;
        }
    }

    /**
     * AJAX: Get prompt
     */
    public function ajax_get_prompt(): void {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'image');

        if (!$collection_id) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        $prompt = $this->get_prompt($collection_id, $type);
        $effective_prompt = $this->get_effective_prompt($collection_id, $type);

        wp_send_json_success([
            'custom_prompt' => $prompt,
            'effective_prompt' => $effective_prompt,
            'is_custom' => !empty($prompt),
        ]);
    }

    /**
     * AJAX: Save prompt
     */
    public function ajax_save_prompt(): void {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'image');
        $prompt_text = wp_kses_post($_POST['prompt_text'] ?? '');
        $metadata_mapping = json_decode(stripslashes($_POST['metadata_mapping'] ?? '[]'), true);

        if (!$collection_id) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        if (empty($prompt_text)) {
            // Remove custom prompt, revert to default
            $this->delete_prompt($collection_id, $type);
            wp_send_json_success(__('Prompt reset to default.', 'tainacan-ai'));
            return; // Important: return after success
        }

        // Check if table exists
        $this->maybe_create_table();

        if ($this->save_prompt($collection_id, $type, $prompt_text, $metadata_mapping ?: [])) {
            wp_send_json_success(__('Prompt saved successfully!', 'tainacan-ai'));
        } else {
            global $wpdb;
            $error_msg = $wpdb->last_error ?: __('Unknown error saving prompt.', 'tainacan-ai');
            wp_send_json_error($error_msg);
        }
    }

    /**
     * Create table if it doesn't exist
     */
    private function maybe_create_table(): void {
        global $wpdb;

        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $this->table_name
            )
        );

        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                collection_id bigint(20) unsigned NOT NULL,
                prompt_type varchar(20) NOT NULL DEFAULT 'image',
                prompt_text text NOT NULL,
                metadata_mapping text DEFAULT NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY collection_prompt (collection_id, prompt_type),
                KEY is_active (is_active)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }

    /**
     * AJAX: Remove prompt
     */
    public function ajax_delete_prompt(): void {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'image');

        if ($this->delete_prompt($collection_id, $type)) {
            wp_send_json_success(__('Prompt removed successfully.', 'tainacan-ai'));
        } else {
            wp_send_json_error(__('Error removing prompt.', 'tainacan-ai'));
        }
    }

    /**
     * AJAX: Get collection metadata
     */
    public function ajax_get_metadata(): void {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);

        if (!$collection_id) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        $metadata = $this->get_collection_metadata($collection_id);

        wp_send_json_success(['metadata' => $metadata]);
    }

    /**
     * AJAX: Generate prompt suggestion
     */
    public function ajax_generate_suggestion(): void {
        check_ajax_referer('tainacan_ai_admin_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $collection_id = absint($_POST['collection_id'] ?? 0);
        $type = sanitize_text_field($_POST['type'] ?? 'image');

        if (!$collection_id) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        $suggestion = $this->generate_prompt_suggestion($collection_id, $type);

        wp_send_json_success(['suggestion' => $suggestion]);
    }
}

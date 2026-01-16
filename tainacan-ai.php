<?php
/**
 * Plugin Name: Tainacan AI
 * Plugin URI: https://github.com/tainacan/tainacan-ai
 * Description: Automated metadata extraction in Tainacan using AI (OpenAI, Gemini, DeepSeek). Supports image analysis, PDF documents, EXIF extraction and custom prompts per collection.
 * Version: 0.0.1
 * Author: Sigismundo
 * Author URI: https://seu-site.com
 * Text Domain: tainacan-ai
 * Domain Path: /languages
 * License: GPL v2 or later
 * Requires Plugins: tainacan
 * Requires at least: 6.5
 * Tested up to: 6.9
 * Requires PHP: 8.0
 *
 * @package Tainacan_AI
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TAINACAN_AI_VERSION', '0.0.1');
define('TAINACAN_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAINACAN_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TAINACAN_AI_DOMAIN', 'tainacan-ai');
define('TAINACAN_AI_DB_VERSION', '0.0.1');

/**
 * Plugin autoloader
 */
spl_autoload_register(function ($class) {
    $prefix = 'Tainacan\\AI\\';
    $base_dir = TAINACAN_AI_PLUGIN_DIR . 'includes/';
    $lib_dir = TAINACAN_AI_PLUGIN_DIR . 'lib/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);

    // First try in includes/ directory
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
        return;
    }

    // Then try in lib/ directory (embedded libraries)
    $file = $lib_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
        return;
    }
});

// Load Composer autoloader if it exists
if (file_exists(TAINACAN_AI_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once TAINACAN_AI_PLUGIN_DIR . 'vendor/autoload.php';
}

/**
 * Main plugin class
 */
final class Tainacan_AI {

    private static ?Tainacan_AI $instance = null;

    /**
     * Singleton
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'init'], 20);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // WP Consent API integration
        $this->init_consent_api();
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                esc_html__('Tainacan AI requires PHP 8.0 or higher.', 'tainacan-ai'),
                esc_html__('Activation Error', 'tainacan-ai'),
                ['back_link' => true]
            );
        }

        $this->create_tables();
        $this->set_default_options();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        global $wpdb;

        // Clear transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tainacan_ai_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tainacan_ai_%'");

        flush_rewrite_rules();
    }

    /**
     * Create plugin tables
     */
    private function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Usage logs table
        $table_logs = $wpdb->prefix . 'tainacan_ai_logs';
        $sql_logs = "CREATE TABLE IF NOT EXISTS $table_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            item_id bigint(20) unsigned DEFAULT NULL,
            collection_id bigint(20) unsigned DEFAULT NULL,
            attachment_id bigint(20) unsigned NOT NULL,
            document_type varchar(50) NOT NULL,
            model varchar(50) NOT NULL,
            tokens_used int(11) DEFAULT 0,
            cost decimal(10,6) DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'success',
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY item_id (item_id),
            KEY collection_id (collection_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Collection prompts table
        $table_prompts = $wpdb->prefix . 'tainacan_ai_collection_prompts';
        $sql_prompts = "CREATE TABLE IF NOT EXISTS $table_prompts (
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
        dbDelta($sql_logs);
        dbDelta($sql_prompts);

        update_option('tainacan_ai_db_version', TAINACAN_AI_DB_VERSION);
    }

    /**
     * Set default options
     */
    private function set_default_options(): void {
        $default_options = [
            // AI Provider
            'ai_provider' => 'openai',

            // OpenAI
            'api_key' => '',
            'model' => 'gpt-4o',

            // Google Gemini
            'gemini_api_key' => '',
            'gemini_model' => 'gemini-1.5-pro',

            // DeepSeek
            'deepseek_api_key' => '',
            'deepseek_model' => 'deepseek-chat',

            // Ollama (Local)
            'ollama_url' => 'http://localhost:11434',
            'ollama_model' => 'llama3.2',

            // General settings
            'default_image_prompt' => $this->get_default_image_prompt(),
            'default_document_prompt' => $this->get_default_document_prompt(),
            'max_tokens' => 2000,
            'temperature' => 0.1,
            'request_timeout' => 120,
            'cache_duration' => 3600,
            'extract_exif' => true,
            'auto_map_metadata' => false,
            'consent_required' => true,
            'log_enabled' => true,
            'cost_tracking' => true,
        ];

        $existing = get_option('tainacan_ai_options', []);
        update_option('tainacan_ai_options', wp_parse_args($existing, $default_options));
    }

    /**
     * Default prompt for images
     */
    private function get_default_image_prompt(): string {
        return 'Você é um especialista em catalogação museológica e arquivística. Analise esta imagem e extraia metadados detalhados.' . "\n\n" .
'## Instruções:' . "\n" .
'1. Analise cuidadosamente todos os elementos visuais' . "\n" .
'2. Identifique técnicas artísticas, materiais e estilos' . "\n" .
'3. Estime períodos históricos quando possível' . "\n" .
'4. Descreva o estado de conservação visível' . "\n" .
'5. Para CADA campo, inclua a evidência de onde a informação foi extraída' . "\n\n" .
'## Retorne um JSON com os seguintes campos (cada campo deve ter "valor" e "evidencia"):' . "\n" .
'{' . "\n" .
'    "titulo": {' . "\n" .
'        "valor": "Título descritivo da obra/objeto",' . "\n" .
'        "evidencia": "Descrição de qual elemento visual ou texto levou a esta conclusão"' . "\n" .
'    },' . "\n" .
'    "autor": {' . "\n" .
'        "valor": "Nome do autor/criador (ou \'Desconhecido\')",' . "\n" .
'        "evidencia": "Assinatura visível, estilo característico, ou \'Não identificado na imagem\'"' . "\n" .
'    },' . "\n" .
'    "data_criacao": {' . "\n" .
'        "valor": "Data ou período estimado",' . "\n" .
'        "evidencia": "Elementos que indicam a época (estilo, materiais, técnica, inscrições)"' . "\n" .
'    },' . "\n" .
'    "tecnica": {' . "\n" .
'        "valor": "Técnica(s) utilizada(s)",' . "\n" .
'        "evidencia": "Características visuais que identificam a técnica"' . "\n" .
'    },' . "\n" .
'    "materiais": {' . "\n" .
'        "valor": ["lista", "de", "materiais"],' . "\n" .
'        "evidencia": "Texturas, cores e características que indicam os materiais"' . "\n" .
'    },' . "\n" .
'    "dimensoes_estimadas": {' . "\n" .
'        "valor": "Dimensões aproximadas",' . "\n" .
'        "evidencia": "Elementos de referência usados para estimar (objetos conhecidos, proporções)"' . "\n" .
'    },' . "\n" .
'    "estado_conservacao": {' . "\n" .
'        "valor": "Bom/Regular/Ruim - descrição",' . "\n" .
'        "evidencia": "Sinais visíveis de desgaste, danos ou boa preservação"' . "\n" .
'    },' . "\n" .
'    "descricao": {' . "\n" .
'        "valor": "Descrição visual detalhada",' . "\n" .
'        "evidencia": "Elementos principais observados na imagem"' . "\n" .
'    },' . "\n" .
'    "estilo_artistico": {' . "\n" .
'        "valor": "Estilo ou movimento artístico",' . "\n" .
'        "evidencia": "Características formais que indicam o estilo"' . "\n" .
'    },' . "\n" .
'    "palavras_chave": {' . "\n" .
'        "valor": ["palavras", "chave", "relevantes"],' . "\n" .
'        "evidencia": "Temas e elementos principais identificados"' . "\n" .
'    },' . "\n" .
'    "observacoes": {' . "\n" .
'        "valor": "Outras observações relevantes",' . "\n" .
'        "evidencia": "Detalhes adicionais notados"' . "\n" .
'    }' . "\n" .
'}' . "\n\n" .
'Responda APENAS com o JSON, sem texto adicional.';
    }

    /**
     * Default prompt for documents
     */
    private function get_default_document_prompt(): string {
        return 'Você é um especialista em análise documental e bibliográfica. Analise este documento e extraia metadados estruturados.' . "\n\n" .
'## Instruções:' . "\n" .
'1. Identifique o tipo de documento (artigo, relatório, tese, etc.)' . "\n" .
'2. Extraia informações bibliográficas completas' . "\n" .
'3. Identifique temas e áreas de conhecimento' . "\n" .
'4. Resuma o conteúdo principal' . "\n" .
'5. Para CADA campo, inclua a evidência de onde a informação foi extraída (página, seção, trecho do texto)' . "\n\n" .
'## Retorne um JSON com os seguintes campos (cada campo deve ter "valor" e "evidencia"):' . "\n" .
'{' . "\n" .
'    "titulo": {' . "\n" .
'        "valor": "Título do documento",' . "\n" .
'        "evidencia": "Local onde o título foi encontrado (capa, cabeçalho, página X)"' . "\n" .
'    },' . "\n" .
'    "autor": {' . "\n" .
'        "valor": ["Nome dos autores"],' . "\n" .
'        "evidencia": "Local onde os autores foram identificados (capa, página X, seção de autoria)"' . "\n" .
'    },' . "\n" .
'    "tipo_documento": {' . "\n" .
'        "valor": "Artigo/Relatório/Tese/Livro/etc",' . "\n" .
'        "evidencia": "Elementos que indicam o tipo (estrutura, formatação, declarações explícitas)"' . "\n" .
'    },' . "\n" .
'    "ano_publicacao": {' . "\n" .
'        "valor": "Ano",' . "\n" .
'        "evidencia": "Local onde a data foi encontrada"' . "\n" .
'    },' . "\n" .
'    "instituicao": {' . "\n" .
'        "valor": "Instituição relacionada",' . "\n" .
'        "evidencia": "Menções à instituição no documento"' . "\n" .
'    },' . "\n" .
'    "resumo": {' . "\n" .
'        "valor": "Resumo do conteúdo (máx. 500 caracteres)",' . "\n" .
'        "evidencia": "Seções principais que fundamentam o resumo"' . "\n" .
'    },' . "\n" .
'    "palavras_chave": {' . "\n" .
'        "valor": ["palavras", "chave"],' . "\n" .
'        "evidencia": "Seção de palavras-chave ou temas recorrentes identificados"' . "\n" .
'    },' . "\n" .
'    "area_conhecimento": {' . "\n" .
'        "valor": "Área principal",' . "\n" .
'        "evidencia": "Indicadores da área (terminologia, metodologia, referências)"' . "\n" .
'    },' . "\n" .
'    "idioma": {' . "\n" .
'        "valor": "Idioma do documento",' . "\n" .
'        "evidencia": "Idioma identificado no texto"' . "\n" .
'    },' . "\n" .
'    "referencias_principais": {' . "\n" .
'        "valor": ["Referências importantes citadas"],' . "\n" .
'        "evidencia": "Seção de referências ou citações no texto"' . "\n" .
'    },' . "\n" .
'    "metodologia": {' . "\n" .
'        "valor": "Metodologia utilizada (se aplicável)",' . "\n" .
'        "evidencia": "Seção de metodologia ou descrição dos métodos"' . "\n" .
'    },' . "\n" .
'    "observacoes": {' . "\n" .
'        "valor": "Outras observações",' . "\n" .
'        "evidencia": "Elementos adicionais notados no documento"' . "\n" .
'    }' . "\n" .
'}' . "\n\n" .
'Responda APENAS com o JSON, sem texto adicional.';
    }

    /**
     * WP Consent API integration
     */
    private function init_consent_api(): void {
        // Register plugin in Consent API
        $plugin = plugin_basename(__FILE__);
        add_filter("wp_consent_api_registered_{$plugin}", '__return_true');

        // Register cookie/data information
        add_action('wp_enqueue_scripts', function() {
            if (function_exists('wp_add_cookie_info')) {
                wp_add_cookie_info(
                    'tainacan_ai_cache',
                    __('AI analysis cache', 'tainacan-ai'),
                    'functional',
                    __('Stores analysis results to avoid repeated API calls', 'tainacan-ai'),
                    false,
                    false,
                    false
                );
            }
        });
    }

    /**
     * Check user consent
     */
    public static function has_consent(): bool {
        $options = get_option('tainacan_ai_options', []);

        // If consent is not required, return true
        if (empty($options['consent_required'])) {
            return true;
        }

        // Check via WP Consent API
        if (function_exists('wp_has_consent')) {
            return wp_has_consent('functional');
        }

        // Fallback: always allow for admins
        return current_user_can('manage_options');
    }

    /**
     * Initialize plugin components
     */
    public function init(): void {
        // Check if Tainacan is active
        if (!class_exists('\Tainacan\Repositories\Items')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('Tainacan AI requires the Tainacan plugin to be active.', 'tainacan-ai');
                echo '</p></div>';
            });
            return;
        }

        // Initialize admin page
        if (class_exists('\Tainacan\Pages')) {
            \Tainacan\AI\AdminPage::get_instance();
        }

        // Initialize components
        new \Tainacan\AI\API();
        new \Tainacan\AI\ItemFormHook();

        // Initialize collection prompts manager
        new \Tainacan\AI\CollectionPrompts();
    }

    /**
     * Settings link
     */
    public function add_settings_link(array $links): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('admin.php?page=tainacan_ai'),
            __('Settings', 'tainacan-ai')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Get plugin options
     */
    public static function get_options(): array {
        return get_option('tainacan_ai_options', []);
    }

    /**
     * Get a specific option
     */
    public static function get_option(string $key, mixed $default = null): mixed {
        $options = self::get_options();
        return $options[$key] ?? $default;
    }
}

// Initialize the plugin
Tainacan_AI::get_instance();

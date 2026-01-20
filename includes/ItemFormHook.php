<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integration with Tainacan item form
 *
 * Adds AI analysis button to item edit form,
 * allowing automatic metadata extraction.
 */
class ItemFormHook {

    public function __construct() {
        add_action('tainacan-register-admin-hooks', [$this, 'register_hook']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX endpoints
        add_action('wp_ajax_tainacan_ai_analyze', [$this, 'ajax_analyze']);
        add_action('wp_ajax_tainacan_ai_get_item_document', [$this, 'ajax_get_item_document']);
        add_action('wp_ajax_tainacan_ai_clear_item_cache', [$this, 'ajax_clear_item_cache']);
        add_action('wp_ajax_tainacan_ai_apply_metadata', [$this, 'ajax_apply_metadata']);
        add_action('wp_ajax_tainacan_ai_get_item_mapping', [$this, 'ajax_get_mapping']);
    }

    /**
     * Registra hook no formulário de item
     */
    public function register_hook(): void {
        if (function_exists('tainacan_register_admin_hook')) {
            tainacan_register_admin_hook(
                'item',
                [$this, 'render_form'],
                'begin-right'
            );
        }
    }

    /**
     * Renderiza o formulário com botão de análise
     */
    public function render_form(): string {
        if (!function_exists('tainacan_get_api_postdata')) {
            return '';
        }

        $options = \Tainacan_AI::get_options();
        $is_configured = !empty($options['api_key']);

        ob_start();
        ?>
        <div class="field tainacan-ai-section" id="tainacan-ai-widget">
            <div class="tainacan-ai-header">
                <div class="tainacan-ai-header-left">
                    <!-- <svg class="tainacan-ai-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                        <path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073zM13.26 22.43a4.476 4.476 0 0 1-2.876-1.04l.141-.081 4.779-2.758a.795.795 0 0 0 .392-.681v-6.737l2.02 1.168a.071.071 0 0 1 .038.052v5.583a4.504 4.504 0 0 1-4.494 4.494zM3.6 18.304a4.47 4.47 0 0 1-.535-3.014l.142.085 4.783 2.759a.771.771 0 0 0 .78 0l5.843-3.369v2.332a.08.08 0 0 1-.033.062L9.74 19.95a4.5 4.5 0 0 1-6.14-1.646zM2.34 7.896a4.485 4.485 0 0 1 2.366-1.973V11.6a.766.766 0 0 0 .388.676l5.815 3.355-2.02 1.168a.076.076 0 0 1-.071 0l-4.83-2.786A4.504 4.504 0 0 1 2.34 7.872zm16.597 3.855l-5.833-3.387L15.119 7.2a.076.076 0 0 1 .071 0l4.83 2.791a4.494 4.494 0 0 1-.676 8.105v-5.678a.79.79 0 0 0-.407-.667zm2.01-3.023l-.141-.085-4.774-2.782a.776.776 0 0 0-.785 0L9.409 9.23V6.897a.066.066 0 0 1 .028-.061l4.83-2.787a4.5 4.5 0 0 1 6.68 4.66zm-12.64 4.135l-2.02-1.164a.08.08 0 0 1-.038-.057V6.075a4.5 4.5 0 0 1 7.375-3.453l-.142.08L8.704 5.46a.795.795 0 0 0-.393.681zm1.097-2.365l2.602-1.5 2.607 1.5v2.999l-2.597 1.5-2.607-1.5z"/>
                    </svg> -->
                    <svg class="tainacan-ai-icon" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" id="svg5" width="32" height="32" version="1.1" viewBox="0 0 8.467 8.467">
                        <g id="layer1" transform="translate(-51.439 -147.782)"><path id="path11554" d="m58.994 153.057-.247.062-.349.082c.124.134.217.267.282.396.158.318.161.607.012.927v.002l-.005.007c-.172.37-.412.548-.824.616-.002 0-.004 0-.005.002-.074.012-.16.018-.257.018-.383 0-.864-.118-1.415-.372l-.009-.005a.534.534 0 0 0-.078-.033 4.111 4.111 0 0 1-.427-.191h-.004c-.016.064-.03.131-.05.21a3.34 3.34 0 0 1-.083.302l-.01.029c.144.07.273.124.38.164l.037.019c.608.282 1.165.426 1.658.426.122 0 .24-.007.352-.026a1.588 1.588 0 0 0 1.235-.927l.003-.007c.215-.46.212-.95-.014-1.405-.051-.102-.111-.2-.182-.297z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path11552" d="M57.188 148.868c-.079 0-.16.006-.241.017-.359.047-.732.2-1.112.455.028.116.055.228.077.311.095.025.226.055.36.087.266-.158.536-.28.748-.307.366-.05.646.046.91.31h.003v.002c.27.272.363.549.314.915a1.85 1.85 0 0 1-.213.62l.055.216c.01.04.017.061.026.091.03.01.053.016.094.027l.238.058c.19-.32.306-.634.346-.94a1.592 1.592 0 0 0-.467-1.375l-.004-.003a1.583 1.583 0 0 0-1.134-.484z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path1" d="M53.574 148.312a1.671 1.671 0 0 0-.67.161l-.006.003c-1.05.493-1.246 1.706-.527 3.248l.015.034c.122.323.356.82.783 1.372a5.33 5.33 0 0 0-.208 1.435v.148h.148c.285 0 .731-.031 1.282-.17-.036-.007-.067-.016-.108-.025a3.406 3.406 0 0 1-.301-.083.791.791 0 0 1-.16-.071.441.441 0 0 1-.222-.295h-.002c-.028-.15 0-.435.101-.79a.55.55 0 0 0-.096-.486 4.834 4.834 0 0 1-.717-1.266l-.016-.034c-.328-.704-.421-1.293-.356-1.697.066-.404.238-.642.618-.82l.004-.002c.168-.078.323-.117.476-.115v-.001c.153 0 .305.042.465.122.15.075.33.237.496.415l.03-.121c.033-.14.067-.281.11-.409l.036-.092v-.001a2.172 2.172 0 0 0-.424-.284 1.605 1.605 0 0 0-.75-.176z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><g id="path6974" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.07603 0 0 1.0728 -16.96 -11.535)"><path id="path10029" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6968" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.51152 0 0 1.50697 -44.11 -74.969)"><path id="path10038" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6976" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(.77239 0 0 .77006 3.277 37.782)"><path id="path10046" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g></g>
                    </svg>
                    <h4><?php esc_html_e('AI Metadata Extractor', 'tainacan-ai'); ?></h4>
                </div>
                <?php if ($is_configured): ?>
                    <span class="tainacan-ai-status-badge success">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Configured', 'tainacan-ai'); ?>
                    </span>
                <?php else: ?>
                    <span class="tainacan-ai-status-badge warning">
                        <span class="dashicons dashicons-warning"></span>
                        <?php esc_html_e('Not configured', 'tainacan-ai'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <hr class="tainacan-ai-divider">

            <?php if (!$is_configured): ?>
                <div class="tainacan-ai-notice warning">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: link to AI Tools settings page */
                                __('Configure your API key in <a href="%s">Tainacan > AI</a> to use automatic extraction.', 'tainacan-ai'),
                                esc_url(admin_url('admin.php?page=tainacan_ai'))
                            )
                        ); ?>
                    </p>
                </div>
            <?php else: ?>
                <p class="tainacan-ai-description">
                    <?php esc_html_e('Analyze this item\'s document with artificial intelligence to automatically extract metadata.', 'tainacan-ai'); ?>
                </p>

                <!-- Detected document info -->
                <div class="tainacan-ai-document-info" id="tainacan-ai-document-info" style="display: none;">
                    <span class="tainacan-ai-document-type" id="tainacan-ai-doc-type"></span>
                    <span class="tainacan-ai-document-name" id="tainacan-ai-doc-name"></span>
                </div>

                <!-- Action buttons -->
                <div class="tainacan-ai-actions">
                    <button type="button" class="button button-primary tainacan-ai-analyze-btn" id="tainacan-ai-analyze">
                        <span class="tainacan-ai-btn-icon">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                        </span>
                        <span class="tainacan-ai-btn-text"><?php esc_html_e('Analyze Document', 'tainacan-ai'); ?></span>
                    </button>

                    <button type="button" class="button tainacan-ai-refresh-btn" id="tainacan-ai-refresh" title="<?php esc_attr_e('Force new analysis (ignore cache)', 'tainacan-ai'); ?>">
                        <span class="dashicons dashicons-update"></span>
                    </button>
                </div>

                <!-- Status / Loading -->
                <div class="tainacan-ai-status" id="tainacan-ai-status" style="display: none;">
                    <div class="tainacan-ai-loading">
                        <div class="tainacan-ai-spinner"></div>
                        <div class="tainacan-ai-loading-text">
                            <span class="tainacan-ai-loading-title"><?php esc_html_e('Analyzing document...', 'tainacan-ai'); ?></span>
                            <span class="tainacan-ai-loading-subtitle"><?php esc_html_e('This may take a few seconds', 'tainacan-ai'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- EXIF data area (when available) -->
                <div class="tainacan-ai-results" id="tainacan-ai-results" style="display: none;">
                    <div class="tainacan-ai-tabs" id="tainacan-ai-tab-exif" style="display: none;">
                        <button type="button" class="tainacan-ai-tab active" data-tab="exif">
                            <span class="dashicons dashicons-camera"></span>
                            <?php esc_html_e('EXIF Data', 'tainacan-ai'); ?>
                        </button>
                    </div>
                    <div class="tainacan-ai-tab-content active" id="tainacan-ai-content-exif">
                        <div class="tainacan-ai-exif-content" id="tainacan-ai-exif-content"></div>
                    </div>
                    <!-- Hidden elements for compatibility -->
                    <span id="tainacan-ai-cache-badge" style="display: none;"></span>
                    <span id="tainacan-ai-model" style="display: none;"></span>
                    <span id="tainacan-ai-tokens" style="display: none;"></span>
                    <div id="tainacan-ai-results-content" style="display: none;"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }


    /**
     * Carrega assets
     */
    public function enqueue_assets(string $hook): void {
        // Verifica se está em páginas do Tainacan
        if (strpos($hook, 'tainacan') === false && strpos($hook, 'post.php') === false) {
            return;
        }

        $css_asset_file = TAINACAN_AI_PLUGIN_DIR . 'build/item-form-style.asset.php';
        $css_asset = file_exists($css_asset_file) ? require $css_asset_file : ['dependencies' => [], 'version' => TAINACAN_AI_VERSION];
        
        wp_enqueue_style(
            'tainacan-ai-item',
            TAINACAN_AI_PLUGIN_URL . 'build/item-form-style.css',
            $css_asset['dependencies'],
            $css_asset['version']
        );

        $js_asset_file = TAINACAN_AI_PLUGIN_DIR . 'build/item-form.asset.php';
        $js_asset = file_exists($js_asset_file) ? require $js_asset_file : ['dependencies' => [], 'version' => TAINACAN_AI_VERSION];
        
        wp_enqueue_script(
            'tainacan-ai-item',
            TAINACAN_AI_PLUGIN_URL . 'build/item-form.js',
            $js_asset['dependencies'],
            $js_asset['version'],
            true
        );

        $options = \Tainacan_AI::get_options();

        // Get metadata mapping for current collection
        $metadata_mapping = $this->get_metadata_mapping();

        wp_localize_script('tainacan-ai-item', 'TainacanAI', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('tainacan-ai/v1/'),
            'nonce' => wp_create_nonce('tainacan_ai_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'autoMapMetadata' => !empty($options['auto_map_metadata']),
            'metadataMapping' => $metadata_mapping,
            'texts' => [
                'analyzing' => __('Analyzing...', 'tainacan-ai'),
                'analyzeBtn' => __('Analyze Document', 'tainacan-ai'),
                'error' => __('Error analyzing. Please try again.', 'tainacan-ai'),
                'noDocument' => __('No document found in this item. Add an image or file first.', 'tainacan-ai'),
                'copy' => __('Copy', 'tainacan-ai'),
                'copied' => __('Copied!', 'tainacan-ai'),
                'copyAll' => __('Copy All', 'tainacan-ai'),
                'allCopied' => __('All copied!', 'tainacan-ai'),
                'apply' => __('Apply', 'tainacan-ai'),
                'applied' => __('Applied!', 'tainacan-ai'),
                'cacheCleared' => __('Cache cleared!', 'tainacan-ai'),
                'clearing' => __('Clearing...', 'tainacan-ai'),
                'tokens' => __('tokens', 'tainacan-ai'),
                'model' => __('Model', 'tainacan-ai'),
                'image' => __('Image', 'tainacan-ai'),
                'pdf' => __('PDF', 'tainacan-ai'),
                'text' => __('Text', 'tainacan-ai'),
                'detecting' => __('Detecting document...', 'tainacan-ai'),
                'analysisResults' => __('Analysis Results', 'tainacan-ai'),
                'newAnalysis' => __('New Analysis', 'tainacan-ai'),
                'evidence' => __('Evidence', 'tainacan-ai'),
                'fillAll' => __('Fill Fields', 'tainacan-ai'),
                'fillAllTooltip' => __('Automatically fills Tainacan fields with extracted values', 'tainacan-ai'),
                'fillField' => __('Fill field', 'tainacan-ai'),
                'fieldsFilled' => __('fields filled', 'tainacan-ai'),
                'noMappedFields' => __('No mapped fields found', 'tainacan-ai'),
                'noFieldsToFill' => __('No fields to fill', 'tainacan-ai'),
                'noResults' => __('No results available', 'tainacan-ai'),
                'noMapping' => __('Field not mapped', 'tainacan-ai'),
                'fieldNotFound' => __('Field not found on page', 'tainacan-ai'),
            ]
        ]);
    }

    /**
     * Get metadata mapping for automatic filling
     * Maps AI JSON fields to Tainacan fields
     */
    private function get_metadata_mapping(): array {
        // Try to get collection_id from URL or context
        $collection_id = $this->get_current_collection_id();

        if (!$collection_id || !class_exists('\Tainacan\Repositories\Metadata')) {
            return $this->get_default_mapping();
        }

        try {
            $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
            $collection_metadata = $metadata_repo->fetch_by_collection(
                new \Tainacan\Entities\Collection($collection_id),
                [],
                'OBJECT'
            );

            if (empty($collection_metadata)) {
                return $this->get_default_mapping();
            }

            $mapping = [];

            // Mapeamento automático baseado no slug/nome do metadado
            $ai_fields = [
                'titulo' => ['titulo', 'title', 'nome', 'name'],
                'autor' => ['autor', 'author', 'criador', 'creator', 'artista', 'artist', 'dccreator'],
                'data_criacao' => ['data', 'date', 'data_criacao', 'creation_date', 'ano', 'year', 'dcdate'],
                'tecnica' => ['tecnica', 'technique', 'technica'],
                'materiais' => ['materiais', 'materials', 'material'],
                'dimensoes_estimadas' => ['dimensoes', 'dimensions', 'tamanho', 'size'],
                'estado_conservacao' => ['conservacao', 'conservation', 'estado', 'condition'],
                'descricao' => ['descricao', 'description', 'desc'],
                'estilo_artistico' => ['estilo', 'style', 'movimento', 'movement'],
                'palavras_chave' => ['palavras_chave', 'keywords', 'tags', 'palavras-chave', 'assunto', 'dcsubject'],
                'observacoes' => ['observacoes', 'observations', 'notas', 'notes'],
                'tipo_documento' => ['tipo', 'type', 'tipo_documento', 'dctype'],
                'formato' => ['formato', 'format', 'dcformat'],
                'ano_publicacao' => ['ano_publicacao', 'publication_year', 'ano'],
                'instituicao' => ['instituicao', 'institution', 'organizacao', 'editor', 'editora', 'dcpublisher'],
                'resumo' => ['resumo', 'abstract', 'summary'],
                'area_conhecimento' => ['area', 'area_conhecimento', 'field', 'subject', 'abrangencia', 'dccoverage'],
                'idioma' => ['idioma', 'language', 'lingua', 'dclanguage'],
                'referencias_principais' => ['referencias', 'references', 'relacao', 'dcrelation'],
                'metodologia' => ['metodologia', 'methodology', 'metodo'],
                'identificador' => ['identificador', 'identifier', 'dcidentifier', 'id', 'codigo'],
                'direitos' => ['direitos', 'rights', 'dcrights', 'licenca', 'license', 'copyright'],
                'fonte' => ['fonte', 'source', 'dcsource', 'origem'],
                'contribuidor' => ['contribuidor', 'contributor', 'dccontributor', 'colaborador'],
            ];

            foreach ($collection_metadata as $metadata) {
                $slug = $metadata->get_slug();
                $name = strtolower($metadata->get_name());
                $id = $metadata->get_id();
                $type = $metadata->get_metadata_type();

                // Procura correspondência nos campos da IA
                foreach ($ai_fields as $ai_key => $possible_matches) {
                    foreach ($possible_matches as $match) {
                        if ($slug === $match || strpos($slug, $match) !== false ||
                            strpos($name, $match) !== false) {
                            $mapping[$ai_key] = [
                                'id' => $id,
                                'slug' => $slug,
                                'name' => $metadata->get_name(),
                                'type' => $type,
                            ];
                            break 2;
                        }
                    }
                }
            }

            return !empty($mapping) ? $mapping : $this->get_default_mapping();

        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TainacanAI] Error getting metadata mapping: ' . $e->getMessage());
            }
            return $this->get_default_mapping();
        }
    }

    /**
     * Default mapping when there's no specific collection
     */
    private function get_default_mapping(): array {
        return [
            'titulo' => ['id' => 'title', 'slug' => 'title', 'name' => 'Title', 'type' => 'text'],
            'descricao' => ['id' => 'description', 'slug' => 'description', 'name' => 'Description', 'type' => 'textarea'],
        ];
    }

    /**
     * Get current collection ID from context
     */
    private function get_current_collection_id(): ?int {
        // Via query string
        if (!empty($_GET['collection_id'])) {
            return absint($_GET['collection_id']);
        }

        // Via referrer (URL hash)
        $referer = wp_get_referer();
        if ($referer && preg_match('/collections\/(\d+)/', $referer, $matches)) {
            return (int) $matches[1];
        }

        // Via post being edited
        global $post;
        if ($post && class_exists('\Tainacan\Repositories\Items')) {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $item = $items_repo->fetch($post->ID);
            if ($item && method_exists($item, 'get_collection_id')) {
                return $item->get_collection_id();
            }
        }

        return null;
    }

    /**
     * AJAX: Analyze item document
     */
    public function ajax_analyze(): void {
        try {
            check_ajax_referer('tainacan_ai_nonce', 'nonce');

            if (!current_user_can('edit_posts')) {
                wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
            }

            $item_id = absint($_POST['item_id'] ?? 0);
            $attachment_id = absint($_POST['attachment_id'] ?? 0);
            $collection_id = absint($_POST['collection_id'] ?? 0);
            $force_refresh = filter_var($_POST['force_refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if (empty($item_id) && empty($attachment_id)) {
                wp_send_json_error(__('Item or attachment ID not provided.', 'tainacan-ai'));
            }

            // Get document from item if no attachment_id
            if (empty($attachment_id) && !empty($item_id)) {
                $document_data = $this->get_item_document($item_id);
                if (!$document_data) {
                    wp_send_json_error(__('No document found in this item.', 'tainacan-ai'));
                }
                $attachment_id = $document_data['id'];
            }

            // Get collection_id from item if not provided
            if (empty($collection_id) && !empty($item_id)) {
                $collection_id = $this->get_item_collection_id($item_id);
            }

            // Check cache
            $cache_key = 'tainacan_ai_' . $attachment_id;
            if (!$force_refresh) {
                $cached = get_transient($cache_key);
                if ($cached !== false) {
                    wp_send_json_success([
                        'result' => $cached,
                        'from_cache' => true,
                    ]);
                }
            }

            // Analisa documento
            $analyzer = new DocumentAnalyzer();
            $analyzer->set_context($collection_id, $item_id);
            $result = $analyzer->analyze($attachment_id);

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }

            // Salva no cache
            $options = \Tainacan_AI::get_options();
            $cache_duration = $options['cache_duration'] ?? 3600;
            if ($cache_duration > 0) {
                set_transient($cache_key, $result, $cache_duration);
            }

            wp_send_json_success([
                'result' => $result,
                'from_cache' => false,
            ]);
        } catch (\Throwable $e) {
            // Catch any error/exception and return friendly message
            $error_message = $e->getMessage();
            $error_file = basename($e->getFile());
            $error_line = $e->getLine();

            // Detailed log for debug
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[TainacanAI] Error in ajax_analyze: {$error_message} in {$error_file}:{$error_line}");
                error_log("[TainacanAI] Stack trace: " . $e->getTraceAsString());
            }

            wp_send_json_error(
                /* translators: %s: error message */
                sprintf(__('Error analyzing document: %s', 'tainacan-ai'), $error_message)
            );
        }
    }

    /**
     * AJAX: Obtém documento do item
     */
    public function ajax_get_item_document(): void {
        check_ajax_referer('tainacan_ai_nonce', 'nonce');

        $item_id = absint($_POST['item_id'] ?? 0);

        if (empty($item_id)) {
            wp_send_json_error(__('Item ID not provided.', 'tainacan-ai'));
        }

        $document = $this->get_item_document($item_id);

        if ($document) {
            wp_send_json_success($document);
        } else {
            wp_send_json_error(__('Document not found.', 'tainacan-ai'));
        }
    }

    /**
     * AJAX: Limpa cache do item
     */
    public function ajax_clear_item_cache(): void {
        check_ajax_referer('tainacan_ai_nonce', 'nonce');

        $attachment_id = absint($_POST['attachment_id'] ?? 0);

        if (empty($attachment_id)) {
            wp_send_json_error(__('Attachment ID not provided.', 'tainacan-ai'));
        }

        delete_transient('tainacan_ai_' . $attachment_id);

        wp_send_json_success(__('Cache cleared!', 'tainacan-ai'));
    }

    /**
     * AJAX: Aplica metadados ao item
     */
    public function ajax_apply_metadata(): void {
        check_ajax_referer('tainacan_ai_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Permission denied.', 'tainacan-ai'));
        }

        $item_id = absint($_POST['item_id'] ?? 0);
        $metadata_id = absint($_POST['metadata_id'] ?? 0);
        $value = sanitize_text_field($_POST['value'] ?? '');

        if (empty($item_id) || empty($metadata_id)) {
            wp_send_json_error(__('Insufficient data.', 'tainacan-ai'));
        }

        if (!class_exists('\Tainacan\Repositories\Item_Metadata')) {
            wp_send_json_error(__('Tainacan not found.', 'tainacan-ai'));
        }

        try {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
            $item_metadata_repo = \Tainacan\Repositories\Item_Metadata::get_instance();

            $item = $items_repo->fetch($item_id);
            $metadata = $metadata_repo->fetch($metadata_id);

            if (!$item || !$metadata) {
                wp_send_json_error(__('Item or metadata not found.', 'tainacan-ai'));
            }

            $item_metadata = new \Tainacan\Entities\Item_Metadata_Entity($item, $metadata);
            $item_metadata->set_value($value);

            if ($item_metadata->validate()) {
                $item_metadata_repo->insert($item_metadata);
                wp_send_json_success(__('Metadata applied successfully!', 'tainacan-ai'));
            } else {
                wp_send_json_error(__('Invalid value for this metadata.', 'tainacan-ai'));
            }
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Obtém documento de um item Tainacan
     */
    private function get_item_document(int $item_id): ?array {
        // Método 1: Via API do Tainacan
        if (class_exists('\Tainacan\Repositories\Items')) {
            $items_repo = \Tainacan\Repositories\Items::get_instance();
            $item = $items_repo->fetch($item_id);

            if ($item) {
                // Main document
                $document_type = $item->get_document_type();
                $document = $item->get_document();

                if ($document_type === 'attachment' && !empty($document) && is_numeric($document)) {
                    return $this->get_attachment_info((int) $document);
                }

                // Document URL
                if ($document_type === 'url' && !empty($document)) {
                    // Try to find attachment by URL
                    $attachment_id = attachment_url_to_postid($document);
                    if ($attachment_id) {
                        return $this->get_attachment_info($attachment_id);
                    }
                }
            }
        }

        // Method 2: Via post_meta
        $document_id = get_post_meta($item_id, 'document', true);
        if (!empty($document_id) && is_numeric($document_id)) {
            return $this->get_attachment_info((int) $document_id);
        }

        // Method 3: Via thumbnail
        $thumbnail_id = get_post_thumbnail_id($item_id);
        if ($thumbnail_id) {
            return $this->get_attachment_info((int) $thumbnail_id);
        }

        // Method 4: First attachment of the post
        $attachments = get_posts([
            'post_type' => 'attachment',
            'posts_per_page' => 1,
            'post_parent' => $item_id,
            'post_status' => 'inherit',
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);

        if (!empty($attachments)) {
            return $this->get_attachment_info($attachments[0]->ID);
        }

        return null;
    }

    /**
     * Get attachment information
     */
    private function get_attachment_info(int $attachment_id): array {
        $mime_type = get_post_mime_type($attachment_id);
        $title = get_the_title($attachment_id);
        $url = wp_get_attachment_url($attachment_id);

        // Determine type
        $type = 'unknown';
        if (strpos($mime_type, 'image/') === 0) {
            $type = 'image';
        } elseif ($mime_type === 'application/pdf') {
            $type = 'pdf';
        } elseif (strpos($mime_type, 'text/') === 0) {
            $type = 'text';
        }

        // Thumbnail for images
        $thumbnail = null;
        if ($type === 'image') {
            $thumb = wp_get_attachment_image_src($attachment_id, 'thumbnail');
            $thumbnail = $thumb ? $thumb[0] : null;
        }

        return [
            'id' => $attachment_id,
            'title' => $title,
            'url' => $url,
            'mime_type' => $mime_type,
            'type' => $type,
            'thumbnail' => $thumbnail,
        ];
    }

    /**
     * Get collection ID of an item
     */
    private function get_item_collection_id(int $item_id): ?int {
        if (!class_exists('\Tainacan\Repositories\Items')) {
            return null;
        }

        $items_repo = \Tainacan\Repositories\Items::get_instance();
        $item = $items_repo->fetch($item_id);

        if ($item && method_exists($item, 'get_collection_id')) {
            return $item->get_collection_id();
        }

        return null;
    }

    /**
     * AJAX: Get metadata mapping for a collection
     */
    public function ajax_get_mapping(): void {
        check_ajax_referer('tainacan_ai_nonce', 'nonce');

        $collection_id = absint($_POST['collection_id'] ?? 0);

        if (empty($collection_id)) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        $mapping = $this->get_metadata_mapping_for_collection($collection_id);
        wp_send_json_success($mapping);
    }

    /**
     * Get metadata mapping for a specific collection
     * First checks if there's custom mapping saved in admin,
     * then tries to detect automatically
     */
    public function get_metadata_mapping_for_collection(int $collection_id): array {
        // First, check if there's custom mapping saved in admin
        $custom_mapping = get_option('tainacan_ai_mapping_' . $collection_id, []);

        if (!empty($custom_mapping)) {
            // Convert custom mapping to format expected by JS
            $mapping = [];
            foreach ($custom_mapping as $ai_field => $data) {
                if (!empty($data['metadata_id'])) {
                    // Get additional metadata information
                    $metadata_info = $this->get_metadata_info($data['metadata_id']);
                    $mapping[$ai_field] = [
                        'id' => $data['metadata_id'],
                        'slug' => $metadata_info['slug'] ?? $ai_field,
                        'name' => $data['metadata_name'] ?? $ai_field,
                        'type' => $metadata_info['type'] ?? 'text',
                    ];
                }
            }
            if (!empty($mapping)) {
                return $mapping;
            }
        }

        // If no custom mapping, try to detect automatically
        if (!class_exists('\Tainacan\Repositories\Metadata')) {
            return $this->get_default_mapping();
        }

        try {
            $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
            $collection_metadata = $metadata_repo->fetch_by_collection(
                new \Tainacan\Entities\Collection($collection_id),
                [],
                'OBJECT'
            );

            if (empty($collection_metadata)) {
                return $this->get_default_mapping();
            }

            $mapping = [];

            // Automatic mapping based on metadata slug/name
            $ai_fields = [
                'titulo' => ['titulo', 'title', 'nome', 'name'],
                'autor' => ['autor', 'author', 'criador', 'creator', 'artista', 'artist', 'dccreator'],
                'data_criacao' => ['data', 'date', 'data_criacao', 'creation_date', 'ano', 'year', 'dcdate'],
                'tecnica' => ['tecnica', 'technique', 'technica'],
                'materiais' => ['materiais', 'materials', 'material'],
                'dimensoes_estimadas' => ['dimensoes', 'dimensions', 'tamanho', 'size'],
                'estado_conservacao' => ['conservacao', 'conservation', 'estado', 'condition'],
                'descricao' => ['descricao', 'description', 'desc'],
                'estilo_artistico' => ['estilo', 'style', 'movimento', 'movement'],
                'palavras_chave' => ['palavras_chave', 'keywords', 'tags', 'palavras-chave', 'assunto', 'dcsubject'],
                'observacoes' => ['observacoes', 'observations', 'notas', 'notes'],
                'tipo_documento' => ['tipo', 'type', 'tipo_documento', 'dctype'],
                'formato' => ['formato', 'format', 'dcformat'],
                'ano_publicacao' => ['ano_publicacao', 'publication_year', 'ano'],
                'instituicao' => ['instituicao', 'institution', 'organizacao', 'editor', 'editora', 'dcpublisher'],
                'resumo' => ['resumo', 'abstract', 'summary'],
                'area_conhecimento' => ['area', 'area_conhecimento', 'field', 'subject', 'abrangencia', 'dccoverage'],
                'idioma' => ['idioma', 'language', 'lingua', 'dclanguage'],
                'referencias_principais' => ['referencias', 'references', 'relacao', 'dcrelation'],
                'metodologia' => ['metodologia', 'methodology', 'metodo'],
                'identificador' => ['identificador', 'identifier', 'dcidentifier', 'id', 'codigo'],
                'direitos' => ['direitos', 'rights', 'dcrights', 'licenca', 'license', 'copyright'],
                'fonte' => ['fonte', 'source', 'dcsource', 'origem'],
                'contribuidor' => ['contribuidor', 'contributor', 'dccontributor', 'colaborador'],
            ];

            foreach ($collection_metadata as $metadata) {
                $slug = $metadata->get_slug();
                $name = strtolower($metadata->get_name());
                $id = $metadata->get_id();
                $type = $metadata->get_metadata_type();

                // Search for match in AI fields
                foreach ($ai_fields as $ai_key => $possible_matches) {
                    foreach ($possible_matches as $match) {
                        if ($slug === $match || strpos($slug, $match) !== false ||
                            strpos($name, $match) !== false) {
                            $mapping[$ai_key] = [
                                'id' => $id,
                                'slug' => $slug,
                                'name' => $metadata->get_name(),
                                'type' => $type,
                            ];
                            break 2;
                        }
                    }
                }
            }

            return !empty($mapping) ? $mapping : $this->get_default_mapping();

        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TainacanAI] Error getting metadata mapping: ' . $e->getMessage());
            }
            return $this->get_default_mapping();
        }
    }

    /**
     * Get information about a specific metadata by ID
     */
    private function get_metadata_info(int $metadata_id): array {
        if (!class_exists('\Tainacan\Repositories\Metadata')) {
            return [];
        }

        try {
            $metadata_repo = \Tainacan\Repositories\Metadata::get_instance();
            $metadata = $metadata_repo->fetch($metadata_id);

            if ($metadata) {
                return [
                    'slug' => $metadata->get_slug(),
                    'type' => $metadata->get_metadata_type(),
                ];
            }
        } catch (\Exception $e) {
            // Ignore errors
        }

        return [];
    }
}

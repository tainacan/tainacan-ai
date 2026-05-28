<?php
namespace Tainacan\AI\Hooks;

use Tainacan\AI\Plugin;
use Tainacan\AI\Extraction\DocumentAnalyzer;
use Tainacan\AI\Extraction\ExtractionMetadata;
use Tainacan\AI\Support\CoreAI;
use Tainacan\AI\Support\DebugLog;

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
        add_action('wp_ajax_tainacan_ai_get_extraction_fields', [$this, 'ajax_get_extraction_fields']);
    }

    /**
     * Registra hook no formulário de item
     */
    public function register_hook(): void {
        if (function_exists('tainacan_register_admin_hook')) {
            tainacan_register_admin_hook(
                'item',
                [$this, 'render_form'],
                'begin-left'
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

        $options = Plugin::get_options();
        $is_configured = CoreAI::is_supported_text_generation();

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

            <?php if (!$is_configured): ?>
                <div class="tainacan-ai-notice warning">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: link to WordPress Connectors screen */
                                __('Configure AI connectors in <a href="%s">WordPress Settings &rarr; Connectors</a> to use automatic extraction.', 'tainacan-ai'),
                                esc_url(admin_url('options-connectors.php'))
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
                    <span id="tainacan-ai-tokens" style="display: none;"></span>
                    <div id="tainacan-ai-results-content" style="display: none;"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }


    /**
     * Load assets
     */
    public function enqueue_assets(string $hook): void {
        // Check if we're on the Tainacan Admin page
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        // Check if current screen matches Tainacan admin page
        // WordPress uses 'admin_page_{page_slug}' format for submenu pages
        if ($screen->base !== 'admin_page_tainacan_admin') {
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

        $options = Plugin::get_options();
        $supports_metadata_reload_event = (
            defined('TAINACAN_VERSION')
            && version_compare((string) TAINACAN_VERSION, '1.1.0', '>=')
        );

        // Extraction fields are loaded per collection via AJAX (see item-form.js).
        wp_localize_script('tainacan-ai-item', 'TainacanAI', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'tainacanApiUrl' => rest_url('tainacan/v2/'),
            'nonce' => wp_create_nonce('tainacan_ai_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'features' => [
                'supportsMetadataReloadEvent' => $supports_metadata_reload_event,
            ],
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'extractionFields' => [],
            'texts' => [
                'analyzing' => __('Analyzing...', 'tainacan-ai'),
                'analyzeBtn' => __('Analyze Document', 'tainacan-ai'),
                'error' => __('Error analyzing. Please try again.', 'tainacan-ai'),
                'errorLabel' => __('Error', 'tainacan-ai'),
                'noDocument' => __('No document found in this item.', 'tainacan-ai'),
                'copy' => __('Copy', 'tainacan-ai'),
                'copied' => __('Copied!', 'tainacan-ai'),
                'copyAll' => __('Copy All', 'tainacan-ai'),
                'allCopied' => __('All copied!', 'tainacan-ai'),
                'apply' => __('Apply', 'tainacan-ai'),
                'applied' => __('Applied!', 'tainacan-ai'),
                'cacheCleared' => __('Cache cleared!', 'tainacan-ai'),
                'clearing' => __('Clearing...', 'tainacan-ai'),
                'tokens' => __('tokens', 'tainacan-ai'),
                'image' => __('Image', 'tainacan-ai'),
                'pdf' => __('PDF', 'tainacan-ai'),
                'text' => __('Text', 'tainacan-ai'),
                'url' => __('URL', 'tainacan-ai'),
                'detecting' => __('Detecting document...', 'tainacan-ai'),
                'analysisResults' => __('Analysis Results', 'tainacan-ai'),
                'newAnalysis' => __('New Analysis', 'tainacan-ai'),
                'evidence' => __('Evidence', 'tainacan-ai'),
                'fillAll' => __('Fill Fields', 'tainacan-ai'),
                'fillAllTooltip' => __('Automatically fills Tainacan fields with extracted values', 'tainacan-ai'),
                'fillField' => __('Fill field', 'tainacan-ai'),
                'fieldsFilled' => __('fields filled', 'tainacan-ai'),
                'fieldsFailed' => __('fields failed', 'tainacan-ai'),
                'noExtractionFields' => __('No extraction-enabled metadata found', 'tainacan-ai'),
                'noFieldsToFill' => __('No fields to fill', 'tainacan-ai'),
                'fillFailed' => __('Could not update field.', 'tainacan-ai'),
                'fillFailedFor' => __('Failed to update', 'tainacan-ai'),
                'fillUnauthorized' => __('You are not authorized to update this metadata.', 'tainacan-ai'),
                'fillForbidden' => __('Access denied while updating metadata.', 'tainacan-ai'),
                'fillNetworkError' => __('Network error while updating metadata.', 'tainacan-ai'),
                'noResults' => __('No results available', 'tainacan-ai'),
                'fieldNotFound' => __('Field not found on page', 'tainacan-ai'),
                'openResults' => __('Open analysis results', 'tainacan-ai'),
                'close' => __('Close', 'tainacan-ai'),
                'clickToAnalyze' => __('Click "Analyze Document" to extract metadata', 'tainacan-ai'),
                'fieldLabel' => __('Tainacan field:', 'tainacan-ai'),
                'valueNotFound' => __('Not found in document', 'tainacan-ai'),
                'pendingTermsTitle' => __('Suggested new terms', 'tainacan-ai'),
                'pendingTermsHint' => __('No existing term matched. Review and create if appropriate.', 'tainacan-ai'),
                'newTerm' => __('New term', 'tainacan-ai'),
                'createTermAndApply' => __('Create', 'tainacan-ai'),
                'createTermFailed' => __('Could not create term.', 'tainacan-ai'),
                'createTermMissingTaxonomy' => __('Taxonomy field is not configured for term creation.', 'tainacan-ai'),
                'pendingTermNotFound' => __('Suggested term is no longer available.', 'tainacan-ai'),
                'pendingTermEmpty' => __('Please provide a term name before creating it.', 'tainacan-ai'),
                'createTermMissingId' => __('Created term response did not include an ID.', 'tainacan-ai'),
                'termCreatedAndApplied' => __('Term created and applied.', 'tainacan-ai'),
                'pendingTermsNeedCreation' => __('Create suggested terms first, then fill this field.', 'tainacan-ai'),
                'viewOnGoogleMaps' => __('View on Google Maps', 'tainacan-ai'),
                'camera' => __('Camera', 'tainacan-ai'),
                'capture' => __('Capture', 'tainacan-ai'),
                'location' => __('Location', 'tainacan-ai'),
                'authorship' => __('Authorship', 'tainacan-ai'),
            ]
        ]);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function build_analyze_ajax_response(
        DocumentAnalyzer $analyzer,
        ?int $attachment_id,
        array $result,
        bool $from_cache
    ): array {
        $response = [
            'result' => $result,
            'from_cache' => $from_cache,
        ];

        if ($attachment_id && $attachment_id > 0) {
            $prompt_debug = $analyzer->build_prompt_debug_payload($attachment_id);

            if ($prompt_debug !== null) {
                if (is_wp_error($prompt_debug)) {
                    $response['prompt_debug'] = [
                        'error' => $prompt_debug->get_error_message(),
                    ];
                } else {
                    $response['prompt_debug'] = $prompt_debug;
                }
            }
        }

        return $response;
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

            $item_id = absint(wp_unslash($_POST['item_id'] ?? 0));
            $attachment_id = absint(wp_unslash($_POST['attachment_id'] ?? 0));
            $collection_id = absint(wp_unslash($_POST['collection_id'] ?? 0));
            $force_refresh = isset($_POST['force_refresh'])
                ? filter_var(wp_unslash($_POST['force_refresh']), FILTER_VALIDATE_BOOLEAN)
                : false;

            if (empty($item_id) && empty($attachment_id)) {
                wp_send_json_error(__('Item or attachment ID not provided.', 'tainacan-ai'));
            }

            $document_data = null;

            // Get document from item if no attachment_id
            if (empty($attachment_id) && !empty($item_id)) {
                $document_data = $this->get_item_document($item_id);
                if (!$document_data) {
                    wp_send_json_error(__('No document found in this item.', 'tainacan-ai'));
                }
                if (!empty($document_data['id'])) {
                    $attachment_id = (int) $document_data['id'];
                }
            }

            // Get collection_id from item if not provided
            if (empty($collection_id) && !empty($item_id)) {
                $collection_id = $this->get_item_collection_id($item_id);
            }

            $analyzer = new DocumentAnalyzer();
            $analyzer->set_context($collection_id, $item_id);

            $is_remote_url_document = (
                is_array($document_data)
                && ($document_data['source'] ?? '') === 'url'
                && !empty($document_data['document_url'])
            );
            $document_url = $is_remote_url_document ? (string) $document_data['document_url'] : '';

            // Check cache
            $cache_key = $is_remote_url_document
                ? 'tainacan_ai_url_' . md5($document_url)
                : 'tainacan_ai_' . $attachment_id;
            if (!$force_refresh) {
                $cached = get_transient($cache_key);
                if ($cached !== false) {
                    wp_send_json_success($this->build_analyze_ajax_response(
                        $analyzer,
                        $is_remote_url_document ? null : $attachment_id,
                        $cached,
                        true
                    ));
                }
            }

            if ($is_remote_url_document) {
                $result = $analyzer->analyze_document_url($document_url);
            } else {
                $result = $analyzer->analyze($attachment_id);
            }

            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }

            // Salva no cache
            $options = Plugin::get_options();
            $cache_duration = $options['cache_duration'] ?? 3600;
            if ($cache_duration > 0) {
                set_transient($cache_key, $result, $cache_duration);
            }

            wp_send_json_success($this->build_analyze_ajax_response(
                $analyzer,
                $is_remote_url_document ? null : $attachment_id,
                $result,
                false
            ));
        } catch (\Throwable $e) {
            // Catch any error/exception and return friendly message
            $error_message = $e->getMessage();
            $error_file = basename($e->getFile());
            $error_line = $e->getLine();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                DebugLog::log("Error in ajax_analyze: {$error_message} in {$error_file}:{$error_line}");
                DebugLog::log('Stack trace: ' . $e->getTraceAsString());
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

        $item_id = absint(wp_unslash($_POST['item_id'] ?? 0));

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

                    return $this->get_remote_url_document_info((string) $document);
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
            'source' => 'attachment',
        ];
    }

    private function get_remote_url_document_info(string $document_url): array {
        $title = wp_parse_url($document_url, PHP_URL_PATH);
        $title = is_string($title) && $title !== '' ? basename($title) : $document_url;
        $extension = strtolower((string) pathinfo($title, PATHINFO_EXTENSION));
        $mime_type = 'application/octet-stream';
        $type = 'url';

        if ($extension === 'pdf') {
            $mime_type = 'application/pdf';
        } elseif (in_array($extension, ['txt', 'text'], true)) {
            $mime_type = 'text/plain';
        } elseif (in_array($extension, ['htm', 'html'], true)) {
            $mime_type = 'text/html';
        }

        return [
            'id' => 0,
            'title' => $title,
            'url' => $document_url,
            'mime_type' => $mime_type,
            'type' => $type,
            'thumbnail' => null,
            'source' => 'url',
            'document_url' => $document_url,
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
     * AJAX: Get extraction-enabled metadata for a collection (keyed by slug).
     */
    public function ajax_get_extraction_fields(): void {
        check_ajax_referer('tainacan_ai_nonce', 'nonce');

        $collection_id = absint(wp_unslash($_POST['collection_id'] ?? 0));

        if (empty($collection_id)) {
            wp_send_json_error(__('Collection ID not provided.', 'tainacan-ai'));
        }

        $fields = ExtractionMetadata::get_instance()->get_fields_for_collection($collection_id);
        wp_send_json_success($fields);
    }
}

<?php
namespace Tainacan\AI\Hooks;

use Tainacan\AI\Plugin;
use Tainacan\AI\Support\CoreAI;

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

                <span id="tainacan-ai-cache-badge" style="display: none;"></span>
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

        // Extraction fields are loaded per collection via REST (see item-form.js).
        wp_localize_script('tainacan-ai-item', 'TainacanAI', [
            'restUrl' => rest_url('tainacan-ai/v1/'),
            'tainacanApiUrl' => rest_url('tainacan/v2/'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'features' => [
                'supportsMetadataReloadEvent' => $supports_metadata_reload_event,
            ],
            'advancedDebug' => Plugin::is_advanced_debug(),
            'debug' => (defined('WP_DEBUG') && WP_DEBUG) || Plugin::is_advanced_debug(),
            'extractionFields' => [],
            'texts' => [
                'analyzing' => __('Analyzing...', 'tainacan-ai'),
                'analyzeBtn' => __('Analyze Document', 'tainacan-ai'),
                'error' => __('Error analyzing. Please try again.', 'tainacan-ai'),
                'errorLabel' => __('Error', 'tainacan-ai'),
                'analysisFailedSummary' => __('Analysis failed', 'tainacan-ai'),
                'errorHttpStatus' => __('HTTP status', 'tainacan-ai'),
                'errorCode' => __('Error code', 'tainacan-ai'),
                'errorDetails' => __('Details', 'tainacan-ai'),
                'errorResponse' => __('Server response', 'tainacan-ai'),
                'errorDebugDetails' => __('Technical details', 'tainacan-ai'),
                'errorContentTruncated' => __('truncated', 'tainacan-ai'),
                'loadingSubtitle' => __('This may take a few seconds', 'tainacan-ai'),
                'noDocument' => __('No document found in this item.', 'tainacan-ai'),
                'copy' => __('Copy', 'tainacan-ai'),
                'copied' => __('Copied!', 'tainacan-ai'),
                'copyAll' => __('Copy all', 'tainacan-ai'),
                'allCopied' => __('All copied!', 'tainacan-ai'),
                'apply' => __('Apply', 'tainacan-ai'),
                'applied' => __('Applied!', 'tainacan-ai'),
                'cacheCleared' => __('Cache cleared!', 'tainacan-ai'),
                'clearing' => __('Clearing...', 'tainacan-ai'),
                'tokens' => __('tokens', 'tainacan-ai'),
                'characters' => __('characters', 'tainacan-ai'),
                'modelUnknown' => __('Model unavailable', 'tainacan-ai'),
                'image' => __('Image', 'tainacan-ai'),
                'pdf' => __('PDF', 'tainacan-ai'),
                'text' => __('Text', 'tainacan-ai'),
                'url' => __('URL', 'tainacan-ai'),
                'detecting' => __('Detecting document...', 'tainacan-ai'),
                'panelTitle' => __('Tainacan AI', 'tainacan-ai'),
                'analysisResults' => __('Analysis Results', 'tainacan-ai'),
                'tabImageData' => __('Image data', 'tainacan-ai'),
                'tabRequest' => __('Request', 'tainacan-ai'),
                'requestTokens' => __('Tokens', 'tainacan-ai'),
                'requestCharacters' => __('Prompt text (characters)', 'tainacan-ai'),
                'requestResponseCharacters' => __('Response (characters)', 'tainacan-ai'),
                'requestAnalysisMode' => __('Analysis mode', 'tainacan-ai'),
                'requestFinishReason' => __('Finish reason', 'tainacan-ai'),
                'requestDuration' => __('Duration', 'tainacan-ai'),
                'requestConnector' => __('Connector', 'tainacan-ai'),
                'requestModel' => __('Model', 'tainacan-ai'),
                'connectorUnknown' => __('Connector unavailable', 'tainacan-ai'),
                'finishReasonStop' => __('Completed normally', 'tainacan-ai'),
                'finishReasonLength' => __('Stopped at max length', 'tainacan-ai'),
                'finishReasonContentFilter' => __('Blocked by content filter', 'tainacan-ai'),
                'finishReasonToolCalls' => __('Stopped for tool calls', 'tainacan-ai'),
                'finishReasonError' => __('Stopped due to error', 'tainacan-ai'),
                'finishReasonUnknown' => __('Unknown', 'tainacan-ai'),
                'analysisModeImage' => __('Image (vision)', 'tainacan-ai'),
                'analysisModeText' => __('Text', 'tainacan-ai'),
                'analysisModePdfText' => __('PDF text', 'tainacan-ai'),
                'analysisModePdfVisual' => __('PDF visual', 'tainacan-ai'),
                'durationSeconds' => __('seconds', 'tainacan-ai'),
                'tokensPrompt' => __('prompt', 'tainacan-ai'),
                'tokensCompletion' => __('completion', 'tainacan-ai'),
                'tokensTotal' => __('total', 'tainacan-ai'),
                'newAnalysis' => __('New Analysis', 'tainacan-ai'),
                'evidence' => __('Evidence', 'tainacan-ai'),
                'fillAll' => __('Fill all', 'tainacan-ai'),
                'fillAllTooltip' => __('Automatically fills Tainacan fields with extracted values', 'tainacan-ai'),
                'fillField' => __('Fill metadatum', 'tainacan-ai'),
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
                'editPrompt' => __('Edit prompt', 'tainacan-ai'),
                'promptEditorTitle' => __('Analysis prompt', 'tainacan-ai'),
                'runWithPrompt' => __('Run with this prompt', 'tainacan-ai'),
                'resetPrompt' => __('Reset to last resolved prompt', 'tainacan-ai'),
                'promptEditorHint' => __('Edits apply only to this run and are not saved. Changing schema or output keys may break field filling.', 'tainacan-ai'),
                'promptDocumentPreview' => __('Document sent to the model (read-only)', 'tainacan-ai'),
                'promptDocumentImage' => __('Image bytes are attached to the API request; pixel data is not shown here.', 'tainacan-ai'),
                'promptDocumentPdfVisual' => __('PDF pages are sent as images; page raster data is not shown here.', 'tainacan-ai'),
                'promptDocumentTruncated' => __('Only part of the document was sent to the model. See processing notes for details.', 'tainacan-ai'),
                'promptDocumentEmpty' => __('No extractable text was found in this document.', 'tainacan-ai'),
                'processingWarningsTitle' => __('Some content was not fully processed', 'tainacan-ai'),
            ]
        ]);
    }

}

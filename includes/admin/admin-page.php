<?php
/**
 * Admin page template
 * @var array $options
 */

if (!defined('ABSPATH')) {
    exit;
}

// Stub for static analysis when imagick extension isn't available.
if (!class_exists('\Imagick')) {
    class Imagick {
        public function queryFormats(string $format): array {
            return [];
        }
    }
}

// WP 7.0+ path: rely on WordPress Connectors + Core AI support checks.
$tainacan_ai_is_configured = \Tainacan\AI\CoreAI::is_supported_text_generation();
$tainacan_ai_has_image_support = \Tainacan\AI\CoreAI::is_supported_image_analysis();

// Check dependencies
$tainacan_ai_has_exif = function_exists('exif_read_data');

// Local PDF-to-image backends (scanned PDF visual analysis)
$tainacan_ai_has_imagick = extension_loaded('imagick');
$tainacan_ai_has_imagick_pdf = false;
if ($tainacan_ai_has_imagick) {
    try {
        $tainacan_ai_imagick = new \Imagick();
        $tainacan_ai_formats = $tainacan_ai_imagick->queryFormats('PDF');
        $tainacan_ai_has_imagick_pdf = !empty($tainacan_ai_formats);
    } catch (\Exception $e) {
        $tainacan_ai_has_imagick_pdf = false;
    }
}

// Ghostscript (PDF to image)
$tainacan_ai_has_ghostscript = false;
if (function_exists('shell_exec')) {
    if (PHP_OS_FAMILY === 'Windows') {
        $tainacan_ai_output = @shell_exec('where gswin64c 2>nul');
        if (empty($tainacan_ai_output)) {
            $tainacan_ai_output = @shell_exec('where gswin32c 2>nul');
        }
        $tainacan_ai_has_ghostscript = !empty($tainacan_ai_output);
    } else {
        $tainacan_ai_output = @shell_exec('which gs 2>/dev/null');
        $tainacan_ai_has_ghostscript = !empty($tainacan_ai_output);
    }
}

$tainacan_ai_has_visual = $tainacan_ai_has_imagick_pdf || $tainacan_ai_has_ghostscript;
?>

<div class="wrap tainacan-page-container-content tainacan-ai-admin">
    <div class="tainacan-fixed-subheader">
        <h1 class="tainacan-page-title">
            <!-- <svg class="tainacan-ai-title-icon" viewBox="0 0 24 24" width="32" height="32" fill="currentColor">
                <path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073z"/>
            </svg> -->
            <svg class="tainacan-ai-title-icon" xmlns="http://www.w3.org/2000/svg" xml:space="preserve" id="svg5" width="32" height="32" version="1.1" viewBox="0 0 8.467 8.467">
                <g id="layer1" transform="translate(-51.439 -147.782)"><path id="path11554" d="m58.994 153.057-.247.062-.349.082c.124.134.217.267.282.396.158.318.161.607.012.927v.002l-.005.007c-.172.37-.412.548-.824.616-.002 0-.004 0-.005.002-.074.012-.16.018-.257.018-.383 0-.864-.118-1.415-.372l-.009-.005a.534.534 0 0 0-.078-.033 4.111 4.111 0 0 1-.427-.191h-.004c-.016.064-.03.131-.05.21a3.34 3.34 0 0 1-.083.302l-.01.029c.144.07.273.124.38.164l.037.019c.608.282 1.165.426 1.658.426.122 0 .24-.007.352-.026a1.588 1.588 0 0 0 1.235-.927l.003-.007c.215-.46.212-.95-.014-1.405-.051-.102-.111-.2-.182-.297z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path11552" d="M57.188 148.868c-.079 0-.16.006-.241.017-.359.047-.732.2-1.112.455.028.116.055.228.077.311.095.025.226.055.36.087.266-.158.536-.28.748-.307.366-.05.646.046.91.31h.003v.002c.27.272.363.549.314.915a1.85 1.85 0 0 1-.213.62l.055.216c.01.04.017.061.026.091.03.01.053.016.094.027l.238.058c.19-.32.306-.634.346-.94a1.592 1.592 0 0 0-.467-1.375l-.004-.003a1.583 1.583 0 0 0-1.134-.484z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><path id="path1" d="M53.574 148.312a1.671 1.671 0 0 0-.67.161l-.006.003c-1.05.493-1.246 1.706-.527 3.248l.015.034c.122.323.356.82.783 1.372a5.33 5.33 0 0 0-.208 1.435v.148h.148c.285 0 .731-.031 1.282-.17-.036-.007-.067-.016-.108-.025a3.406 3.406 0 0 1-.301-.083.791.791 0 0 1-.16-.071.441.441 0 0 1-.222-.295h-.002c-.028-.15 0-.435.101-.79a.55.55 0 0 0-.096-.486 4.834 4.834 0 0 1-.717-1.266l-.016-.034c-.328-.704-.421-1.293-.356-1.697.066-.404.238-.642.618-.82l.004-.002c.168-.078.323-.117.476-.115v-.001c.153 0 .305.042.465.122.15.075.33.237.496.415l.03-.121c.033-.14.067-.281.11-.409l.036-.092v-.001a2.172 2.172 0 0 0-.424-.284 1.605 1.605 0 0 0-.75-.176z" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none"/><g id="path6974" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.07603 0 0 1.0728 -16.96 -11.535)"><path id="path10029" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6968" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(1.51152 0 0 1.50697 -44.11 -74.969)"><path id="path10038" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g><g id="path6976" style="fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-dasharray:none" transform="matrix(.77239 0 0 .77006 3.277 37.782)"><path id="path10046" d="M68.481 150.899c0 .123-.974.262-1.06.349-.088.087-.227 1.06-.35 1.06-.123 0-.262-.973-.349-1.06-.087-.087-1.06-.226-1.06-.35 0-.122.973-.261 1.06-.348.087-.087.226-1.061.35-1.061.122 0 .261.974.348 1.06.087.088 1.061.227 1.061.35z" style="color:currentColor;fill:currentColor;fill-opacity:1;stroke:none;stroke-width:0;stroke-linecap:round;stroke-dasharray:none;paint-order:stroke markers fill"/></g></g>
            </svg>
            <?php esc_html_e('Tainacan AI Tools', 'tainacan-ai'); ?>
            <span class="tainacan-ai-version">v<?php echo esc_html(TAINACAN_AI_VERSION); ?></span>
        </h1>
    </div>

    <div class="tainacan-ai-admin-content">
        <form method="post" action="options.php" class="tainacan-ai-form">
            <?php settings_fields('tainacan_ai_options'); ?>

            <div class="tainacan-ai-form-fields">

                <!-- Seção: Prompts Padrão -->
                <div class="tainacan-ai-card">
                    <div class="tainacan-ai-card-header">
                        <div class="tainacan-ai-card-title">
                            <span class="dashicons dashicons-edit-page"></span>
                            <h2><?php esc_html_e('Default Analysis Prompts', 'tainacan-ai'); ?></h2>
                        </div>
                        <button type="button" class="tainacan-ai-toggle-card">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                    </div>
                    <div class="tainacan-ai-card-body tainacan-ai-collapsible">
                        <p class="tainacan-ai-card-description">
                            <?php esc_html_e('Prompts define how the AI should analyze documents. Use clear instructions and specify the JSON fields you want to extract.', 'tainacan-ai'); ?>
                        </p>

                        <div class="tainacan-ai-field">
                            <label for="default_image_prompt">
                                <?php esc_html_e('Prompt for Images', 'tainacan-ai'); ?>
                            </label>
                            <textarea
                                id="default_image_prompt"
                                name="tainacan_ai_options[default_image_prompt]"
                                rows="8"
                                class="large-text code"
                            ><?php echo esc_textarea($options['default_image_prompt'] ?? ''); ?></textarea>
                        </div>

                        <div class="tainacan-ai-field">
                            <label for="default_document_prompt">
                                <?php esc_html_e('Prompt for Documents', 'tainacan-ai'); ?>
                            </label>
                            <textarea
                                id="default_document_prompt"
                                name="tainacan_ai_options[default_document_prompt]"
                                rows="8"
                                class="large-text code"
                            ><?php echo esc_textarea($options['default_document_prompt'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Seção: Prompts por Coleção -->
                <div class="tainacan-ai-card">
                    <div class="tainacan-ai-card-header">
                        <div class="tainacan-ai-card-title">
                            <span class="dashicons dashicons-category"></span>
                            <h2><?php esc_html_e('Prompts per Collection', 'tainacan-ai'); ?></h2>
                        </div>
                        <button type="button" class="tainacan-ai-toggle-card">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                    </div>
                    <div class="tainacan-ai-card-body tainacan-ai-collapsible">
                        <p class="tainacan-ai-card-description">
                            <?php esc_html_e('Configure specific prompts for each collection.', 'tainacan-ai'); ?>
                        </p>

                        <div class="tainacan-ai-collection-prompts" id="collection-prompts-container">
                            <div class="tainacan-ai-field">
                                <label for="collection-select"><?php esc_html_e('Select a Collection', 'tainacan-ai'); ?></label>
                                <select id="collection-select" class="regular-text">
                                    <option value=""><?php esc_html_e('-- Select --', 'tainacan-ai'); ?></option>
                                </select>
                            </div>

                            <div id="collection-prompt-editor" style="display: none;">
                                <div class="tainacan-ai-field-row">
                                    <div class="tainacan-ai-field">
                                        <label><?php esc_html_e('Prompt Type', 'tainacan-ai'); ?></label>
                                        <div class="tainacan-ai-radio-group">
                                            <label>
                                                <input type="radio" name="collection_prompt_type" value="image" checked>
                                                <span class="dashicons dashicons-format-image"></span>
                                                <?php esc_html_e('Image', 'tainacan-ai'); ?>
                                            </label>
                                            <label>
                                                <input type="radio" name="collection_prompt_type" value="document">
                                                <span class="dashicons dashicons-media-document"></span>
                                                <?php esc_html_e('Document', 'tainacan-ai'); ?>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="tainacan-ai-field">
                                    <div class="tainacan-ai-field-header">
                                        <label for="collection-prompt-text"><?php esc_html_e('Custom Prompt', 'tainacan-ai'); ?></label>
                                        <button type="button" class="button button-small" id="generate-prompt-suggestion">
                                            <span class="dashicons dashicons-lightbulb"></span>
                                            <?php esc_html_e('Generate Suggestion', 'tainacan-ai'); ?>
                                        </button>
                                    </div>
                                    <textarea
                                        id="collection-prompt-text"
                                        rows="10"
                                        class="large-text code"
                                        placeholder="<?php esc_attr_e('Leave blank to use default prompt...', 'tainacan-ai'); ?>"
                                    ></textarea>
                                </div>

                                <div class="tainacan-ai-collection-actions">
                                    <button type="button" class="button button-primary" id="save-collection-prompt">
                                        <span class="dashicons dashicons-saved"></span>
                                        <?php esc_html_e('Save Prompt', 'tainacan-ai'); ?>
                                    </button>
                                    <button type="button" class="button" id="reset-collection-prompt">
                                        <span class="dashicons dashicons-undo"></span>
                                        <?php esc_html_e('Reset to Default', 'tainacan-ai'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Seção: Mapeamento de Campos -->
                <div class="tainacan-ai-card">
                    <div class="tainacan-ai-card-header">
                        <div class="tainacan-ai-card-title">
                            <span class="dashicons dashicons-networking"></span>
                            <h2><?php esc_html_e('Field Mapping', 'tainacan-ai'); ?></h2>
                        </div>
                        <button type="button" class="tainacan-ai-toggle-card">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                    </div>
                    <div class="tainacan-ai-card-body tainacan-ai-collapsible">
                        <p class="tainacan-ai-card-description">
                            <?php esc_html_e('Configure the mapping between AI-extracted fields and your collection metadata. This allows the "Fill Fields" button to work correctly.', 'tainacan-ai'); ?>
                        </p>

                        <div class="tainacan-ai-field">
                            <label for="mapping-collection-select"><?php esc_html_e('Select a Collection', 'tainacan-ai'); ?></label>
                            <select id="mapping-collection-select" class="regular-text">
                                <option value=""><?php esc_html_e('-- Selecione --', 'tainacan-ai'); ?></option>
                                <?php
                                // Popula coleções diretamente no PHP
                                if (class_exists('\Tainacan\Repositories\Collections')) {
                                    $tainacan_ai_collections_repo = \Tainacan\Repositories\Collections::get_instance();
                                    $tainacan_ai_collections = $tainacan_ai_collections_repo->fetch([], 'OBJECT');
                                    if (is_array($tainacan_ai_collections)) {
                                        foreach ($tainacan_ai_collections as $tainacan_ai_collection) {
                                            printf(
                                                '<option value="%d">%s</option>',
                                                esc_attr($tainacan_ai_collection->get_id()),
                                                esc_html($tainacan_ai_collection->get_name())
                                            );
                                        }
                                    }
                                }
                                ?>
                            </select>
                        </div>

                        <div id="metadata-mapping-editor" style="display: none;">
                            <div class="tainacan-ai-mapping-header">
                                <div class="tainacan-ai-mapping-col"><?php esc_html_e('AI Field', 'tainacan-ai'); ?></div>
                                <div class="tainacan-ai-mapping-col tainacan-ai-mapping-col--spacer" aria-hidden="true"></div>
                                <div class="tainacan-ai-mapping-col"><?php esc_html_e('Tainacan Metadata', 'tainacan-ai'); ?></div>
                                <div class="tainacan-ai-mapping-col tainacan-ai-mapping-col--spacer" aria-hidden="true"></div>
                            </div>
                            <div id="metadata-mapping-list" class="tainacan-ai-mapping-list">
                                <!-- Preenchido via JavaScript -->
                            </div>

                            <div class="tainacan-ai-mapping-actions">
                                <button type="button" class="button button-primary" id="save-metadata-mapping">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Save Mapping', 'tainacan-ai'); ?>
                                </button>
                                <button type="button" class="button" id="auto-detect-mapping">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    <?php esc_html_e('Auto-detect', 'tainacan-ai'); ?>
                                </button>
                                <button type="button" class="button" id="clear-mapping">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Clear Mapping', 'tainacan-ai'); ?>
                                </button>
                            </div>

                            <p class="tainacan-ai-card-description tainacan-ai-card-description-tip">
                                <?php
                                echo wp_kses(
                                    __('<strong>Tip:</strong> AI fields are defined in your prompt. Configure the prompt to return the desired fields and map them here.', 'tainacan-ai'),
                                    array( 'strong' => array() )
                                );
                            ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Seção: Funcionalidades -->
                <div class="tainacan-ai-card">
                    <div class="tainacan-ai-card-header">
                        <div class="tainacan-ai-card-title">
                            <span class="dashicons dashicons-admin-tools"></span>
                            <h2><?php esc_html_e('Features', 'tainacan-ai'); ?></h2>
                        </div>
                    </div>
                    <div class="tainacan-ai-card-body">
                        <div class="tainacan-ai-checkbox-grid">
                            <label class="tainacan-ai-checkbox">
                                <input
                                    type="checkbox"
                                    name="tainacan_ai_options[extract_exif]"
                                    value="1"
                                    <?php checked(!empty($options['extract_exif'])); ?>
                                />
                                <span class="tainacan-ai-checkbox-content">
                                    <span class="tainacan-ai-checkbox-title">
                                        <span class="dashicons dashicons-camera"></span>
                                        <?php esc_html_e('Extract EXIF Data', 'tainacan-ai'); ?>
                                    </span>
                                    <span class="tainacan-ai-checkbox-desc">
                                        <?php esc_html_e('Extracts technical metadata from images', 'tainacan-ai'); ?>
                                    </span>
                                </span>
                            </label>

                            <label class="tainacan-ai-checkbox">
                                <input
                                    type="checkbox"
                                    name="tainacan_ai_options[consent_required]"
                                    value="1"
                                    <?php checked(!empty($options['consent_required'])); ?>
                                />
                                <span class="tainacan-ai-checkbox-content">
                                    <span class="tainacan-ai-checkbox-title">
                                        <span class="dashicons dashicons-shield"></span>
                                        <?php esc_html_e('Require Consent', 'tainacan-ai'); ?>
                                    </span>
                                    <span class="tainacan-ai-checkbox-desc">
                                        <?php esc_html_e('Integrates with WP Consent API', 'tainacan-ai'); ?>
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Seção: Configurações Avançadas -->
                <div class="tainacan-ai-card">
                    <div class="tainacan-ai-card-header">
                        <div class="tainacan-ai-card-title">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <h2><?php esc_html_e('Advanced Settings', 'tainacan-ai'); ?></h2>
                        </div>
                        <button type="button" class="tainacan-ai-toggle-card">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </button>
                    </div>
                    <div class="tainacan-ai-card-body tainacan-ai-collapsible collapsed">
                        <div class="tainacan-ai-field-grid">
                            <div class="tainacan-ai-field">
                                <label for="max_tokens"><?php esc_html_e('Max Tokens', 'tainacan-ai'); ?></label>
                                <input
                                    type="number"
                                    id="max_tokens"
                                    name="tainacan_ai_options[max_tokens]"
                                    value="<?php echo esc_attr($options['max_tokens'] ?? 2000); ?>"
                                    min="100"
                                    max="8000"
                                />
                                <p class="description"><?php esc_html_e('Token limit in response (100-8000).', 'tainacan-ai'); ?></p>
                            </div>

                            <div class="tainacan-ai-field">
                                <label for="temperature"><?php esc_html_e('Temperature', 'tainacan-ai'); ?></label>
                                <input
                                    type="number"
                                    id="temperature"
                                    name="tainacan_ai_options[temperature]"
                                    value="<?php echo esc_attr($options['temperature'] ?? 0.1); ?>"
                                    min="0"
                                    max="2"
                                    step="0.1"
                                />
                                <p class="description"><?php esc_html_e('0 = deterministic, 2 = creative.', 'tainacan-ai'); ?></p>
                            </div>

                            <div class="tainacan-ai-field">
                                <label for="request_timeout"><?php esc_html_e('Timeout (seconds)', 'tainacan-ai'); ?></label>
                                <input
                                    type="number"
                                    id="request_timeout"
                                    name="tainacan_ai_options[request_timeout]"
                                    value="<?php echo esc_attr($options['request_timeout'] ?? 120); ?>"
                                    min="10"
                                    max="300"
                                />
                            </div>

                            <div class="tainacan-ai-field">
                                <label for="cache_duration"><?php esc_html_e('Cache Duration (seconds)', 'tainacan-ai'); ?></label>
                                <input
                                    type="number"
                                    id="cache_duration"
                                    name="tainacan_ai_options[cache_duration]"
                                    value="<?php echo esc_attr($options['cache_duration'] ?? 3600); ?>"
                                    min="0"
                                    max="604800"
                                />
                                <p class="description"><?php esc_html_e('0 to disable.', 'tainacan-ai'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

             <!-- Sidebar -->
            <div class="tainacan-ai-sidebar">
                <div class="tainacan-ai-info-box">
                    <h3>
                        <span class="dashicons dashicons-cloud"></span>
                        <?php esc_html_e('AI Connectors', 'tainacan-ai'); ?>
                    </h3>
                    <?php if ($tainacan_ai_is_configured): ?>
                        <span class="tainacan-ai-badge success"><?php esc_html_e('Configured', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php esc_html_e('Not configured', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                /* translators: %s: connectors admin URL */
                                __('WordPress chooses the connector and model automatically. Configure credentials in <a href="%s">Settings &rarr; Connectors</a>.', 'tainacan-ai'),
                                esc_url(admin_url('options-connectors.php'))
                            )
                        );
                        ?>
                    </p>
                    <?php if (empty($tainacan_ai_has_image_support)): ?>
                        <p><?php esc_html_e('Image/PDF visual analysis may be unavailable depending on the configured WordPress connector.', 'tainacan-ai'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="tainacan-ai-info-box">
                    <h3>
                        <span class="dashicons dashicons-admin-tools"></span>
                        <?php esc_html_e('System Capabilities', 'tainacan-ai'); ?>
                    </h3>
                    <p><?php esc_html_e('Status of local modules and runtime support used during analysis.', 'tainacan-ai'); ?></p>
                    <ul class="tainacan-ai-capability-list">
                        <li>
                            <span><?php esc_html_e('Text extraction', 'tainacan-ai'); ?></span>
                            <strong class="status-ok"><?php esc_html_e('Available', 'tainacan-ai'); ?></strong>
                        </li>
                        <li>
                            <span><?php esc_html_e('EXIF extraction', 'tainacan-ai'); ?></span>
                            <strong class="<?php echo $tainacan_ai_has_exif ? 'status-ok' : 'status-warn'; ?>">
                                <?php echo $tainacan_ai_has_exif ? esc_html__('Available', 'tainacan-ai') : esc_html__('Missing', 'tainacan-ai'); ?>
                            </strong>
                        </li>
                        <li>
                            <span><?php esc_html_e('Visual PDF analysis', 'tainacan-ai'); ?></span>
                            <strong class="<?php echo $tainacan_ai_has_visual ? 'status-ok' : 'status-warn'; ?>">
                                <?php echo $tainacan_ai_has_visual ? esc_html__('Available', 'tainacan-ai') : esc_html__('Unavailable', 'tainacan-ai'); ?>
                            </strong>
                        </li>
                        <li>
                            <span><?php esc_html_e('Image analysis via connector', 'tainacan-ai'); ?></span>
                            <strong class="<?php echo $tainacan_ai_has_image_support ? 'status-ok' : 'status-warn'; ?>">
                                <?php echo $tainacan_ai_has_image_support ? esc_html__('Available', 'tainacan-ai') : esc_html__('Unavailable', 'tainacan-ai'); ?>
                            </strong>
                        </li>
                    </ul>
                    <p>
                        <strong><?php esc_html_e('Backends:', 'tainacan-ai'); ?></strong>
                        <?php if ($tainacan_ai_has_imagick_pdf): ?>
                            <span class="tainacan-ai-method-available">Imagick</span>
                        <?php else: ?>
                            <span class="tainacan-ai-method-missing">Imagick</span>
                        <?php endif; ?>
                        <?php if ($tainacan_ai_has_ghostscript): ?>
                            <span class="tainacan-ai-method-available">Ghostscript</span>
                        <?php else: ?>
                            <span class="tainacan-ai-method-missing">Ghostscript</span>
                        <?php endif; ?>
                    </p>
                    <?php if (!$tainacan_ai_has_exif || !$tainacan_ai_has_visual): ?>
                        <p>
                            <strong><?php esc_html_e('Missing modules:', 'tainacan-ai'); ?></strong>
                            <?php
                            $tainacan_ai_missing_modules = [];
                            if (!$tainacan_ai_has_exif) {
                                $tainacan_ai_missing_modules[] = esc_html__('EXIF extension', 'tainacan-ai');
                            }
                            if (!$tainacan_ai_has_visual) {
                                if (!$tainacan_ai_has_imagick_pdf) {
                                    $tainacan_ai_missing_modules[] = 'Imagick (PDF support)';
                                }
                                if (!$tainacan_ai_has_ghostscript) {
                                    $tainacan_ai_missing_modules[] = 'Ghostscript';
                                }
                            }
                            echo esc_html(implode(', ', $tainacan_ai_missing_modules));
                            ?>
                        </p>
                    <?php endif; ?>

                    <div class="tainacan-ai-formats compact">
                        <div class="tainacan-ai-format-group">
                            <strong><?php esc_html_e('Images:', 'tainacan-ai'); ?></strong>
                            <span>JPG, PNG, GIF, WebP</span>
                        </div>
                        <div class="tainacan-ai-format-group">
                            <strong><?php esc_html_e('Documents:', 'tainacan-ai'); ?></strong>
                            <span>PDF, TXT, HTML</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ações -->
            <div class="tainacan-ai-actions-bar">
                
                <button type="button" class="button button-secondary" id="clear-all-cache">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e('Clear Cache', 'tainacan-ai'); ?>
                </button>

                <?php submit_button(esc_html__('Save Settings', 'tainacan-ai'), 'primary large', 'submit', false); ?>
            </div>


        </form>

    </div>
</div>

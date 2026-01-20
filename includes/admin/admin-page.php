<?php
/**
 * Admin page template
 * @var array $options
 * @var array $stats
 */

if (!defined('ABSPATH')) {
    exit;
}

use Tainacan\AI\AI\AIProviderFactory;

// Get available providers
$tainacan_ai_providers = AIProviderFactory::get_available_providers();
$tainacan_ai_current_provider = $options['ai_provider'] ?? 'openai';

// Check configuration for each provider
$tainacan_ai_provider_status = [
    'openai' => !empty($options['api_key']),
    'gemini' => !empty($options['gemini_api_key']),
    'deepseek' => !empty($options['deepseek_api_key']),
    'ollama' => !empty($options['ollama_url']),
];

$tainacan_ai_is_configured = $tainacan_ai_provider_status[$tainacan_ai_current_provider] ?? false;

// Check dependencies
$tainacan_ai_has_exif = function_exists('exif_read_data');
$tainacan_ai_has_pdfparser = class_exists('\Smalot\PdfParser\Parser') || file_exists(TAINACAN_AI_PLUGIN_DIR . 'vendor/autoload.php');

// Check visual PDF analysis capabilities
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

// Check Ghostscript
$tainacan_ai_has_ghostscript = false;
$tainacan_ai_gs_path = null;
if (function_exists('shell_exec')) {
    if (PHP_OS_FAMILY === 'Windows') {
        $tainacan_ai_output = @shell_exec('where gswin64c 2>nul');
        if (empty($tainacan_ai_output)) {
            $tainacan_ai_output = @shell_exec('where gswin32c 2>nul');
        }
        if (!empty($tainacan_ai_output)) {
            $tainacan_ai_has_ghostscript = true;
            $tainacan_ai_gs_path = trim($tainacan_ai_output);
        }
    } else {
        $tainacan_ai_output = @shell_exec('which gs 2>/dev/null');
        if (!empty($tainacan_ai_output)) {
            $tainacan_ai_has_ghostscript = true;
            $tainacan_ai_gs_path = trim($tainacan_ai_output);
        }
    }
}

$tainacan_ai_has_builtin_parser = true;
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

    <!-- Stats Cards -->
    <?php if ($tainacan_ai_is_configured && !empty($stats)): ?>
    <div class="tainacan-ai-stats-grid">
        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon">
                <span class="dashicons dashicons-chart-bar"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value"><?php echo number_format($stats['total_analyses']); ?></span>
                <span class="tainacan-ai-stat-label"><?php esc_html_e('Analyses (30 days)', 'tainacan-ai'); ?></span>
            </div>
        </div>

        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon">
                <span class="dashicons dashicons-performance"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value"><?php echo number_format($stats['total_tokens']); ?></span>
                <span class="tainacan-ai-stat-label"><?php esc_html_e('Tokens Used', 'tainacan-ai'); ?></span>
            </div>
        </div>

        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon success">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value"><?php echo esc_html($stats['success_rate']); ?>%</span>
                <span class="tainacan-ai-stat-label"><?php esc_html_e('Success Rate', 'tainacan-ai'); ?></span>
            </div>
        </div>

        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon">
                <span class="dashicons dashicons-money-alt"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value">$<?php echo number_format($stats['total_cost'], 4); ?></span>
                <span class="tainacan-ai-stat-label"><?php esc_html_e('Estimated Cost', 'tainacan-ai'); ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="tainacan-ai-admin-content">
        <form method="post" action="options.php" class="tainacan-ai-form">
            <?php settings_fields('tainacan_ai_options'); ?>

            <!-- Seção: Provedor de IA -->
            <div class="tainacan-ai-card">
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-cloud"></span>
                        <h2><?php esc_html_e('AI Provider', 'tainacan-ai'); ?></h2>
                    </div>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-provider-selector">
                        <?php foreach ($tainacan_ai_providers as $id => $tainacan_ai_provider): ?>
                        <label class="tainacan-ai-provider-option <?php echo $tainacan_ai_current_provider === $id ? 'selected' : ''; ?>">
                            <input
                                type="radio"
                                name="tainacan_ai_options[ai_provider]"
                                value="<?php echo esc_attr($id); ?>"
                                <?php checked($tainacan_ai_current_provider, $id); ?>
                            />
                            <div class="tainacan-ai-provider-content">
                                <span class="tainacan-ai-provider-name"><?php echo esc_html($tainacan_ai_provider['name']); ?></span>
                                <span class="tainacan-ai-provider-features">
                                    <?php if ($tainacan_ai_provider['supports_vision']): ?>
                                        <span class="tainacan-ai-feature-badge success" title="<?php esc_attr_e('Supports image analysis', 'tainacan-ai'); ?>">
                                            <span class="dashicons dashicons-format-image"></span>
                                            <?php esc_html_e('Vision', 'tainacan-ai'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="tainacan-ai-feature-badge warning" title="<?php esc_attr_e('Text only', 'tainacan-ai'); ?>">
                                            <span class="dashicons dashicons-text"></span>
                                            <?php esc_html_e('Text', 'tainacan-ai'); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($tainacan_ai_provider_status[$id]): ?>
                                    <span class="tainacan-ai-provider-status configured">
                                        <span class="dashicons dashicons-yes"></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Seção: OpenAI -->
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-openai" <?php echo $tainacan_ai_current_provider !== 'openai' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2><?php esc_html_e('OpenAI Configuration', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($tainacan_ai_provider_status['openai']): ?>
                        <span class="tainacan-ai-badge success"><?php esc_html_e('Configured', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php esc_html_e('Pending', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-field">
                        <label for="api_key">
                            <?php esc_html_e('OpenAI API Key', 'tainacan-ai'); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="tainacan-ai-input-group">
                            <input
                                type="password"
                                id="api_key"
                                name="tainacan_ai_options[api_key]"
                                value="<?php echo esc_attr($options['api_key'] ?? ''); ?>"
                                class="regular-text"
                                autocomplete="off"
                                placeholder="sk-..."
                            />
                            <button type="button" class="button toggle-password" data-target="api_key">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <button type="button" class="button button-secondary test-api-btn" data-provider="openai">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e('Test', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php
                            echo wp_kses_post(
                                sprintf(
                                    /* translators: %s: link to OpenAI platform */
                                    esc_html__('Get your key at %s.', 'tainacan-ai'),
                                    '<a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>'
                                )
                            ); ?>
                        </p>
                        <div class="api-test-result" data-provider="openai" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="model"><?php esc_html_e('Model', 'tainacan-ai'); ?></label>
                        <select id="model" name="tainacan_ai_options[model]">
                            <?php
                            $tainacan_ai_openai_models = $tainacan_ai_providers['openai']['models'] ?? [];
                            $tainacan_ai_current_model = $options['model'] ?? 'gpt-4o';
                            foreach ($tainacan_ai_openai_models as $tainacan_ai_value => $tainacan_ai_label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($tainacan_ai_value),
                                    selected($tainacan_ai_current_model, $tainacan_ai_value, false),
                                    esc_html($tainacan_ai_label)
                                );
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Seção: Google Gemini -->
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-gemini" <?php echo $tainacan_ai_current_provider !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2><?php esc_html_e('Google Gemini Configuration', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($tainacan_ai_provider_status['gemini']): ?>
                        <span class="tainacan-ai-badge success"><?php esc_html_e('Configured', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php esc_html_e('Pending', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-field">
                        <label for="gemini_api_key">
                            <?php esc_html_e('Google AI API Key', 'tainacan-ai'); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="tainacan-ai-input-group">
                            <input
                                type="password"
                                id="gemini_api_key"
                                name="tainacan_ai_options[gemini_api_key]"
                                value="<?php echo esc_attr($options['gemini_api_key'] ?? ''); ?>"
                                class="regular-text"
                                autocomplete="off"
                                placeholder="AIza..."
                            />
                            <button type="button" class="button toggle-password" data-target="gemini_api_key">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <button type="button" class="button button-secondary test-api-btn" data-provider="gemini">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e('Test', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php
                            echo wp_kses_post(
                                sprintf(
                                    /* translators: %s: link to Google AI Studio */
                                    esc_html__('Get your key at %s.', 'tainacan-ai'),
                                    '<a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>'
                                )
                            ); ?>
                        </p>
                        <div class="api-test-result" data-provider="gemini" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="gemini_model"><?php esc_html_e('Model', 'tainacan-ai'); ?></label>
                        <select id="gemini_model" name="tainacan_ai_options[gemini_model]">
                            <?php
                            $tainacan_ai_gemini_models = $tainacan_ai_providers['gemini']['models'] ?? [];
                            $tainacan_ai_current_gemini_model = $options['gemini_model'] ?? 'gemini-1.5-pro';
                            foreach ($tainacan_ai_gemini_models as $tainacan_ai_value => $tainacan_ai_label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($tainacan_ai_value),
                                    selected($tainacan_ai_current_gemini_model, $tainacan_ai_value, false),
                                    esc_html($tainacan_ai_label)
                                );
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Seção: DeepSeek -->
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-deepseek" <?php echo $tainacan_ai_current_provider !== 'deepseek' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2><?php esc_html_e('DeepSeek Configuration', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($tainacan_ai_provider_status['deepseek']): ?>
                        <span class="tainacan-ai-badge success"><?php esc_html_e('Configured', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php esc_html_e('Pending', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-prompt-info warning">
                        <span class="dashicons dashicons-warning"></span>
                        <p>
                            <?php
                                echo wp_kses(
                                    __(' <strong>Warning:</strong> DeepSeek does not support image analysis. Only documents with extractable text (PDFs with text, TXT) can be analyzed.', 'tainacan-ai'),
                                    array(
                                        'strong' => array()
                                    )
                                );
                            ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="deepseek_api_key">
                            <?php esc_html_e('DeepSeek API Key', 'tainacan-ai'); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="tainacan-ai-input-group">
                            <input
                                type="password"
                                id="deepseek_api_key"
                                name="tainacan_ai_options[deepseek_api_key]"
                                value="<?php echo esc_attr($options['deepseek_api_key'] ?? ''); ?>"
                                class="regular-text"
                                autocomplete="off"
                                placeholder="sk-..."
                            />
                            <button type="button" class="button toggle-password" data-target="deepseek_api_key">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <button type="button" class="button button-secondary test-api-btn" data-provider="deepseek">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e('Test', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php
                            echo wp_kses_post(
                                sprintf(
                                    /* translators: %s: link to DeepSeek platform */
                                    esc_html__('Get your key at %s.', 'tainacan-ai'),
                                    '<a href="https://platform.deepseek.com/api_keys" target="_blank">platform.deepseek.com</a>'
                                )
                            ); ?>
                        </p>
                        <div class="api-test-result" data-provider="deepseek" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="deepseek_model"><?php esc_html_e('Model', 'tainacan-ai'); ?></label>
                        <select id="deepseek_model" name="tainacan_ai_options[deepseek_model]">
                            <?php
                            $tainacan_ai_deepseek_models = $tainacan_ai_providers['deepseek']['models'] ?? [];
                            $tainacan_ai_current_deepseek_model = $options['deepseek_model'] ?? 'deepseek-chat';
                            foreach ($tainacan_ai_deepseek_models as $tainacan_ai_value => $tainacan_ai_label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($tainacan_ai_value),
                                    selected($tainacan_ai_current_deepseek_model, $tainacan_ai_value, false),
                                    esc_html($tainacan_ai_label)
                                );
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Seção: Ollama -->
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-ollama" <?php echo $tainacan_ai_current_provider !== 'ollama' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-desktop"></span>
                        <h2><?php esc_html_e('Ollama Configuration (Local)', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($tainacan_ai_provider_status['ollama']): ?>
                        <span class="tainacan-ai-badge success"><?php esc_html_e('Configured', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php esc_html_e('Pending', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-info"></span>
                        <p>
                            <?php
                                echo wp_kses(
                                    __('<strong>Ollama</strong> allows you to run AI models locally, without API costs. You need to have Ollama installed and running on the server.', 'tainacan-ai'),
                                    array( 'strong' => array() )
                                );
                            ?>
                            <br>
                            <?php
                            echo wp_kses_post(
                                sprintf(
                                    /* translators: %s: link to Ollama download page */
                                    esc_html__('Install at: %s', 'tainacan-ai'),
                                    '<a href="https://ollama.com/download" target="_blank">ollama.com/download</a>'
                                )
                            ); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="ollama_url">
                            <?php esc_html_e('Ollama URL', 'tainacan-ai'); ?>
                            <span class="required">*</span>
                        </label>
                        <div class="tainacan-ai-input-group">
                            <input
                                type="text"
                                id="ollama_url"
                                name="tainacan_ai_options[ollama_url]"
                                value="<?php echo esc_attr($options['ollama_url'] ?? 'http://localhost:11434'); ?>"
                                class="regular-text"
                                placeholder="http://localhost:11434"
                            />
                            <button type="button" class="button button-secondary test-api-btn" data-provider="ollama">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e('Test', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php esc_html_e('URL where Ollama is running. Default: http://localhost:11434', 'tainacan-ai'); ?>
                        </p>
                        <div class="api-test-result" data-provider="ollama" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="ollama_model"><?php esc_html_e('Model', 'tainacan-ai'); ?></label>
                        <select id="ollama_model" name="tainacan_ai_options[ollama_model]">
                            <?php
                            $tainacan_ai_ollama_models = $tainacan_ai_providers['ollama']['models'] ?? [];
                            $tainacan_ai_current_ollama_model = $options['ollama_model'] ?? 'llama3.2';
                            foreach ($tainacan_ai_ollama_models as $tainacan_ai_value => $tainacan_ai_label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($tainacan_ai_value),
                                    selected($tainacan_ai_current_ollama_model, $tainacan_ai_value, false),
                                    esc_html($tainacan_ai_label)
                                );
                            }
                            ?>
                        </select>
                        <p class="description">
                            <?php
                            echo wp_kses(
                                __('Use <code>llama3.2-vision</code> or <code>llava</code> for image analysis.', 'tainacan-ai'),
                                array( 'code' => array() )
                            );
                            ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-prompt-info warning">
                        <span class="dashicons dashicons-warning"></span>
                        <p>
                            <?php
                            echo wp_kses(
                                __('Make sure the model is installed. Run in terminal: <code>ollama pull llama3.2</code>', 'tainacan-ai'),
                                array( 'code' => array() )
                            );
                            ?>
                        </p>
                    </div>
                </div>
            </div>

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
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-info"></span>
                        <p>
                            <?php esc_html_e('Prompts define how the AI should analyze documents. Use clear instructions and specify the JSON fields you want to extract.', 'tainacan-ai'); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="default_image_prompt">
                            <span class="dashicons dashicons-format-image"></span>
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
                            <span class="dashicons dashicons-media-document"></span>
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
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-lightbulb"></span>
                        <p>
                            <?php esc_html_e('Configure specific prompts for each collection.', 'tainacan-ai'); ?>
                        </p>
                    </div>

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
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-info"></span>
                        <p>
                            <?php esc_html_e('Configure the mapping between AI-extracted fields and your collection metadata. This allows the "Fill Fields" button to work correctly.', 'tainacan-ai'); ?>
                        </p>
                    </div>

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
                            <div class="tainacan-ai-mapping-col"><?php esc_html_e('Tainacan Metadata', 'tainacan-ai'); ?></div>
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

                        <div class="tainacan-ai-prompt-info" style="margin-top: 15px;">
                            <span class="dashicons dashicons-lightbulb"></span>
                            <p>
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
            </div>

            <!-- Seção: Capacidades do Sistema -->
            <div class="tainacan-ai-card">
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <h2><?php esc_html_e('System Capabilities', 'tainacan-ai'); ?></h2>
                    </div>
                    <button type="button" class="tainacan-ai-toggle-card">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                </div>
                <div class="tainacan-ai-card-body tainacan-ai-collapsible">
                    <div class="tainacan-ai-dep-grid">
                        <!-- Parser PDF Embutido -->
                        <div class="tainacan-ai-dep-item installed">
                            <div class="tainacan-ai-dep-icon">
                                <span class="dashicons dashicons-yes"></span>
                            </div>
                            <div class="tainacan-ai-dep-content">
                                <div class="tainacan-ai-dep-header">
                                    <span class="tainacan-ai-dep-name"><?php esc_html_e('Text Extraction (Built-in)', 'tainacan-ai'); ?></span>
                                    <span class="tainacan-ai-dep-status"><?php esc_html_e('Active', 'tainacan-ai'); ?></span>
                                </div>
                                <p class="tainacan-ai-dep-desc">
                                    <?php esc_html_e('Native PDF parser of the plugin.', 'tainacan-ai'); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Análise Visual -->
                        <?php $tainacan_ai_has_visual = $tainacan_ai_has_imagick_pdf || $tainacan_ai_has_ghostscript; ?>
                        <div class="tainacan-ai-dep-item <?php echo $tainacan_ai_has_visual ? 'installed' : 'missing'; ?>">
                            <div class="tainacan-ai-dep-icon">
                                <span class="dashicons dashicons-<?php echo $tainacan_ai_has_visual ? 'yes' : 'warning'; ?>"></span>
                            </div>
                            <div class="tainacan-ai-dep-content">
                                <div class="tainacan-ai-dep-header">
                                    <span class="tainacan-ai-dep-name"><?php esc_html_e('Visual PDF Analysis', 'tainacan-ai'); ?></span>
                                    <span class="tainacan-ai-dep-status">
                                        <?php echo $tainacan_ai_has_visual ? esc_html__('Available', 'tainacan-ai') : esc_html__('Unavailable', 'tainacan-ai'); ?>
                                    </span>
                                </div>
                                <p class="tainacan-ai-dep-desc">
                                    <?php esc_html_e('Converts scanned PDFs to images.', 'tainacan-ai'); ?>
                                </p>
                                <div class="tainacan-ai-dep-methods">
                                    <small>
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
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- EXIF -->
                        <div class="tainacan-ai-dep-item <?php echo $tainacan_ai_has_exif ? 'installed' : 'missing'; ?>">
                            <div class="tainacan-ai-dep-icon">
                                <span class="dashicons dashicons-<?php echo $tainacan_ai_has_exif ? 'yes' : 'warning'; ?>"></span>
                            </div>
                            <div class="tainacan-ai-dep-content">
                                <div class="tainacan-ai-dep-header">
                                    <span class="tainacan-ai-dep-name"><?php esc_html_e('EXIF Extraction', 'tainacan-ai'); ?></span>
                                    <span class="tainacan-ai-dep-status">
                                        <?php echo $tainacan_ai_has_exif ? esc_html__('Active', 'tainacan-ai') : esc_html__('Missing', 'tainacan-ai'); ?>
                                    </span>
                                </div>
                                <p class="tainacan-ai-dep-desc">
                                    <?php esc_html_e('Extracts technical metadata from images.', 'tainacan-ai'); ?>
                                </p>
                            </div>
                        </div>
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
                                name="tainacan_ai_options[log_enabled]"
                                value="1"
                                <?php checked(!empty($options['log_enabled'])); ?>
                            />
                            <span class="tainacan-ai-checkbox-content">
                                <span class="tainacan-ai-checkbox-title">
                                    <span class="dashicons dashicons-list-view"></span>
                                    <?php esc_html_e('Log Usage', 'tainacan-ai'); ?>
                                </span>
                                <span class="tainacan-ai-checkbox-desc">
                                    <?php esc_html_e('Keeps analysis history', 'tainacan-ai'); ?>
                                </span>
                            </span>
                        </label>

                        <label class="tainacan-ai-checkbox">
                            <input
                                type="checkbox"
                                name="tainacan_ai_options[cost_tracking]"
                                value="1"
                                <?php checked(!empty($options['cost_tracking'])); ?>
                            />
                            <span class="tainacan-ai-checkbox-content">
                                <span class="tainacan-ai-checkbox-title">
                                    <span class="dashicons dashicons-money-alt"></span>
                                    <?php esc_html_e('Track Costs', 'tainacan-ai'); ?>
                                </span>
                                <span class="tainacan-ai-checkbox-desc">
                                    <?php esc_html_e('Calculates estimated cost of analyses', 'tainacan-ai'); ?>
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

            <!-- Ações -->
            <div class="tainacan-ai-actions-bar">
                <?php submit_button(esc_html__('Save Settings', 'tainacan-ai'), 'primary large', 'submit', false); ?>
                <button type="button" class="button button-secondary" id="clear-all-cache">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e('Clear Cache', 'tainacan-ai'); ?>
                </button>
            </div>
        </form>

        <!-- Sidebar -->
        <div class="tainacan-ai-sidebar">
            <div class="tainacan-ai-info-box">
                <h3>
                    <span class="dashicons dashicons-cloud"></span>
                    <?php esc_html_e('AI Providers', 'tainacan-ai'); ?>
                </h3>
                <ul>
                    <li><strong>OpenAI:</strong> <?php esc_html_e('GPT-4o with Vision (images and text)', 'tainacan-ai'); ?></li>
                    <li><strong>Gemini:</strong> <?php esc_html_e('Google AI with Vision (images and text)', 'tainacan-ai'); ?></li>
                    <li><strong>DeepSeek:</strong> <?php esc_html_e('Cost-effective alternative (text only)', 'tainacan-ai'); ?></li>
                    <li><strong>Ollama:</strong> <?php esc_html_e('Free local AI (Llama, Mistral, etc)', 'tainacan-ai'); ?></li>
                </ul>
            </div>

            <div class="tainacan-ai-info-box">
                <h3>
                    <span class="dashicons dashicons-book"></span>
                    <?php esc_html_e('How to Use', 'tainacan-ai'); ?>
                </h3>
                <ol>
                    <li><?php esc_html_e('Choose an AI provider', 'tainacan-ai'); ?></li>
                    <li><?php esc_html_e('Configure the API key', 'tainacan-ai'); ?></li>
                    <li><?php esc_html_e('Customize the prompts', 'tainacan-ai'); ?></li>
                    <li><?php esc_html_e('Edit an item in Tainacan', 'tainacan-ai'); ?></li>
                    <li><?php esc_html_e('Click "Analyze Document"', 'tainacan-ai'); ?></li>
                </ol>
            </div>

            <div class="tainacan-ai-info-box">
                <h3>
                    <span class="dashicons dashicons-media-default"></span>
                    <?php esc_html_e('Supported Formats', 'tainacan-ai'); ?>
                </h3>
                <div class="tainacan-ai-formats">
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
    </div>
</div>

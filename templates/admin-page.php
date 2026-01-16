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
$providers = AIProviderFactory::get_available_providers();
$current_provider = $options['ai_provider'] ?? 'openai';

// Check configuration for each provider
$provider_status = [
    'openai' => !empty($options['api_key']),
    'gemini' => !empty($options['gemini_api_key']),
    'deepseek' => !empty($options['deepseek_api_key']),
    'ollama' => !empty($options['ollama_url']),
];

$is_configured = $provider_status[$current_provider] ?? false;

// Check dependencies
$has_exif = function_exists('exif_read_data');
$has_pdfparser = class_exists('\Smalot\PdfParser\Parser') || file_exists(TAINACAN_AI_PLUGIN_DIR . 'vendor/autoload.php');

// Check visual PDF analysis capabilities
$has_imagick = extension_loaded('imagick');
$has_imagick_pdf = false;
if ($has_imagick) {
    try {
        $imagick = new \Imagick();
        $formats = $imagick->queryFormats('PDF');
        $has_imagick_pdf = !empty($formats);
    } catch (\Exception $e) {
        $has_imagick_pdf = false;
    }
}

// Check Ghostscript
$has_ghostscript = false;
$gs_path = null;
if (function_exists('shell_exec')) {
    if (PHP_OS_FAMILY === 'Windows') {
        $output = @shell_exec('where gswin64c 2>nul');
        if (empty($output)) {
            $output = @shell_exec('where gswin32c 2>nul');
        }
        if (!empty($output)) {
            $has_ghostscript = true;
            $gs_path = trim($output);
        }
    } else {
        $output = @shell_exec('which gs 2>/dev/null');
        if (!empty($output)) {
            $has_ghostscript = true;
            $gs_path = trim($output);
        }
    }
}

$has_builtin_parser = true;
?>

<div class="wrap tainacan-page-container-content tainacan-ai-admin">
    <div class="tainacan-fixed-subheader">
        <h1 class="tainacan-page-title">
            <svg class="tainacan-ai-title-icon" viewBox="0 0 24 24" width="32" height="32" fill="currentColor">
                <path d="M22.282 9.821a5.985 5.985 0 0 0-.516-4.91 6.046 6.046 0 0 0-6.51-2.9A6.065 6.065 0 0 0 4.981 4.18a5.985 5.985 0 0 0-3.998 2.9 6.046 6.046 0 0 0 .743 7.097 5.98 5.98 0 0 0 .51 4.911 6.051 6.051 0 0 0 6.515 2.9A5.985 5.985 0 0 0 13.26 24a6.056 6.056 0 0 0 5.772-4.206 5.99 5.99 0 0 0 3.997-2.9 6.056 6.056 0 0 0-.747-7.073z"/>
            </svg>
            <?php _e('Tainacan AI Tools', 'tainacan-ai'); ?>
            <span class="tainacan-ai-version">v<?php echo TAINACAN_AI_VERSION; ?></span>
        </h1>
    </div>

    <!-- Stats Cards -->
    <?php if ($is_configured && !empty($stats)): ?>
    <div class="tainacan-ai-stats-grid">
        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon">
                <span class="dashicons dashicons-chart-bar"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value"><?php echo number_format($stats['total_analyses']); ?></span>
                <span class="tainacan-ai-stat-label"><?php _e('Analyses (30 days)', 'tainacan-ai'); ?></span>
            </div>
        </div>

        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon">
                <span class="dashicons dashicons-performance"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value"><?php echo number_format($stats['total_tokens']); ?></span>
                <span class="tainacan-ai-stat-label"><?php _e('Tokens Used', 'tainacan-ai'); ?></span>
            </div>
        </div>

        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon success">
                <span class="dashicons dashicons-yes-alt"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value"><?php echo $stats['success_rate']; ?>%</span>
                <span class="tainacan-ai-stat-label"><?php _e('Taxa de Sucesso', 'tainacan-ai'); ?></span>
            </div>
        </div>

        <div class="tainacan-ai-stat-card">
            <div class="tainacan-ai-stat-icon">
                <span class="dashicons dashicons-money-alt"></span>
            </div>
            <div class="tainacan-ai-stat-content">
                <span class="tainacan-ai-stat-value">$<?php echo number_format($stats['total_cost'], 4); ?></span>
                <span class="tainacan-ai-stat-label"><?php _e('Custo Estimado', 'tainacan-ai'); ?></span>
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
                        <h2><?php _e('Provedor de IA', 'tainacan-ai'); ?></h2>
                    </div>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-provider-selector">
                        <?php foreach ($providers as $id => $provider): ?>
                        <label class="tainacan-ai-provider-option <?php echo $current_provider === $id ? 'selected' : ''; ?>">
                            <input
                                type="radio"
                                name="tainacan_ai_options[ai_provider]"
                                value="<?php echo esc_attr($id); ?>"
                                <?php checked($current_provider, $id); ?>
                            />
                            <div class="tainacan-ai-provider-content">
                                <span class="tainacan-ai-provider-name"><?php echo esc_html($provider['name']); ?></span>
                                <span class="tainacan-ai-provider-features">
                                    <?php if ($provider['supports_vision']): ?>
                                        <span class="tainacan-ai-feature-badge success" title="<?php esc_attr_e('Suporta análise de imagens', 'tainacan-ai'); ?>">
                                            <span class="dashicons dashicons-format-image"></span>
                                            <?php _e('Vision', 'tainacan-ai'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="tainacan-ai-feature-badge warning" title="<?php esc_attr_e('Apenas texto', 'tainacan-ai'); ?>">
                                            <span class="dashicons dashicons-text"></span>
                                            <?php _e('Texto', 'tainacan-ai'); ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($provider_status[$id]): ?>
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
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-openai" <?php echo $current_provider !== 'openai' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2><?php _e('Configuração OpenAI', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($provider_status['openai']): ?>
                        <span class="tainacan-ai-badge success"><?php _e('Configurado', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php _e('Pendente', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-field">
                        <label for="api_key">
                            <?php _e('Chave da API OpenAI', 'tainacan-ai'); ?>
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
                                <?php _e('Testar', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php printf(
                                __('Obtenha sua chave em %s.', 'tainacan-ai'),
                                '<a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a>'
                            ); ?>
                        </p>
                        <div class="api-test-result" data-provider="openai" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="model"><?php _e('Modelo', 'tainacan-ai'); ?></label>
                        <select id="model" name="tainacan_ai_options[model]">
                            <?php
                            $openai_models = $providers['openai']['models'] ?? [];
                            $current_model = $options['model'] ?? 'gpt-4o';
                            foreach ($openai_models as $value => $label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($value),
                                    selected($current_model, $value, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Seção: Google Gemini -->
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-gemini" <?php echo $current_provider !== 'gemini' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2><?php _e('Configuração Google Gemini', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($provider_status['gemini']): ?>
                        <span class="tainacan-ai-badge success"><?php _e('Configurado', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php _e('Pendente', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-field">
                        <label for="gemini_api_key">
                            <?php _e('Chave da API Google AI', 'tainacan-ai'); ?>
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
                                <?php _e('Testar', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php printf(
                                __('Obtenha sua chave em %s.', 'tainacan-ai'),
                                '<a href="https://aistudio.google.com/app/apikey" target="_blank">Google AI Studio</a>'
                            ); ?>
                        </p>
                        <div class="api-test-result" data-provider="gemini" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="gemini_model"><?php _e('Modelo', 'tainacan-ai'); ?></label>
                        <select id="gemini_model" name="tainacan_ai_options[gemini_model]">
                            <?php
                            $gemini_models = $providers['gemini']['models'] ?? [];
                            $current_gemini_model = $options['gemini_model'] ?? 'gemini-1.5-pro';
                            foreach ($gemini_models as $value => $label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($value),
                                    selected($current_gemini_model, $value, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Seção: DeepSeek -->
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-deepseek" <?php echo $current_provider !== 'deepseek' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-admin-network"></span>
                        <h2><?php _e('Configuração DeepSeek', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($provider_status['deepseek']): ?>
                        <span class="tainacan-ai-badge success"><?php _e('Configurado', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php _e('Pendente', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-prompt-info warning">
                        <span class="dashicons dashicons-warning"></span>
                        <p>
                            <?php _e('<strong>Atenção:</strong> O DeepSeek não suporta análise de imagens. Apenas documentos com texto extraível (PDFs com texto, TXT) podem ser analisados.', 'tainacan-ai'); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="deepseek_api_key">
                            <?php _e('Chave da API DeepSeek', 'tainacan-ai'); ?>
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
                                <?php _e('Testar', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php printf(
                                __('Obtenha sua chave em %s.', 'tainacan-ai'),
                                '<a href="https://platform.deepseek.com/api_keys" target="_blank">platform.deepseek.com</a>'
                            ); ?>
                        </p>
                        <div class="api-test-result" data-provider="deepseek" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="deepseek_model"><?php _e('Modelo', 'tainacan-ai'); ?></label>
                        <select id="deepseek_model" name="tainacan_ai_options[deepseek_model]">
                            <?php
                            $deepseek_models = $providers['deepseek']['models'] ?? [];
                            $current_deepseek_model = $options['deepseek_model'] ?? 'deepseek-chat';
                            foreach ($deepseek_models as $value => $label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($value),
                                    selected($current_deepseek_model, $value, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Seção: Ollama -->
            <div class="tainacan-ai-card tainacan-ai-provider-config" id="provider-config-ollama" <?php echo $current_provider !== 'ollama' ? 'style="display:none;"' : ''; ?>>
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-desktop"></span>
                        <h2><?php _e('Configuração Ollama (Local)', 'tainacan-ai'); ?></h2>
                    </div>
                    <?php if ($provider_status['ollama']): ?>
                        <span class="tainacan-ai-badge success"><?php _e('Configurado', 'tainacan-ai'); ?></span>
                    <?php else: ?>
                        <span class="tainacan-ai-badge warning"><?php _e('Pendente', 'tainacan-ai'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-info"></span>
                        <p>
                            <?php _e('<strong>Ollama</strong> permite executar modelos de IA localmente, sem custos de API. Você precisa ter o Ollama instalado e rodando no servidor.', 'tainacan-ai'); ?>
                            <br>
                            <?php printf(
                                __('Instale em: %s', 'tainacan-ai'),
                                '<a href="https://ollama.com/download" target="_blank">ollama.com/download</a>'
                            ); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="ollama_url">
                            <?php _e('URL do Ollama', 'tainacan-ai'); ?>
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
                                <?php _e('Testar', 'tainacan-ai'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php _e('URL onde o Ollama está rodando. Padrão: http://localhost:11434', 'tainacan-ai'); ?>
                        </p>
                        <div class="api-test-result" data-provider="ollama" style="display: none;"></div>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="ollama_model"><?php _e('Modelo', 'tainacan-ai'); ?></label>
                        <select id="ollama_model" name="tainacan_ai_options[ollama_model]">
                            <?php
                            $ollama_models = $providers['ollama']['models'] ?? [];
                            $current_ollama_model = $options['ollama_model'] ?? 'llama3.2';
                            foreach ($ollama_models as $value => $label) {
                                printf(
                                    '<option value="%s" %s>%s</option>',
                                    esc_attr($value),
                                    selected($current_ollama_model, $value, false),
                                    esc_html($label)
                                );
                            }
                            ?>
                        </select>
                        <p class="description">
                            <?php _e('Use <code>llama3.2-vision</code> ou <code>llava</code> para análise de imagens.', 'tainacan-ai'); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-prompt-info warning">
                        <span class="dashicons dashicons-warning"></span>
                        <p>
                            <?php _e('Certifique-se de que o modelo está instalado. Execute no terminal: <code>ollama pull llama3.2</code>', 'tainacan-ai'); ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Seção: Prompts Padrão -->
            <div class="tainacan-ai-card">
                <div class="tainacan-ai-card-header">
                    <div class="tainacan-ai-card-title">
                        <span class="dashicons dashicons-edit-page"></span>
                        <h2><?php _e('Prompts de Análise Padrão', 'tainacan-ai'); ?></h2>
                    </div>
                    <button type="button" class="tainacan-ai-toggle-card">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                </div>
                <div class="tainacan-ai-card-body">
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-info"></span>
                        <p>
                            <?php _e('Os prompts definem como a IA deve analisar os documentos. Use instruções claras e especifique os campos JSON que deseja extrair.', 'tainacan-ai'); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="default_image_prompt">
                            <span class="dashicons dashicons-format-image"></span>
                            <?php _e('Prompt para Imagens', 'tainacan-ai'); ?>
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
                            <?php _e('Prompt para Documentos', 'tainacan-ai'); ?>
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
                        <h2><?php _e('Prompts por Coleção', 'tainacan-ai'); ?></h2>
                    </div>
                    <button type="button" class="tainacan-ai-toggle-card">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                </div>
                <div class="tainacan-ai-card-body tainacan-ai-collapsible">
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-lightbulb"></span>
                        <p>
                            <?php _e('Configure prompts específicos para cada coleção.', 'tainacan-ai'); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-collection-prompts" id="collection-prompts-container">
                        <div class="tainacan-ai-field">
                            <label for="collection-select"><?php _e('Selecione uma Coleção', 'tainacan-ai'); ?></label>
                            <select id="collection-select" class="regular-text">
                                <option value=""><?php _e('-- Selecione --', 'tainacan-ai'); ?></option>
                            </select>
                        </div>

                        <div id="collection-prompt-editor" style="display: none;">
                            <div class="tainacan-ai-field-row">
                                <div class="tainacan-ai-field">
                                    <label><?php _e('Tipo de Prompt', 'tainacan-ai'); ?></label>
                                    <div class="tainacan-ai-radio-group">
                                        <label>
                                            <input type="radio" name="collection_prompt_type" value="image" checked>
                                            <span class="dashicons dashicons-format-image"></span>
                                            <?php _e('Imagem', 'tainacan-ai'); ?>
                                        </label>
                                        <label>
                                            <input type="radio" name="collection_prompt_type" value="document">
                                            <span class="dashicons dashicons-media-document"></span>
                                            <?php _e('Documento', 'tainacan-ai'); ?>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="tainacan-ai-field">
                                <div class="tainacan-ai-field-header">
                                    <label for="collection-prompt-text"><?php _e('Prompt Personalizado', 'tainacan-ai'); ?></label>
                                    <button type="button" class="button button-small" id="generate-prompt-suggestion">
                                        <span class="dashicons dashicons-lightbulb"></span>
                                        <?php _e('Gerar Sugestão', 'tainacan-ai'); ?>
                                    </button>
                                </div>
                                <textarea
                                    id="collection-prompt-text"
                                    rows="10"
                                    class="large-text code"
                                    placeholder="<?php esc_attr_e('Deixe em branco para usar o prompt padrão...', 'tainacan-ai'); ?>"
                                ></textarea>
                            </div>

                            <div class="tainacan-ai-collection-actions">
                                <button type="button" class="button button-primary" id="save-collection-prompt">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php _e('Salvar Prompt', 'tainacan-ai'); ?>
                                </button>
                                <button type="button" class="button" id="reset-collection-prompt">
                                    <span class="dashicons dashicons-undo"></span>
                                    <?php _e('Resetar para Padrão', 'tainacan-ai'); ?>
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
                        <h2><?php _e('Mapeamento de Campos', 'tainacan-ai'); ?></h2>
                    </div>
                    <button type="button" class="tainacan-ai-toggle-card">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                </div>
                <div class="tainacan-ai-card-body tainacan-ai-collapsible">
                    <div class="tainacan-ai-prompt-info">
                        <span class="dashicons dashicons-info"></span>
                        <p>
                            <?php _e('Configure o mapeamento entre os campos extraídos pela IA e os metadados da sua coleção. Isso permite que o botão "Preencher Campos" funcione corretamente.', 'tainacan-ai'); ?>
                        </p>
                    </div>

                    <div class="tainacan-ai-field">
                        <label for="mapping-collection-select"><?php _e('Selecione uma Coleção', 'tainacan-ai'); ?></label>
                        <select id="mapping-collection-select" class="regular-text">
                            <option value=""><?php _e('-- Selecione --', 'tainacan-ai'); ?></option>
                            <?php
                            // Popula coleções diretamente no PHP
                            if (class_exists('\Tainacan\Repositories\Collections')) {
                                $collections_repo = \Tainacan\Repositories\Collections::get_instance();
                                $collections = $collections_repo->fetch([], 'OBJECT');
                                if (is_array($collections)) {
                                    foreach ($collections as $collection) {
                                        printf(
                                            '<option value="%d">%s</option>',
                                            $collection->get_id(),
                                            esc_html($collection->get_name())
                                        );
                                    }
                                }
                            }
                            ?>
                        </select>
                    </div>

                    <div id="metadata-mapping-editor" style="display: none;">
                        <div class="tainacan-ai-mapping-header">
                            <div class="tainacan-ai-mapping-col"><?php _e('Campo da IA', 'tainacan-ai'); ?></div>
                            <div class="tainacan-ai-mapping-col"><?php _e('Metadado do Tainacan', 'tainacan-ai'); ?></div>
                        </div>
                        <div id="metadata-mapping-list" class="tainacan-ai-mapping-list">
                            <!-- Preenchido via JavaScript -->
                        </div>

                        <div class="tainacan-ai-mapping-actions">
                            <button type="button" class="button button-primary" id="save-metadata-mapping">
                                <span class="dashicons dashicons-saved"></span>
                                <?php _e('Salvar Mapeamento', 'tainacan-ai'); ?>
                            </button>
                            <button type="button" class="button" id="auto-detect-mapping">
                                <span class="dashicons dashicons-admin-generic"></span>
                                <?php _e('Auto-detectar', 'tainacan-ai'); ?>
                            </button>
                            <button type="button" class="button" id="clear-mapping">
                                <span class="dashicons dashicons-trash"></span>
                                <?php _e('Limpar Mapeamento', 'tainacan-ai'); ?>
                            </button>
                        </div>

                        <div class="tainacan-ai-prompt-info" style="margin-top: 15px;">
                            <span class="dashicons dashicons-lightbulb"></span>
                            <p>
                                <?php _e('<strong>Dica:</strong> Os campos da IA são definidos no seu prompt. Configure o prompt para retornar os campos desejados e mapeie-os aqui.', 'tainacan-ai'); ?>
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
                        <h2><?php _e('Capacidades do Sistema', 'tainacan-ai'); ?></h2>
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
                                    <span class="tainacan-ai-dep-name"><?php _e('Extração de Texto (Embutido)', 'tainacan-ai'); ?></span>
                                    <span class="tainacan-ai-dep-status"><?php _e('Ativo', 'tainacan-ai'); ?></span>
                                </div>
                                <p class="tainacan-ai-dep-desc">
                                    <?php _e('Parser PDF nativo do plugin.', 'tainacan-ai'); ?>
                                </p>
                            </div>
                        </div>

                        <!-- Análise Visual -->
                        <?php $has_visual = $has_imagick_pdf || $has_ghostscript; ?>
                        <div class="tainacan-ai-dep-item <?php echo $has_visual ? 'installed' : 'missing'; ?>">
                            <div class="tainacan-ai-dep-icon">
                                <span class="dashicons dashicons-<?php echo $has_visual ? 'yes' : 'warning'; ?>"></span>
                            </div>
                            <div class="tainacan-ai-dep-content">
                                <div class="tainacan-ai-dep-header">
                                    <span class="tainacan-ai-dep-name"><?php _e('Análise Visual de PDFs', 'tainacan-ai'); ?></span>
                                    <span class="tainacan-ai-dep-status">
                                        <?php echo $has_visual ? __('Disponível', 'tainacan-ai') : __('Indisponível', 'tainacan-ai'); ?>
                                    </span>
                                </div>
                                <p class="tainacan-ai-dep-desc">
                                    <?php _e('Converte PDFs escaneados em imagens.', 'tainacan-ai'); ?>
                                </p>
                                <div class="tainacan-ai-dep-methods">
                                    <small>
                                        <strong><?php _e('Backends:', 'tainacan-ai'); ?></strong>
                                        <?php if ($has_imagick_pdf): ?>
                                            <span class="tainacan-ai-method-available">Imagick</span>
                                        <?php else: ?>
                                            <span class="tainacan-ai-method-missing">Imagick</span>
                                        <?php endif; ?>
                                        <?php if ($has_ghostscript): ?>
                                            <span class="tainacan-ai-method-available">Ghostscript</span>
                                        <?php else: ?>
                                            <span class="tainacan-ai-method-missing">Ghostscript</span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- EXIF -->
                        <div class="tainacan-ai-dep-item <?php echo $has_exif ? 'installed' : 'missing'; ?>">
                            <div class="tainacan-ai-dep-icon">
                                <span class="dashicons dashicons-<?php echo $has_exif ? 'yes' : 'warning'; ?>"></span>
                            </div>
                            <div class="tainacan-ai-dep-content">
                                <div class="tainacan-ai-dep-header">
                                    <span class="tainacan-ai-dep-name"><?php _e('Extração EXIF', 'tainacan-ai'); ?></span>
                                    <span class="tainacan-ai-dep-status">
                                        <?php echo $has_exif ? __('Ativo', 'tainacan-ai') : __('Ausente', 'tainacan-ai'); ?>
                                    </span>
                                </div>
                                <p class="tainacan-ai-dep-desc">
                                    <?php _e('Extrai metadados técnicos de imagens.', 'tainacan-ai'); ?>
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
                        <h2><?php _e('Funcionalidades', 'tainacan-ai'); ?></h2>
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
                                    <?php _e('Extrair Dados EXIF', 'tainacan-ai'); ?>
                                </span>
                                <span class="tainacan-ai-checkbox-desc">
                                    <?php _e('Extrai metadados técnicos de imagens', 'tainacan-ai'); ?>
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
                                    <?php _e('Registrar Uso', 'tainacan-ai'); ?>
                                </span>
                                <span class="tainacan-ai-checkbox-desc">
                                    <?php _e('Mantém histórico de análises', 'tainacan-ai'); ?>
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
                                    <?php _e('Rastrear Custos', 'tainacan-ai'); ?>
                                </span>
                                <span class="tainacan-ai-checkbox-desc">
                                    <?php _e('Calcula custo estimado das análises', 'tainacan-ai'); ?>
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
                                    <?php _e('Requer Consentimento', 'tainacan-ai'); ?>
                                </span>
                                <span class="tainacan-ai-checkbox-desc">
                                    <?php _e('Integra com WP Consent API', 'tainacan-ai'); ?>
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
                        <h2><?php _e('Configurações Avançadas', 'tainacan-ai'); ?></h2>
                    </div>
                    <button type="button" class="tainacan-ai-toggle-card">
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                </div>
                <div class="tainacan-ai-card-body tainacan-ai-collapsible collapsed">
                    <div class="tainacan-ai-field-grid">
                        <div class="tainacan-ai-field">
                            <label for="max_tokens"><?php _e('Máximo de Tokens', 'tainacan-ai'); ?></label>
                            <input
                                type="number"
                                id="max_tokens"
                                name="tainacan_ai_options[max_tokens]"
                                value="<?php echo esc_attr($options['max_tokens'] ?? 2000); ?>"
                                min="100"
                                max="8000"
                            />
                            <p class="description"><?php _e('Limite de tokens na resposta (100-8000).', 'tainacan-ai'); ?></p>
                        </div>

                        <div class="tainacan-ai-field">
                            <label for="temperature"><?php _e('Temperatura', 'tainacan-ai'); ?></label>
                            <input
                                type="number"
                                id="temperature"
                                name="tainacan_ai_options[temperature]"
                                value="<?php echo esc_attr($options['temperature'] ?? 0.1); ?>"
                                min="0"
                                max="2"
                                step="0.1"
                            />
                            <p class="description"><?php _e('0 = determinístico, 2 = criativo.', 'tainacan-ai'); ?></p>
                        </div>

                        <div class="tainacan-ai-field">
                            <label for="request_timeout"><?php _e('Timeout (segundos)', 'tainacan-ai'); ?></label>
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
                            <label for="cache_duration"><?php _e('Duração do Cache (segundos)', 'tainacan-ai'); ?></label>
                            <input
                                type="number"
                                id="cache_duration"
                                name="tainacan_ai_options[cache_duration]"
                                value="<?php echo esc_attr($options['cache_duration'] ?? 3600); ?>"
                                min="0"
                                max="604800"
                            />
                            <p class="description"><?php _e('0 para desativar.', 'tainacan-ai'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ações -->
            <div class="tainacan-ai-actions-bar">
                <?php submit_button(__('Salvar Configurações', 'tainacan-ai'), 'primary large', 'submit', false); ?>
                <button type="button" class="button button-secondary" id="clear-all-cache">
                    <span class="dashicons dashicons-trash"></span>
                    <?php _e('Limpar Cache', 'tainacan-ai'); ?>
                </button>
            </div>
        </form>

        <!-- Sidebar -->
        <div class="tainacan-ai-sidebar">
            <div class="tainacan-ai-info-box">
                <h3>
                    <span class="dashicons dashicons-cloud"></span>
                    <?php _e('Provedores de IA', 'tainacan-ai'); ?>
                </h3>
                <ul>
                    <li><strong>OpenAI:</strong> <?php _e('GPT-4o com Vision (imagens e texto)', 'tainacan-ai'); ?></li>
                    <li><strong>Gemini:</strong> <?php _e('Google AI com Vision (imagens e texto)', 'tainacan-ai'); ?></li>
                    <li><strong>DeepSeek:</strong> <?php _e('Alternativa econômica (apenas texto)', 'tainacan-ai'); ?></li>
                    <li><strong>Ollama:</strong> <?php _e('IA local gratuita (Llama, Mistral, etc)', 'tainacan-ai'); ?></li>
                </ul>
            </div>

            <div class="tainacan-ai-info-box">
                <h3>
                    <span class="dashicons dashicons-book"></span>
                    <?php _e('Como Usar', 'tainacan-ai'); ?>
                </h3>
                <ol>
                    <li><?php _e('Escolha um provedor de IA', 'tainacan-ai'); ?></li>
                    <li><?php _e('Configure a chave API', 'tainacan-ai'); ?></li>
                    <li><?php _e('Personalize os prompts', 'tainacan-ai'); ?></li>
                    <li><?php _e('Edite um item no Tainacan', 'tainacan-ai'); ?></li>
                    <li><?php _e('Clique em "Analisar Documento"', 'tainacan-ai'); ?></li>
                </ol>
            </div>

            <div class="tainacan-ai-info-box">
                <h3>
                    <span class="dashicons dashicons-media-default"></span>
                    <?php _e('Formatos Suportados', 'tainacan-ai'); ?>
                </h3>
                <div class="tainacan-ai-formats">
                    <div class="tainacan-ai-format-group">
                        <strong><?php _e('Imagens:', 'tainacan-ai'); ?></strong>
                        <span>JPG, PNG, GIF, WebP</span>
                    </div>
                    <div class="tainacan-ai-format-group">
                        <strong><?php _e('Documentos:', 'tainacan-ai'); ?></strong>
                        <span>PDF, TXT, HTML</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

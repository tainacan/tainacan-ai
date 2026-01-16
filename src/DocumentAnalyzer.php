<?php
namespace Tainacan\AI;

if (!defined('ABSPATH')) {
    exit;
}

use Tainacan\AI\AI\AIProviderFactory;
use Tainacan\AI\AI\AIProviderInterface;
use Tainacan\AI\PdfParser\PdfParser;
use Tainacan\AI\PdfParser\PdfToImage;

/**
 * Document analyzer using multiple AI providers
 *
 * Analyzes images and documents extracting metadata via AI.
 * Supports OpenAI, Google Gemini and DeepSeek.
 *
 * @since 1.0.0 - Support for multiple AI providers
 */
class DocumentAnalyzer {

    private array $options;
    private ?int $collection_id = null;
    private ?int $item_id = null;
    private ExifExtractor $exif_extractor;
    private CollectionPrompts $collection_prompts;
    private UsageLogger $logger;
    private ?AIProviderInterface $provider = null;

    /**
     * Supported MIME types
     */
    private array $supported_image_types = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private array $supported_document_types = [
        'application/pdf',
        'text/plain',
        'text/html',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct() {
        $this->options = \Tainacan_AI::get_options();
        $this->exif_extractor = new ExifExtractor();
        $this->collection_prompts = new CollectionPrompts();
        $this->logger = new UsageLogger();
    }

    /**
     * Set analysis context
     */
    public function set_context(?int $collection_id = null, ?int $item_id = null): self {
        $this->collection_id = $collection_id;
        $this->item_id = $item_id;
        return $this;
    }

    /**
     * Get configured AI provider
     */
    private function get_provider(): ?AIProviderInterface {
        if ($this->provider === null) {
            $this->provider = AIProviderFactory::create_from_options();
        }
        return $this->provider;
    }

    /**
     * Analyze an attachment
     */
    public function analyze(int $attachment_id, bool $include_exif = true): array|\WP_Error {
        // Get provider
        $provider = $this->get_provider();

        if (!$provider) {
            return new \WP_Error(
                'no_provider',
                __('No AI provider configured. Go to Tainacan > AI Tools to configure.', 'tainacan-ai')
            );
        }

        if (!$provider->is_configured()) {
            return new \WP_Error(
                'no_api_key',
                sprintf(
                    /* translators: %s: provider name */
                    __('%s API key not configured. Go to Tainacan > AI Tools to configure.', 'tainacan-ai'),
                    $provider->get_name()
                )
            );
        }

        // Check consent
        if (!\Tainacan_AI::has_consent()) {
            return new \WP_Error('no_consent', __('Consent required to use AI features.', 'tainacan-ai'));
        }

        $file_path = get_attached_file($attachment_id);
        $mime_type = get_post_mime_type($attachment_id);

        if (!$file_path) {
            return new \WP_Error(
                'file_not_found',
                __('File path not found in WordPress. The attachment may have been removed.', 'tainacan-ai')
            );
        }

        // Normalize file path (fixes slash and _x_ issues)
        $file_path = $this->normalize_file_path($file_path);

        if (!file_exists($file_path)) {
            return new \WP_Error(
                'file_not_found',
                sprintf(
                    /* translators: %s: file path */
                    __('Physical file does not exist on server. Expected at: %s', 'tainacan-ai'),
                    $file_path
                )
            );
        }

        // Detect collection if not defined
        if (!$this->collection_id && $this->item_id) {
            $this->collection_id = $this->get_item_collection($this->item_id);
        }

        $result = [];
        $document_type = 'unknown';
        $extraction_method = null;

        // Analysis based on type
        if (in_array($mime_type, $this->supported_image_types)) {
            $document_type = 'image';

            // Extract EXIF first
            if ($include_exif && ($this->options['extract_exif'] ?? true)) {
                $exif_data = $this->exif_extractor->extract($file_path);
                if (!empty($exif_data['data'])) {
                    $result['exif'] = $exif_data['data'];
                    $result['exif_summary'] = $this->exif_extractor->get_summary($exif_data);
                }
            }

            // AI analysis
            $ai_result = $this->analyze_image($attachment_id, $file_path, $mime_type);
            $extraction_method = 'vision';

        } elseif ($mime_type === 'application/pdf') {
            $document_type = 'pdf';
            $pdf_result = $this->analyze_pdf_smart($file_path);
            $ai_result = $pdf_result['result'];
            $extraction_method = $pdf_result['method'];

        } elseif (in_array($mime_type, ['text/plain', 'text/html'])) {
            $document_type = 'text';
            $ai_result = $this->analyze_text(file_get_contents($file_path));
            $extraction_method = 'text';

        } else {
            return new \WP_Error(
                'unsupported_type',
                /* translators: %s: file type */
                sprintf(__('Unsupported file type: %s', 'tainacan-ai'), $mime_type)
            );
        }

        // Check for error in AI analysis
        if (is_wp_error($ai_result)) {
            $this->log_analysis($attachment_id, $document_type, 'error', $ai_result->get_error_message());
            return $ai_result;
        }

        // Combine results
        $result['ai_metadata'] = $ai_result['metadata'] ?? $ai_result;
        $result['document_type'] = $document_type;
        $result['extraction_method'] = $extraction_method;
        $result['tokens_used'] = $ai_result['usage']['total_tokens'] ?? 0;
        $result['model'] = $ai_result['model'] ?? '';
        $result['provider'] = $ai_result['provider'] ?? $provider->get_id();
        $result['analyzed_at'] = current_time('mysql');

        // Success log
        $cost = $provider->calculate_cost($ai_result['usage'] ?? [], $result['model']);
        $this->log_analysis($attachment_id, $document_type, 'success', null, $result['tokens_used'], $cost, $result['model']);

        return $result;
    }

    /**
     * Log analysis
     */
    private function log_analysis(
        int $attachment_id,
        string $document_type,
        string $status,
        ?string $error_message = null,
        int $tokens_used = 0,
        float $cost = 0,
        string $model = ''
    ): void {
        $provider = $this->get_provider();

        $this->logger->log([
            'user_id' => get_current_user_id(),
            'item_id' => $this->item_id,
            'collection_id' => $this->collection_id,
            'attachment_id' => $attachment_id,
            'document_type' => $document_type,
            'model' => $model ?: ($provider ? $provider->get_default_model() : 'unknown'),
            'provider' => $provider ? $provider->get_id() : 'openai',
            'tokens_used' => $tokens_used,
            'cost' => $cost,
            'status' => $status,
            'error_message' => $error_message,
        ]);
    }

    /**
     * Smart PDF analysis with multiple fallbacks
     */
    private function analyze_pdf_smart(string $file_path): array {
        $provider = $this->get_provider();

        // Method 1: Text extraction (faster and cheaper)
        $text = $this->extract_pdf_text($file_path);

        if (!is_wp_error($text) && !empty(trim($text)) && strlen(trim($text)) > 100) {
            $result = $this->analyze_text($text);
            return [
                'result' => $result,
                'method' => 'text_extraction',
            ];
        }

        // Method 2: Convert to image and visual analysis (if provider supports)
        if ($provider && $provider->supports_vision()) {
            $visual_result = $this->analyze_pdf_visually($file_path);

            if (!is_wp_error($visual_result)) {
                return [
                    'result' => $visual_result,
                    'method' => 'visual_analysis',
                ];
            }
        }

        // If both failed, return more informative error
        $error_msg = __('Could not analyze PDF. ', 'tainacan-ai');

        if (is_wp_error($text)) {
            $error_msg .= $text->get_error_message() . ' ';
        } else {
            $error_msg .= __('PDF does not contain extractable text. ', 'tainacan-ai');
        }

        if ($provider && !$provider->supports_vision()) {
            $error_msg .= sprintf(
                /* translators: %s: provider name */
                __('Provider %s does not support visual image analysis.', 'tainacan-ai'),
                $provider->get_name()
            );
        }

        return [
            'result' => new \WP_Error('pdf_analysis_failed', trim($error_msg)),
            'method' => 'failed',
        ];
    }

    /**
     * Analyze PDF visually (for scanned PDFs)
     */
    private function analyze_pdf_visually(string $file_path): array|\WP_Error {
        $provider = $this->get_provider();

        if (!$provider || !$provider->supports_vision()) {
            return new \WP_Error(
                'vision_not_supported',
                __('Current provider does not support visual analysis.', 'tainacan-ai')
            );
        }

        try {
            $converter = new PdfToImage();
            $converter->setDpi(150)
                      ->setQuality(85)
                      ->setMaxPages(3);

            $images = $converter->convert($file_path);

            if (empty($images)) {
                return new \WP_Error(
                    'conversion_failed',
                    __('Could not convert PDF to image. Install Imagick or Ghostscript.', 'tainacan-ai')
                );
            }

            // Get prompt
            $prompt = $this->get_prompt('document');

            if (empty($prompt)) {
                return new \WP_Error('no_prompt', __('No prompt configured for document analysis.', 'tainacan-ai'));
            }

            $pageCount = count($images);
            $promptWithContext = $prompt . "\n\n" . sprintf(
                /* translators: %d: number of pages */
                __('The document has %d page(s). Analyze the visual content of all provided pages.', 'tainacan-ai'),
                $pageCount
            );

            // Prepare images for provider
            $image_data = [];
            foreach ($images as $image) {
                $image_data[] = [
                    'data' => "data:{$image['mime']};base64,{$image['base64']}",
                    'mime' => $image['mime'],
                ];
            }

            $result = $provider->analyze_images($image_data, $promptWithContext);

            // Clean temporary files
            foreach ($images as $image) {
                if (isset($image['path']) && file_exists($image['path'])) {
                    @unlink($image['path']);
                }
            }

            return $result;

        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TainacanAI] Visual analysis error: ' . $e->getMessage());
            }
            return new \WP_Error('visual_analysis_error', $e->getMessage());
        }
    }

    /**
     * Analyze image
     */
    private function analyze_image(int $attachment_id, string $file_path, string $mime_type): array|\WP_Error {
        $provider = $this->get_provider();

        if (!$provider) {
            return new \WP_Error('no_provider', __('AI provider not configured.', 'tainacan-ai'));
        }

        if (!$provider->supports_vision()) {
            return new \WP_Error(
                'vision_not_supported',
                sprintf(
                    /* translators: %s: provider name */
                    __('Provider %s does not support image analysis. Use text extraction or choose another provider.', 'tainacan-ai'),
                    $provider->get_name()
                )
            );
        }

        // Always use base64 to ensure API can access the image
        // Local URLs (localhost, 127.0.0.1, private IPs) are not accessible by external APIs
        $image_url = wp_get_attachment_url($attachment_id);
        $image_data = null;
        $use_base64 = true;

        // Check if it's a publicly accessible URL (not localhost/local)
        if ($image_url && $this->is_public_url($image_url) && $this->is_url_accessible($image_url)) {
            $use_base64 = false;
            $image_data = $image_url;
        }

        if ($use_base64) {
            $image_content = @file_get_contents($file_path);

            if ($image_content === false) {
                return new \WP_Error('file_read_error', __('Could not read image file.', 'tainacan-ai'));
            }

            $base64 = base64_encode($image_content);

            if (empty($base64)) {
                return new \WP_Error('base64_error', __('Error encoding image to base64.', 'tainacan-ai'));
            }

            $image_data = "data:{$mime_type};base64,{$base64}";

            // Debug: Base64 size
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $base64_size = strlen($base64);
                $mb_size = round($base64_size / 1024 / 1024, 2);
                error_log("[TainacanAI] Base64 size: {$base64_size} bytes ({$mb_size} MB)");
            }
        }

        // Get prompt
        $prompt = $this->get_prompt('image');

        if (empty($prompt)) {
            return new \WP_Error('no_prompt', __('No prompt configured for image analysis.', 'tainacan-ai'));
        }

        // Debug: Prompt log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[TainacanAI] Image prompt length: " . strlen($prompt));
        }

        return $provider->analyze_image($image_data, $prompt);
    }

    /**
     * Analyze text
     */
    private function analyze_text(string $text): array|\WP_Error {
        $provider = $this->get_provider();

        if (!$provider) {
            return new \WP_Error('no_provider', __('AI provider not configured.', 'tainacan-ai'));
        }

        // Sanitize text to valid UTF-8
        $text = $this->sanitize_utf8_string($text);

        // Limit text size
        $max_chars = 32000;
        if (mb_strlen($text, 'UTF-8') > $max_chars) {
            $text = mb_substr($text, 0, $max_chars, 'UTF-8');
            $text .= "\n\n[Document truncated due to size]";
        }

        // Get prompt
        $prompt = $this->get_prompt('document');

        if (empty($prompt)) {
            return new \WP_Error('no_prompt', __('No prompt configured for document analysis.', 'tainacan-ai'));
        }

        return $provider->analyze_text($text, $prompt);
    }

    /**
     * Get prompt (custom or default)
     *
     * First checks if there's custom field mapping in admin,
     * and generates a dynamic prompt based on those fields so AI
     * returns exactly the mapped fields.
     */
    private function get_prompt(string $type): string {
        // First, check if there's custom mapping saved in admin
        if ($this->collection_id) {
            $custom_mapping = get_option('tainacan_ai_mapping_' . $this->collection_id, []);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[TainacanAI] get_prompt: collection_id={$this->collection_id}, type={$type}");
                error_log("[TainacanAI] Custom mapping found: " . (empty($custom_mapping) ? 'NO' : 'YES - ' . count($custom_mapping) . ' fields'));
            }

            if (!empty($custom_mapping)) {
                $prompt = $this->generate_prompt_from_mapping($custom_mapping, $type);
                if (!empty($prompt)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("[TainacanAI] Using dynamic prompt from mapping. Length: " . strlen($prompt));
                    }
                    return $prompt;
                }
            }

            // If no custom mapping, try collection prompt
            $prompt = $this->collection_prompts->get_effective_prompt($this->collection_id, $type);
            if (!empty($prompt)) {
                return $prompt;
            }
        }

        $key = $type === 'image' ? 'default_image_prompt' : 'default_document_prompt';
        return $this->options[$key] ?? '';
    }

    /**
     * Generate dynamic prompt based on field mapping
     *
     * Creates a prompt that instructs AI to return JSON with exactly
     * the fields defined in the mapping, using the correct keys.
     */
    private function generate_prompt_from_mapping(array $mapping, string $type): string {
        if (empty($mapping)) {
            return '';
        }

        // Build list of expected fields
        $fields_list = [];
        $json_example = [];

        foreach ($mapping as $ai_field => $data) {
            if (!empty($data['metadata_id'])) {
                $field_name = $data['metadata_name'] ?? $ai_field;
                $fields_list[] = "- **{$ai_field}**: {$field_name}";
                $json_example[$ai_field] = "value for {$field_name}";
            }
        }

        if (empty($fields_list)) {
            return '';
        }

        $fields_text = implode("\n", $fields_list);
        $json_text = json_encode($json_example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($type === 'image') {
            return <<<PROMPT
Você é um especialista em análise e catalogação de imagens de acervos culturais. Analise esta imagem cuidadosamente e extraia informações para os campos especificados abaixo.

## Campos para Extrair:
{$fields_text}

## Instruções:
1. Analise a imagem detalhadamente
2. Extraia informações relevantes para CADA campo listado
3. Se não conseguir identificar um campo, use null
4. Para campos com múltiplos valores, use array
5. Seja preciso e objetivo

## Formato de Resposta:
Retorne APENAS um JSON válido com esta estrutura (use EXATAMENTE estas chaves):
{$json_text}

IMPORTANTE: Use EXATAMENTE as chaves mostradas acima (como "titulo", "autor", etc.). Responda SOMENTE com o JSON, sem texto adicional.
PROMPT;
        } else {
            return <<<PROMPT
Você é um especialista em análise documental e catalogação. Analise este documento e extraia informações para os campos especificados abaixo.

## Campos para Extrair:
{$fields_text}

## Instruções:
1. Leia o documento completamente
2. Extraia informações para CADA campo listado
3. Se não encontrar informação para um campo, use null
4. Para campos com múltiplos valores, use array
5. Seja preciso nas citações

## Formato de Resposta:
Retorne APENAS um JSON válido com esta estrutura (use EXATAMENTE estas chaves):
{$json_text}

IMPORTANTE: Use EXATAMENTE as chaves mostradas acima (como "titulo", "autor", etc.). Responda SOMENTE com o JSON, sem texto adicional.
PROMPT;
        }
    }

    /**
     * Extract text from PDF using multiple methods
     */
    private function extract_pdf_text(string $file_path): string|\WP_Error {
        // Method 1: Built-in plugin parser
        try {
            $parser = new PdfParser();
            $text = $parser->parseFile($file_path)->getText();

            if (!empty(trim($text))) {
                return $text;
            }
        } catch (\Throwable $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TainacanAI] PdfParser error: ' . $e->getMessage());
            }
        }

        // Method 2: smalot/pdfparser (if installed via Composer)
        if (class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($file_path);
                $text = $pdf->getText();

                if (!empty(trim($text))) {
                    return $text;
                }
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[TainacanAI] Smalot PdfParser error: ' . $e->getMessage());
                }
            }
        }

        // Method 3: pdftotext (poppler-utils) - Linux/Mac
        if (function_exists('shell_exec') && !$this->is_windows()) {
            $escaped_path = escapeshellarg($file_path);
            $output = @shell_exec("pdftotext {$escaped_path} - 2>/dev/null");

            if (!empty($output)) {
                return $output;
            }
        }

        // Method 4: pdftotext on Windows
        if ($this->is_windows() && function_exists('shell_exec')) {
            $escaped_path = escapeshellarg($file_path);
            $paths = [
                'pdftotext',
                'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
                'C:\\poppler\\bin\\pdftotext.exe',
            ];

            foreach ($paths as $pdftotext) {
                $output = @shell_exec("\"{$pdftotext}\" {$escaped_path} - 2>nul");
                if (!empty($output)) {
                    return $output;
                }
            }
        }

        // Method 5: Basic extraction via regex
        $text = $this->basic_pdf_text_extract($file_path);
        if (!empty(trim($text))) {
            return $text;
        }

        return new \WP_Error(
            'pdf_extract_failed',
            __('Could not extract text from PDF. The document may be a scanned image.', 'tainacan-ai')
        );
    }

    /**
     * Basic PDF text extraction
     */
    private function basic_pdf_text_extract(string $file_path): string {
        $content = file_get_contents($file_path);
        $text = '';

        if (preg_match_all('/stream\s*\n(.*?)\nendstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    $decoded = $stream;
                }

                if (preg_match_all('/\((.*?)\)/', $decoded, $text_matches)) {
                    $text .= implode(' ', $text_matches[1]) . ' ';
                }
            }
        }

        return trim($text);
    }

    /**
     * Check if URL is accessible
     */
    private function is_url_accessible(string $url): bool {
        $response = wp_remote_head($url, ['timeout' => 5]);

        if (is_wp_error($response)) {
            return false;
        }

        return wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Check if it's a public URL (not localhost/local)
     */
    private function is_public_url(string $url): bool {
        $parsed = parse_url($url);

        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        $host = strtolower($parsed['host']);

        // List of local hosts that external APIs cannot access
        $local_hosts = [
            'localhost',
            '127.0.0.1',
            '::1',
            '0.0.0.0',
        ];

        if (in_array($host, $local_hosts)) {
            return false;
        }

        // Check private IPs (RFC 1918)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // 10.0.0.0 - 10.255.255.255
            if (preg_match('/^10\./', $host)) {
                return false;
            }
            // 172.16.0.0 - 172.31.255.255
            if (preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)) {
                return false;
            }
            // 192.168.0.0 - 192.168.255.255
            if (preg_match('/^192\.168\./', $host)) {
                return false;
            }
        }

        // Check common local domains
        $local_domains = ['.local', '.localhost', '.test', '.example', '.invalid', '.lan'];
        foreach ($local_domains as $domain) {
            if (str_ends_with($host, $domain)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if it's Windows
     */
    private function is_windows(): bool {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Get collection of an item
     */
    private function get_item_collection(int $item_id): ?int {
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
     * Sanitize a string to valid UTF-8
     */
    private function sanitize_utf8_string(string $string): string {
        if (mb_check_encoding($string, 'UTF-8')) {
            $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
            return $string;
        }

        $encodings = ['ISO-8859-1', 'Windows-1252', 'ASCII'];

        foreach ($encodings as $encoding) {
            $converted = @mb_convert_encoding($string, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $converted);
            }
        }

        $string = mb_convert_encoding($string, 'UTF-8', 'UTF-8');
        $string = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string);
        $string = preg_replace('/[\x80-\xFF](?![\x80-\xBF])|(?<![\xC0-\xFF])[\x80-\xBF]/', '', $string);

        return $string;
    }

    /**
     * Normalize file path to work on Windows and Linux
     */
    private function normalize_file_path(string $file_path): string {
        $file_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);

        if (file_exists($file_path)) {
            return $file_path;
        }

        $fixed_path = preg_replace('/([\/\\\\])_x_(\d+)([\/\\\\])/', '$1$2$3', $file_path);

        if ($fixed_path !== $file_path && file_exists($fixed_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[TainacanAI] Fixed file path from: {$file_path} to: {$fixed_path}");
            }
            return $fixed_path;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $alt_path = str_replace('/', '\\', $file_path);
            if (file_exists($alt_path)) {
                return $alt_path;
            }

            $alt_fixed = str_replace('/', '\\', $fixed_path);
            if (file_exists($alt_fixed)) {
                return $alt_fixed;
            }
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $alt_path = str_replace('\\', '/', $file_path);
            if (file_exists($alt_path)) {
                return $alt_path;
            }

            $alt_fixed = str_replace('\\', '/', $fixed_path);
            if (file_exists($alt_fixed)) {
                return $alt_fixed;
            }
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        if (preg_match('/tainacan-items[\/\\\\](\d+)[\/\\\\](?:_x_)?(\d+)[\/\\\\](.+)$/', $file_path, $matches)) {
            $collection_id = $matches[1];
            $item_id = $matches[2];
            $file_name = $matches[3];

            $correct_path = $base_dir . DIRECTORY_SEPARATOR . 'tainacan-items' . DIRECTORY_SEPARATOR .
                           $collection_id . DIRECTORY_SEPARATOR . $item_id . DIRECTORY_SEPARATOR . $file_name;

            if (file_exists($correct_path)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[TainacanAI] Found file at corrected path: {$correct_path}");
                }
                return $correct_path;
            }
        }

        return $file_path;
    }

    /**
     * Get supported file types
     */
    public function get_supported_types(): array {
        return [
            'images' => $this->supported_image_types,
            'documents' => $this->supported_document_types,
        ];
    }

    /**
     * Check if type is supported
     */
    public function is_supported(string $mime_type): bool {
        return in_array($mime_type, array_merge($this->supported_image_types, $this->supported_document_types));
    }

    /**
     * Check available capabilities
     */
    public static function get_capabilities(): array {
        $capabilities = [
            'text_extraction' => [
                'name' => __('Text Extraction', 'tainacan-ai'),
                'available' => true,
                'methods' => ['built_in_parser'],
            ],
            'visual_analysis' => [
                'name' => __('Visual Analysis (PDF)', 'tainacan-ai'),
                'available' => false,
                'methods' => [],
            ],
            'exif_extraction' => [
                'name' => __('EXIF Extraction', 'tainacan-ai'),
                'available' => function_exists('exif_read_data'),
            ],
        ];

        $backends = PdfToImage::getAvailableBackends();

        if (!empty($backends['imagick']['available']) && !empty($backends['imagick']['supports_pdf'])) {
            $capabilities['visual_analysis']['available'] = true;
            $capabilities['visual_analysis']['methods'][] = 'imagick';
        }

        if (!empty($backends['ghostscript']['available'])) {
            $capabilities['visual_analysis']['available'] = true;
            $capabilities['visual_analysis']['methods'][] = 'ghostscript';
        }

        if (class_exists('\Smalot\PdfParser\Parser')) {
            $capabilities['text_extraction']['methods'][] = 'smalot_pdfparser';
        }

        if (function_exists('shell_exec')) {
            $output = @shell_exec('pdftotext -v 2>&1');
            if ($output && stripos($output, 'pdftotext') !== false) {
                $capabilities['text_extraction']['methods'][] = 'pdftotext';
            }
        }

        return $capabilities;
    }
}

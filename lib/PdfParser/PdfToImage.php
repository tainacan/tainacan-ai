<?php
namespace Tainacan\AI\PdfParser;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PDF to Image Converter
 *
 * Supports multiple backends: Imagick, GD with Ghostscript, or external API.
 * Allows visual analysis of scanned PDFs via GPT-4 Vision.
 *
 * @package Tainacan_AI
 * @since 1.0.0
 */

class PdfToImage {

    private int $dpi = 150;
    private int $quality = 85;
    private int $maxPages = 5;
    private string $format = 'jpeg';

    /**
     * Sets DPI for conversion
     */
    public function setDpi(int $dpi): self {
        $this->dpi = max(72, min(300, $dpi));
        return $this;
    }

    /**
     * Sets image quality
     */
    public function setQuality(int $quality): self {
        $this->quality = max(50, min(100, $quality));
        return $this;
    }

    /**
     * Sets maximum pages to convert
     */
    public function setMaxPages(int $pages): self {
        $this->maxPages = max(1, min(20, $pages));
        return $this;
    }

    /**
     * Converts PDF to images
     *
     * @param string $pdfPath PDF file path
     * @return array Array of generated image paths or base64 data
     */
    public function convert(string $pdfPath): array {
        if (!file_exists($pdfPath)) {
            throw new \Exception("PDF file not found: {$pdfPath}");
        }

        // Try different methods in order of preference
        $methods = [
            'convertWithImagick',
            'convertWithGhostscript',
        ];

        foreach ($methods as $method) {
            try {
                $result = $this->$method($pdfPath);
                if (!empty($result)) {
                    return $result;
                }
            } catch (\Exception $e) {
                // Try next method
                continue;
            }
        }

        throw new \Exception('No PDF conversion method available. Install Imagick or Ghostscript.');
    }

    /**
     * Converts using Imagick
     */
    private function convertWithImagick(string $pdfPath): array {
        if (!extension_loaded('imagick')) {
            throw new \Exception('Imagick extension not available.');
        }

        $images = [];
        $uploadDir = wp_upload_dir();
        $tempDir = $uploadDir['basedir'] . '/tainacan-ai-temp/';

        // Create temporary directory
        if (!is_dir($tempDir)) {
            wp_mkdir_p($tempDir);
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution($this->dpi, $this->dpi);

            // Read PDF (limit pages)
            $imagick->readImage($pdfPath . '[0-' . ($this->maxPages - 1) . ']');

            $pageNum = 0;
            foreach ($imagick as $page) {
                if ($pageNum >= $this->maxPages) {
                    break;
                }

                $page->setImageFormat($this->format);
                $page->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $page->setImageCompressionQuality($this->quality);

                // Convert to RGB if necessary
                if ($page->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                    $page->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
                }

                // Flatten (remove transparency)
                $page->setImageBackgroundColor('white');
                $page = $page->flattenImages();

                // Save temporary image
                $imagePath = $tempDir . 'pdf_page_' . uniqid() . '_' . $pageNum . '.jpg';
                $page->writeImage($imagePath);

                $images[] = [
                    'path' => $imagePath,
                    'page' => $pageNum + 1,
                    'base64' => base64_encode(file_get_contents($imagePath)),
                    'mime' => 'image/jpeg',
                ];

                $pageNum++;
            }

            $imagick->clear();
            $imagick->destroy();

        } catch (\Exception $e) {
            throw new \Exception('Error converting PDF with Imagick: ' . $e->getMessage());
        }

        return $images;
    }

    /**
     * Converts using Ghostscript via command line
     */
    private function convertWithGhostscript(string $pdfPath): array {
        // Check if Ghostscript is available
        $gsPath = $this->findGhostscript();
        if (!$gsPath) {
            throw new \Exception('Ghostscript not found.');
        }

        $images = [];
        $uploadDir = wp_upload_dir();
        $tempDir = $uploadDir['basedir'] . '/tainacan-ai-temp/';

        // Create temporary directory
        if (!is_dir($tempDir)) {
            wp_mkdir_p($tempDir);
        }

        $outputPattern = $tempDir . 'pdf_page_' . uniqid() . '_%d.jpg';

        // Ghostscript command
        $command = sprintf(
            '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -dJPEGQ=%d -r%d -dFirstPage=1 -dLastPage=%d -sOutputFile=%s %s 2>&1',
            escapeshellcmd($gsPath),
            $this->quality,
            $this->dpi,
            $this->maxPages,
            escapeshellarg($outputPattern),
            escapeshellarg($pdfPath)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('Error executing Ghostscript: ' . implode("\n", $output));
        }

        // Find generated images
        for ($i = 1; $i <= $this->maxPages; $i++) {
            $imagePath = str_replace('%d', $i, $outputPattern);

            if (file_exists($imagePath)) {
                $images[] = [
                    'path' => $imagePath,
                    'page' => $i,
                    'base64' => base64_encode(file_get_contents($imagePath)),
                    'mime' => 'image/jpeg',
                ];
            }
        }

        return $images;
    }

    /**
     * Finds Ghostscript executable
     */
    private function findGhostscript(): ?string {
        // Common paths
        $paths = [
            'gs',                                          // Linux/Mac (PATH)
            '/usr/bin/gs',                                 // Linux
            '/usr/local/bin/gs',                           // Mac Homebrew
            'C:\\Program Files\\gs\\gs10.02.1\\bin\\gswin64c.exe',   // Windows 64-bit
            'C:\\Program Files\\gs\\gs10.00.0\\bin\\gswin64c.exe',
            'C:\\Program Files\\gs\\gs9.56.1\\bin\\gswin64c.exe',
            'C:\\Program Files (x86)\\gs\\gs9.56.1\\bin\\gswin32c.exe',
            'C:\\xampp\\gs\\bin\\gswin64c.exe',           // XAMPP custom
        ];

        // Add XAMPP paths
        if (defined('ABSPATH')) {
            $wpRoot = dirname(dirname(dirname(dirname(ABSPATH))));
            $paths[] = $wpRoot . '/gs/bin/gswin64c.exe';
            $paths[] = $wpRoot . '/gs/bin/gswin32c.exe';
        }

        foreach ($paths as $path) {
            if ($this->isExecutable($path)) {
                return $path;
            }
        }

        // Try which/where
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec('where gswin64c 2>nul', $output);
            if (!empty($output[0])) {
                return trim($output[0]);
            }
            exec('where gswin32c 2>nul', $output);
            if (!empty($output[0])) {
                return trim($output[0]);
            }
        } else {
            $output = [];
            exec('which gs 2>/dev/null', $output);
            if (!empty($output[0])) {
                return trim($output[0]);
            }
        }

        return null;
    }

    /**
     * Checks if file is executable
     */
    private function isExecutable(string $path): bool {
        if (PHP_OS_FAMILY === 'Windows') {
            return file_exists($path) && is_file($path);
        }
        return is_executable($path);
    }

    /**
     * Checks available backends
     */
    public static function getAvailableBackends(): array {
        $backends = [];

        if (extension_loaded('imagick')) {
            $backends['imagick'] = [
                'name' => 'Imagick',
                'available' => true,
                'description' => 'PHP extension for ImageMagick',
            ];

            // Check if PDF is supported
            try {
                $imagick = new \Imagick();
                $formats = $imagick->queryFormats('PDF');
                $backends['imagick']['supports_pdf'] = !empty($formats);
            } catch (\Exception $e) {
                $backends['imagick']['supports_pdf'] = false;
            }
        } else {
            $backends['imagick'] = [
                'name' => 'Imagick',
                'available' => false,
                'description' => 'PHP extension not installed',
            ];
        }

        // Ghostscript
        $instance = new self();
        $gsPath = $instance->findGhostscript();
        $backends['ghostscript'] = [
            'name' => 'Ghostscript',
            'available' => $gsPath !== null,
            'path' => $gsPath,
            'description' => $gsPath ? 'Available at: ' . $gsPath : 'Not found on system',
        ];

        return $backends;
    }

    /**
     * Cleans up old temporary files
     */
    public static function cleanupTempFiles(int $maxAge = 3600): int {
        $uploadDir = wp_upload_dir();
        $tempDir = $uploadDir['basedir'] . '/tainacan-ai-temp/';

        if (!is_dir($tempDir)) {
            return 0;
        }

        $count = 0;
        $files = glob($tempDir . 'pdf_page_*.jpg');

        foreach ($files as $file) {
            if (filemtime($file) < time() - $maxAge) {
                if (unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}

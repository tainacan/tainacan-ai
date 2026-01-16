<?php
namespace Tainacan\AI\PdfParser;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lightweight PDF Parser - PDF Text Extraction
 *
 * Standalone implementation that does not require external dependencies.
 * Supports PDFs with selectable text (not scanned).
 *
 * @package Tainacan_AI
 * @since 1.0.0
 */

class PdfParser {

    private string $content;
    private array $objects = [];
    private array $pages = [];

    /**
     * Parses a PDF file
     */
    public function parseFile(string $filePath): self {
        if (!file_exists($filePath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $this->content = file_get_contents($filePath);
        $this->parseObjects();

        return $this;
    }

    /**
     * Parses PDF content directly
     */
    public function parseContent(string $content): self {
        $this->content = $content;
        $this->parseObjects();

        return $this;
    }

    /**
     * Extracts all text from the PDF
     */
    public function getText(): string {
        $text = '';

        // Method 1: Extract from content streams
        $text .= $this->extractFromStreams();

        // Method 2: Extract text between parentheses (PDF text objects)
        if (empty(trim($text))) {
            $text .= $this->extractTextObjects();
        }

        // Method 3: Extract hexadecimal strings
        if (empty(trim($text))) {
            $text .= $this->extractHexStrings();
        }

        // Clean the text
        $text = $this->cleanText($text);

        return $text;
    }

    /**
     * Returns number of pages (estimate)
     */
    public function getPageCount(): int {
        preg_match_all('/\/Type\s*\/Page[^s]/i', $this->content, $matches);
        return count($matches[0]);
    }

    /**
     * Parses PDF objects
     */
    private function parseObjects(): void {
        // Find all objects
        preg_match_all('/(\d+)\s+(\d+)\s+obj(.*?)endobj/s', $this->content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $objNum = $match[1];
            $objGen = $match[2];
            $objContent = $match[3];

            $this->objects["{$objNum}_{$objGen}"] = $objContent;
        }
    }

    /**
     * Extracts text from compressed streams
     */
    private function extractFromStreams(): string {
        $text = '';

        // Find all streams
        preg_match_all('/stream\s*(.*?)\s*endstream/s', $this->content, $streams);

        foreach ($streams[1] as $stream) {
            $decoded = $this->decodeStream($stream);

            if (!empty($decoded)) {
                // Extract text from decoded content
                $extracted = $this->extractTextFromContent($decoded);
                if (!empty($extracted)) {
                    $text .= $extracted . "\n";
                }
            }
        }

        return $text;
    }

    /**
     * Decodes stream (FlateDecode/deflate)
     */
    private function decodeStream(string $stream): string {
        // Remove leading/trailing whitespace
        $stream = trim($stream);

        // Try decoding with zlib (FlateDecode)
        $decoded = @gzuncompress($stream);
        if ($decoded !== false) {
            return $decoded;
        }

        // Try inflate (without zlib header)
        $decoded = @gzinflate($stream);
        if ($decoded !== false) {
            return $decoded;
        }

        // Try raw deflate
        $decoded = @gzinflate(substr($stream, 2));
        if ($decoded !== false) {
            return $decoded;
        }

        // If unable to decode, return original stream
        // (may be an uncompressed stream)
        if (strpos($stream, 'BT') !== false || strpos($stream, 'Tj') !== false) {
            return $stream;
        }

        return '';
    }

    /**
     * Extracts text from PDF page content
     */
    private function extractTextFromContent(string $content): string {
        $text = '';

        // Find text blocks (between BT and ET)
        preg_match_all('/BT(.*?)ET/s', $content, $textBlocks);

        foreach ($textBlocks[1] as $block) {
            // Extract strings between parentheses (Tj, TJ, ', ")
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)?\)\s*Tj/s', $block, $tjMatches);
            foreach ($tjMatches[1] as $match) {
                $text .= $this->decodeTextString($match) . ' ';
            }

            // Extract text arrays (TJ)
            preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjArrayMatches);
            foreach ($tjArrayMatches[1] as $arrayContent) {
                preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)?\)/', $arrayContent, $arrayStrings);
                foreach ($arrayStrings[1] as $str) {
                    $text .= $this->decodeTextString($str);
                }
                $text .= ' ';
            }

            // Operators ' and "
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)?\)\s*[\'"]/s', $block, $quoteMatches);
            foreach ($quoteMatches[1] as $match) {
                $text .= $this->decodeTextString($match) . ' ';
            }
        }

        return $text;
    }

    /**
     * Extracts text objects directly
     */
    private function extractTextObjects(): string {
        $text = '';

        // Search for text patterns in all content
        foreach ($this->objects as $obj) {
            // Literal text between parentheses
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $obj, $matches);
            foreach ($matches[1] as $match) {
                $decoded = $this->decodeTextString($match);
                // Filter valid text (at least 3 alphanumeric characters)
                if (preg_match('/[a-zA-Z0-9]{3,}/', $decoded)) {
                    $text .= $decoded . ' ';
                }
            }
        }

        return $text;
    }

    /**
     * Extracts hexadecimal strings
     */
    private function extractHexStrings(): string {
        $text = '';

        // Search for hex strings <...>
        preg_match_all('/<([0-9A-Fa-f\s]+)>/', $this->content, $matches);

        foreach ($matches[1] as $hex) {
            $hex = preg_replace('/\s/', '', $hex);
            if (strlen($hex) >= 4) {
                $decoded = $this->hexToString($hex);
                if (!empty($decoded) && preg_match('/[a-zA-Z0-9]{2,}/', $decoded)) {
                    $text .= $decoded . ' ';
                }
            }
        }

        return $text;
    }

    /**
     * Decodes PDF text string
     */
    private function decodeTextString(string $str): string {
        // Decode escapes
        $str = str_replace('\\n', "\n", $str);
        $str = str_replace('\\r', "\r", $str);
        $str = str_replace('\\t', "\t", $str);
        $str = str_replace('\\(', '(', $str);
        $str = str_replace('\\)', ')', $str);
        $str = str_replace('\\\\', '\\', $str);

        // Decode octal escapes
        $str = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $str);

        return $str;
    }

    /**
     * Converts hex to string
     */
    private function hexToString(string $hex): string {
        $str = '';
        $len = strlen($hex);

        for ($i = 0; $i < $len; $i += 2) {
            if ($i + 1 < $len) {
                $byte = hexdec(substr($hex, $i, 2));
                if ($byte >= 32 && $byte <= 126) {
                    $str .= chr($byte);
                } elseif ($byte === 10 || $byte === 13) {
                    $str .= ' ';
                }
            }
        }

        return $str;
    }

    /**
     * Cleans extracted text
     */
    private function cleanText(string $text): string {
        // Convert to UTF-8 if necessary
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Try to detect encoding and convert
            $detected = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($detected) {
                $text = mb_convert_encoding($text, 'UTF-8', $detected);
            } else {
                // Fallback: force conversion from ISO-8859-1
                $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            }
        }

        // Remove control characters (keep tab and newline)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Remove invalid UTF-8 bytes
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove multiple spaces
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Remove multiple empty lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Remove spaces before punctuation
        $text = preg_replace('/\s+([.,;:!?])/', '$1', $text);

        // Trim
        $text = trim($text);

        return $text;
    }
}

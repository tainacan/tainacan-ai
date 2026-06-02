<?php
declare(strict_types=1);

namespace Tainacan\AI\Extraction;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heuristic quality gate for PDF text extraction candidates.
 *
 * Language-agnostic structural checks only (no locale/word-list assumptions).
 * Rejects non-empty but corrupted output so later extractors can run.
 */
final class PdfExtractedTextQuality {

	/** Minimum trimmed UTF-8 length (aligned with analyze_pdf_smart). */
	public const MIN_LENGTH = 100;

	private const MIN_LETTER_RATIO = 0.38;

	private const MIN_READABLE_WORD_COUNT = 12;

	private const MIN_LONG_WORD_COUNT = 8;

	private const MIN_LONG_WORD_LENGTH = 5;

	private const MIN_COHERENT_TOKEN_COUNT = 10;

	private const MIN_COHERENT_TOKEN_RATIO = 0.35;

	private const MAX_COHERENT_TOKEN_LENGTH = 48;

	private const MAX_OVERSIZED_GARBLED_TOKEN_RATIO = 0.25;

	private const OVERSIZED_TOKEN_LENGTH = 36;

	private const MAX_ARTIFACT_CHAR_RATIO = 0.14;

	private const MAX_REPLACEMENT_CHAR_RATIO = 0.01;

	private const MIN_PROSE_LINE_RATIO = 0.2;

	/**
	 * Whether extracted PDF text is usable for AI analysis.
	 */
	public static function is_usable( string $text ): bool {
		$text = trim( $text );
		if ( $text === '' ) {
			return false;
		}

		if ( mb_strlen( $text, 'UTF-8' ) < self::MIN_LENGTH ) {
			return false;
		}

		$passes = self::passes_heuristics( $text );

		/**
		 * Filter whether PDF extracted text passes the quality gate.
		 *
		 * @param bool   $passes Default heuristic result.
		 * @param string $text   Trimmed candidate text.
		 */
		return (bool) apply_filters( 'tainacan_ai_pdf_extracted_text_is_usable', $passes, $text );
	}

	private static function passes_heuristics( string $text ): bool {
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text ) ) {
			return false;
		}

		if ( self::has_strong_pdf_internal_signature( $text ) ) {
			return false;
		}

		$non_whitespace = preg_replace( '/\s+/u', '', $text );
		if ( $non_whitespace === null || $non_whitespace === '' ) {
			return false;
		}

		$non_ws_length = mb_strlen( $non_whitespace, 'UTF-8' );
		if ( $non_ws_length < 1 ) {
			return false;
		}

		$letter_count = self::count_unicode_letters( $text );
		if ( ( $letter_count / $non_ws_length ) < self::MIN_LETTER_RATIO ) {
			return false;
		}

		if ( self::uses_space_delimited_tokens( $text ) ) {
			if ( self::count_readable_words( $text ) < self::MIN_READABLE_WORD_COUNT ) {
				return false;
			}

			if ( self::count_long_letter_words( $text ) < self::MIN_LONG_WORD_COUNT ) {
				return false;
			}
			$token_stats = self::analyze_tokens( $text );
			if ( $token_stats['coherent_count'] < self::MIN_COHERENT_TOKEN_COUNT ) {
				return false;
			}

			if ( $token_stats['coherent_ratio'] < self::MIN_COHERENT_TOKEN_RATIO ) {
				return false;
			}

			if ( $token_stats['oversized_garbled_ratio'] > self::MAX_OVERSIZED_GARBLED_TOKEN_RATIO ) {
				return false;
			}
		} elseif ( ! preg_match( '/\p{L}{20,}/u', $text ) ) {
			return false;
		}

		if ( self::artifact_char_ratio( $non_whitespace ) > self::MAX_ARTIFACT_CHAR_RATIO ) {
			return false;
		}

		$replacement_count = substr_count( $text, "\u{FFFD}" );
		if ( ( $replacement_count / $non_ws_length ) > self::MAX_REPLACEMENT_CHAR_RATIO ) {
			return false;
		}

		if ( self::prose_line_ratio( $text ) < self::MIN_PROSE_LINE_RATIO ) {
			return false;
		}

		return true;
	}

	/**
	 * Detect leaked PDF file structure/metadata, not document prose.
	 *
	 * Uses format-level markers from the PDF spec (dates, objects, streams, XMP)
	 * plus optional renderer/producer names. Avoids locale-specific word lists.
	 */
	private static function has_strong_pdf_internal_signature( string $text ): bool {
		if ( self::has_pdf_structure_leak( $text ) ) {
			return true;
		}

		if ( self::has_pdf_metadata_dictionary_leak( $text ) ) {
			return true;
		}

		return self::has_pdf_date_with_engine_marker( $text );
	}

	/**
	 * PDF internal date (D:YYYYMMDDHHmmSS…) near the start plus a known producer string.
	 */
	private static function has_pdf_date_with_engine_marker( string $text ): bool {
		$head = mb_substr( $text, 0, 600, 'UTF-8' );
		if ( ! preg_match( '/\bD:\d{14}/', $head ) ) {
			return false;
		}

		return preg_match(
			'/\b(?:PDFium|Ghostscript|Poppler|iText|Skia|wkhtmltopdf|LibreOffice|OpenOffice|Microsoft|Adobe|Cairo|Qt|TCPDF|FPDF|Prince)\b/i',
			$head
		) === 1;
	}

	/**
	 * Multiple PDF syntax / stream markers in extracted text (any producer).
	 */
	private static function has_pdf_structure_leak( string $text ): bool {
		$markers = array(
			'/\bendstream\b/i',
			'/\bendobj\b/i',
			'/\bstartxref\b/i',
			'/\btrailer\b/i',
			'/<\?xpacket\b/i',
			'/\bxmlns:(?:pdf|xmp)=/i',
			'/\/(?:Type|Subtype|Font|Pages|Catalog|Page|Metadata)\b/i',
			'/\b(?:FlateDecode|DCTDecode|ASCII85Decode|LZWDecode)\b/i',
		);

		$hits = 0;
		foreach ( $markers as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				++$hits;
			}
		}

		if ( $hits >= 2 ) {
			return true;
		}

		$head = mb_substr( $text, 0, 800, 'UTF-8' );

		return preg_match( '/\bD:\d{14}/', $head ) === 1 && $hits >= 1;
	}

	/**
	 * Raw /Producer or /Creator dictionary lines mixed with object syntax.
	 */
	private static function has_pdf_metadata_dictionary_leak( string $text ): bool {
		if ( ! preg_match( '/\/(?:Producer|Creator)\s*[\(<]/i', $text ) ) {
			return false;
		}

		return preg_match( '/\b(?:endstream|endobj|xref|startxref)\b/i', $text ) === 1
			|| preg_match( '/\/(?:Type|Font|Pages)\b/i', $text ) === 1;
	}

	/**
	 * Scripts such as CJK are often extracted without spaces; token heuristics are unreliable then.
	 */
	private static function uses_space_delimited_tokens( string $text ): bool {
		$length = mb_strlen( $text, 'UTF-8' );
		if ( $length < 1 ) {
			return false;
		}

		$space_like = preg_match_all( '/\s/u', $text, $matches ) ? count( $matches[0] ) : 0;

		return ( $space_like / $length ) >= 0.02;
	}

	/**
	 * @return array{coherent_count: int, coherent_ratio: float, oversized_garbled_ratio: float}
	 */
	private static function analyze_tokens( string $text ): array {
		$tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) || $tokens === [] ) {
			return array(
				'coherent_count'           => 0,
				'coherent_ratio'           => 0.0,
				'oversized_garbled_ratio'  => 0.0,
			);
		}

		$total              = count( $tokens );
		$coherent_count     = 0;
		$oversized_garbled  = 0;

		foreach ( $tokens as $token ) {
			if ( self::is_coherent_word_token( $token ) ) {
				++$coherent_count;
			}

			if ( self::is_oversized_garbled_token( $token ) ) {
				++$oversized_garbled;
			}
		}

		return array(
			'coherent_count'          => $coherent_count,
			'coherent_ratio'          => $coherent_count / $total,
			'oversized_garbled_ratio' => $oversized_garbled / $total,
		);
	}

	/**
	 * Share of non-trivial lines that look like human prose, not encoding streams.
	 */
	private static function prose_line_ratio( string $text ): float {
		$lines = preg_split( '/\R/u', $text );
		if ( ! is_array( $lines ) || $lines === [] ) {
			return 0.0;
		}

		$candidate_lines = 0;
		$prose_lines     = 0;

		foreach ( $lines as $line ) {
			$line   = trim( $line );
			$length = mb_strlen( $line, 'UTF-8' );
			if ( $length < 20 ) {
				continue;
			}

			++$candidate_lines;
			$non_ws = preg_replace( '/\s+/u', '', $line );
			if ( $non_ws === null || $non_ws === '' ) {
				continue;
			}

			$non_ws_length = mb_strlen( $non_ws, 'UTF-8' );
			$letters       = self::count_unicode_letters( $line );
			if ( ( $letters / $non_ws_length ) >= 0.55 && self::artifact_char_ratio( $non_ws ) <= 0.1 ) {
				++$prose_lines;
			}
		}

		if ( $candidate_lines === 0 ) {
			return 0.0;
		}

		return $prose_lines / $candidate_lines;
	}

	private static function count_unicode_letters( string $text ): int {
		if ( ! preg_match_all( '/\p{L}/u', $text, $matches ) ) {
			return 0;
		}

		return count( $matches[0] );
	}

	/**
	 * Tokens that are mostly letters (any script), not encoding garbage.
	 */
	private static function count_readable_words( string $text ): int {
		$tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return 0;
		}

		$readable = 0;

		foreach ( $tokens as $token ) {
			if ( self::is_readable_word_token( $token ) ) {
				++$readable;
			}
		}

		return $readable;
	}

	private static function count_long_letter_words( string $text ): int {
		$tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $tokens ) ) {
			return 0;
		}

		$long_words = 0;

		foreach ( $tokens as $token ) {
			$core = self::normalize_word_core( $token );
			if ( $core === '' ) {
				continue;
			}

			$core_length = mb_strlen( $core, 'UTF-8' );
			if ( $core_length < self::MIN_LONG_WORD_LENGTH ) {
				continue;
			}

			if ( self::is_coherent_word_token( $token ) ) {
				++$long_words;
			}
		}

		return $long_words;
	}

	/**
	 * Word-like token without embedded operator/symbol runs (language-agnostic).
	 */
	private static function is_coherent_word_token( string $token ): bool {
		if ( ! self::is_readable_word_token( $token ) ) {
			return false;
		}

		$core = self::normalize_word_core( $token );
		if ( $core === '' ) {
			return false;
		}

		$core_length = mb_strlen( $core, 'UTF-8' );
		if ( $core_length > self::MAX_COHERENT_TOKEN_LENGTH ) {
			return false;
		}

		if ( preg_match( '/[=<>?@#^\\\\|]{2,}/', $core ) ) {
			return false;
		}

		// Long runs of non-letters inside a single token (broken encoding), allowing one hyphen.
		if ( preg_match( '/\P{L}\P{L}\P{L}/u', $core ) ) {
			return false;
		}

		return true;
	}

	private static function is_oversized_garbled_token( string $token ): bool {
		$core = self::normalize_word_core( $token );
		if ( $core === '' ) {
			return false;
		}

		$core_length = mb_strlen( $core, 'UTF-8' );
		if ( $core_length < self::OVERSIZED_TOKEN_LENGTH ) {
			return false;
		}

		$letters = self::count_unicode_letters( $core );

		return ( $letters / $core_length ) < 0.8;
	}

	private static function is_readable_word_token( string $token ): bool {
		$core = self::normalize_word_core( $token );
		if ( $core === '' ) {
			return false;
		}

		$core_length = mb_strlen( $core, 'UTF-8' );
		if ( $core_length < 2 ) {
			return false;
		}

		$letters = self::count_unicode_letters( $core );

		return ( $letters / $core_length ) >= 0.75;
	}

	private static function normalize_word_core( string $token ): string {
		$core = preg_replace( '/^[\p{P}\p{S}\d]+|[\p{P}\p{S}\d]+$/u', '', $token );

		return is_string( $core ) ? $core : '';
	}

	/**
	 * Characters common in broken PDF text operators / custom encodings.
	 */
	private static function artifact_char_ratio( string $non_whitespace ): float {
		$artifact_count = 0;
		$length         = mb_strlen( $non_whitespace, 'UTF-8' );

		for ( $i = 0; $i < $length; ++$i ) {
			$char = mb_substr( $non_whitespace, $i, 1, 'UTF-8' );
			if ( $char === '' ) {
				continue;
			}

			if ( str_contains( '=<>?@#^[]\\|', $char ) ) {
				++$artifact_count;
			}
		}

		return $artifact_count / $length;
	}
}

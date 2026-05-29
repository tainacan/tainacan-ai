<?php
declare(strict_types=1);

namespace Tainacan\AI\Support;

use Tainacan\AI\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configurable analysis processing limits (admin: Advanced Settings).
 */
final class AnalysisLimits {

	public const DEFAULT_DOCUMENT_MAX_CHARS = 32000;

	public const DEFAULT_PDF_VISUAL_MAX_PAGES = 3;

	public const DEFAULT_TAXONOMY_ALLOWED_VALUES_LIMIT = 100;

	public const DOCUMENT_MAX_CHARS_MIN = 0;

	public const DOCUMENT_MAX_CHARS_MAX = 500000;

	public const PDF_VISUAL_MAX_PAGES_MIN = 1;

	public const PDF_VISUAL_MAX_PAGES_MAX = 20;

	public const TAXONOMY_ALLOWED_VALUES_LIMIT_MIN = 1;

	public const TAXONOMY_ALLOWED_VALUES_LIMIT_MAX = 1000;

	/**
	 * Maximum UTF-8 characters sent to the model for text/HTML/PDF text extraction.
	 * 0 means no truncation.
	 */
	public static function get_document_max_chars(): int {
		$options = Plugin::get_options();
		$value   = isset( $options['document_max_chars'] )
			? (int) $options['document_max_chars']
			: self::DEFAULT_DOCUMENT_MAX_CHARS;

		if ( $value === 0 ) {
			return 0;
		}

		return max(
			1000,
			min( self::DOCUMENT_MAX_CHARS_MAX, $value )
		);
	}

	/**
	 * Maximum PDF pages converted for visual (scanned) analysis.
	 */
	public static function get_pdf_visual_max_pages(): int {
		$options = Plugin::get_options();
		$value   = isset( $options['pdf_visual_max_pages'] )
			? (int) $options['pdf_visual_max_pages']
			: self::DEFAULT_PDF_VISUAL_MAX_PAGES;

		return max(
			self::PDF_VISUAL_MAX_PAGES_MIN,
			min( self::PDF_VISUAL_MAX_PAGES_MAX, $value )
		);
	}

	/**
	 * Maximum taxonomy terms listed per field in the analysis prompt.
	 */
	public static function get_taxonomy_allowed_values_limit(): int {
		$options = Plugin::get_options();
		$value   = isset( $options['taxonomy_allowed_values_limit'] )
			? (int) $options['taxonomy_allowed_values_limit']
			: self::DEFAULT_TAXONOMY_ALLOWED_VALUES_LIMIT;

		return max(
			self::TAXONOMY_ALLOWED_VALUES_LIMIT_MIN,
			min( self::TAXONOMY_ALLOWED_VALUES_LIMIT_MAX, $value )
		);
	}

	public static function sanitize_document_max_chars( int $value ): int {
		if ( $value === 0 ) {
			return 0;
		}

		return max(
			1000,
			min( self::DOCUMENT_MAX_CHARS_MAX, $value )
		);
	}

	public static function sanitize_pdf_visual_max_pages( int $value ): int {
		return max(
			self::PDF_VISUAL_MAX_PAGES_MIN,
			min( self::PDF_VISUAL_MAX_PAGES_MAX, $value )
		);
	}

	public static function sanitize_taxonomy_allowed_values_limit( int $value ): int {
		return max(
			self::TAXONOMY_ALLOWED_VALUES_LIMIT_MIN,
			min( self::TAXONOMY_ALLOWED_VALUES_LIMIT_MAX, $value )
		);
	}
}

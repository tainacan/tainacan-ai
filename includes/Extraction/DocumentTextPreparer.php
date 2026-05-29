<?php
declare(strict_types=1);

namespace Tainacan\AI\Extraction;

use Tainacan\AI\Support\AnalysisLimits;
use Tainacan\AI\Support\ProcessingWarnings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes document text before it is sent to the model.
 */
final class DocumentTextPreparer {

	/** @deprecated Use AnalysisLimits::DEFAULT_DOCUMENT_MAX_CHARS */
	public const MAX_ANALYSIS_CHARS = AnalysisLimits::DEFAULT_DOCUMENT_MAX_CHARS;

	/**
	 * @return array{
	 *     content: string,
	 *     truncated: bool,
	 *     original_length: int,
	 *     sent_length: int,
	 *     warnings: ProcessingWarnings
	 * }
	 */
	public static function prepare( string $raw, string $mime_type ): array {
		$warnings = new ProcessingWarnings();
		$text     = self::sanitize_utf8_string( $raw );

		if ( $mime_type === 'text/html' ) {
			$text = HtmlContentFilter::filter( $text );
			$text = self::sanitize_utf8_string( $text );
		}

		$original_length = mb_strlen( $text, 'UTF-8' );
		$max_chars       = AnalysisLimits::get_document_max_chars();
		$truncated       = $max_chars > 0 && $original_length > $max_chars;

		if ( $truncated ) {
			$text = mb_substr( $text, 0, $max_chars, 'UTF-8' );
			$warnings->add(
				'document_truncated',
				'warning',
				sprintf(
					/* translators: 1: maximum characters sent, 2: total document length */
					__( 'Only the first %1$s characters of the document were sent to the model (%2$s total). Later content was not processed.', 'tainacan-ai' ),
					number_format_i18n( $max_chars ),
					number_format_i18n( $original_length )
				),
				array(
					'max_chars'       => $max_chars,
					'original_length' => $original_length,
					'sent_length'     => $max_chars,
				)
			);
		}

		$sent_length = $max_chars > 0
			? min( $original_length, $max_chars )
			: $original_length;

		return array(
			'content'         => $text,
			'truncated'       => $truncated,
			'original_length' => $original_length,
			'sent_length'     => $sent_length,
			'warnings'        => $warnings,
		);
	}

	public static function sanitize_utf8_string( string $string ): string {
		if ( mb_check_encoding( $string, 'UTF-8' ) ) {
			return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string );
		}

		$encodings = array( 'ISO-8859-1', 'Windows-1252', 'ASCII' );

		foreach ( $encodings as $encoding ) {
			$converted = @mb_convert_encoding( $string, 'UTF-8', $encoding );
			if ( $converted !== false && mb_check_encoding( $converted, 'UTF-8' ) ) {
				return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $converted );
			}
		}

		$string = mb_convert_encoding( $string, 'UTF-8', 'UTF-8' );
		$string = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string );
		$string = (string) preg_replace( '/[\x80-\xFF](?![\x80-\xBF])|(?<![\xC0-\xFF])[\x80-\xBF]/', '', $string );

		return $string;
	}
}

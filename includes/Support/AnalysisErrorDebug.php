<?php
declare(strict_types=1);

namespace Tainacan\AI\Support;

use Tainacan\AI\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for attaching optional debug payloads to WP_Error::error_data.
 *
 * Debug sections are exposed in REST only when advanced debugging is enabled
 * and the user can edit posts.
 */
final class AnalysisErrorDebug {
	public const MAX_RAW_LENGTH = 12000;

	public static function should_include_in_response(): bool {
		$include = current_user_can( 'edit_posts' ) && Plugin::is_advanced_debug();

		/**
		 * @param bool $include Default: advanced_debug option and edit_posts capability.
		 */
		return (bool) apply_filters( 'tainacan_ai_include_error_debug_in_response', $include );
	}

	/**
	 * @return array{content: string, truncated: bool, length: int}
	 */
	public static function truncate( string $text, ?int $max_length = null ): array {
		$max_length = $max_length ?? self::MAX_RAW_LENGTH;
		$length     = strlen( $text );

		if ( $length <= $max_length ) {
			return array(
				'content'   => $text,
				'truncated' => false,
				'length'    => $length,
			);
		}

		return array(
			'content'   => substr( $text, 0, $max_length ) . "\n…",
			'truncated' => true,
			'length'    => $length,
		);
	}

	/**
	 * Build the WP_Error data array (third constructor argument).
	 *
	 * @param array<string, string|array{label?: string, content: string, truncated?: bool}> $debug_fields
	 * @param array<string, mixed>|null $request_meta Optional tokens/model/provider for the Request tab.
	 * @return array<string, mixed>
	 */
	public static function data(
		array $debug_fields = array(),
		int $status = 400,
		?array $request_meta = null
	): array {
		$data = array( 'status' => $status );

		$normalized_request_meta = self::normalize_request_meta( $request_meta );
		if ( $normalized_request_meta !== null ) {
			$data['request_meta'] = $normalized_request_meta;
		}

		if ( self::should_include_in_response() && $debug_fields !== array() ) {
			$data['debug_details'] = self::normalize_debug_details( $debug_fields );
		}

		return $data;
	}

	/**
	 * @param array<string, mixed>|null $request_meta
	 * @return array<string, int|string>|null
	 */
	public static function normalize_request_meta( ?array $request_meta ): ?array {
		if ( $request_meta === null || $request_meta === array() ) {
			return null;
		}

		$tokens = $request_meta['tokens_used'] ?? $request_meta['total_tokens'] ?? 0;
		$normalized = array(
			'tokens_used'         => (int) $tokens,
			'prompt_tokens'       => (int) ( $request_meta['prompt_tokens'] ?? 0 ),
			'completion_tokens'   => (int) ( $request_meta['completion_tokens'] ?? 0 ),
			'model_used'          => (string) ( $request_meta['model_used'] ?? $request_meta['model'] ?? '' ),
			'model_name'          => (string) ( $request_meta['model_name'] ?? '' ),
			'provider_used'       => (string) ( $request_meta['provider_used'] ?? $request_meta['provider'] ?? '' ),
			'provider_name'       => (string) ( $request_meta['provider_name'] ?? '' ),
			'finish_reason'       => (string) ( $request_meta['finish_reason'] ?? '' ),
			'analysis_mode'       => (string) ( $request_meta['analysis_mode'] ?? '' ),
		);

		foreach ( array( 'request_characters', 'response_characters', 'duration_ms' ) as $numeric_key ) {
			if ( ! isset( $request_meta[ $numeric_key ] ) ) {
				continue;
			}

			$value = (int) $request_meta[ $numeric_key ];
			if ( $value > 0 ) {
				$normalized[ $numeric_key ] = $value;
			}
		}

		return $normalized;
	}

	/**
	 * @return array{tokens_used: int, model_used: string, provider_used: string, request_characters?: int}|null
	 */
	public static function request_meta_from_wp_error( ?\WP_Error $error ): ?array {
		if ( ! $error instanceof \WP_Error ) {
			return null;
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::normalize_request_meta(
			isset( $data['request_meta'] ) && is_array( $data['request_meta'] )
				? $data['request_meta']
				: null
		);
	}

	/**
	 * @param array<string, string|array{label?: string, content: string, truncated?: bool}> $debug_fields
	 * @param array<string, mixed>|null $request_meta
	 */
	public static function wp_error(
		string $code,
		string $message,
		array $debug_fields = array(),
		int $status = 400,
		?array $request_meta = null
	): \WP_Error {
		return new \WP_Error( $code, $message, self::data( $debug_fields, $status, $request_meta ) );
	}

	/**
	 * Wrap an existing WP_Error with additional context while preserving debug sections.
	 *
	 * @param array<string, string|array{label?: string, content: string, truncated?: bool}> $extra_fields
	 */
	public static function wrap(
		\WP_Error $inner,
		string $code,
		string $message,
		array $extra_fields = array(),
		?int $status = null
	): \WP_Error {
		$inner_data = $inner->get_error_data();
		if ( ! is_array( $inner_data ) ) {
			$inner_data = array();
		}

		$resolved_status = $status;
		if ( $resolved_status === null ) {
			$inner_status = $inner_data['status'] ?? null;
			$resolved_status = is_int( $inner_status ) && $inner_status > 0 ? $inner_status : 400;
		}

		$fields = array_merge(
			self::export_wp_error_context( $inner, 'underlying' ),
			$extra_fields
		);

		return new \WP_Error(
			$code,
			$message,
			self::data( $fields, $resolved_status, self::request_meta_from_wp_error( $inner ) )
		);
	}

	/**
	 * @return array<string, string|array{label: string, content: string, truncated: bool}>
	 */
	public static function export_wp_error_context( \WP_Error $error, string $prefix = '' ): array {
		$key_prefix = $prefix !== '' ? $prefix . '_' : '';

		$fields = array(
			$key_prefix . 'error_code'    => $error->get_error_code(),
			$key_prefix . 'error_message' => $error->get_error_message(),
		);

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return $fields;
		}

		if ( isset( $data['status'] ) ) {
			$fields[ $key_prefix . 'http_status' ] = (string) $data['status'];
		}

		$debug_details = $data['debug_details'] ?? null;
		if ( ! is_array( $debug_details ) || empty( $debug_details['sections'] ) ) {
			return $fields;
		}

		$section_prefix = $prefix !== '' ? $prefix . ' — ' : '';

		foreach ( $debug_details['sections'] as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$section_id = (string) ( $section['id'] ?? 'detail' );
			$fields[ $key_prefix . $section_id ] = array(
				'label'     => $section_prefix . (string) ( $section['label'] ?? self::label_for( $section_id ) ),
				'content'   => (string) ( $section['content'] ?? '' ),
				'truncated' => (bool) ( $section['truncated'] ?? false ),
			);
		}

		return $fields;
	}

	/**
	 * @return array<string, string|array{label: string, content: string, truncated: bool}>
	 */
	public static function throwable_fields( \Throwable $e, bool $include_trace = true ): array {
		$fields = array(
			'exception' => get_class( $e ),
		);

		if ( ! $include_trace ) {
			return $fields;
		}

		$trace = $e->getTraceAsString();
		if ( $trace === '' ) {
			return $fields;
		}

		$truncated_trace = self::truncate( $trace, 8000 );
		$fields['trace'] = array(
			'label'     => self::label_for( 'trace' ),
			'content'   => $truncated_trace['content'],
			'truncated' => $truncated_trace['truncated'],
		);

		return $fields;
	}

	public static function from_throwable(
		\Throwable $e,
		string $code,
		?string $message = null,
		int $status = 500
	): \WP_Error {
		return new \WP_Error(
			$code,
			$message ?? $e->getMessage(),
			self::data( self::throwable_fields( $e ), $status )
		);
	}

	public static function sanitize_url_for_debug( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}

		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';

		if ( strlen( $path ) > 120 ) {
			$path = substr( $path, 0, 120 ) . '…';
		}

		return $scheme . '://' . $host . $path;
	}

	public static function basename_for_debug( string $path ): string {
		$basename = basename( $path );

		return $basename !== '' ? $basename : $path;
	}

	/**
	 * @param array<string, string|array{label?: string, content: string, truncated?: bool}> $fields
	 * @return array{sections: list<array{id: string, label: string, content: string, truncated: bool}>}
	 */
	public static function normalize_debug_details( array $fields ): array {
		$sections = array();

		foreach ( $fields as $id => $value ) {
			if ( $value === null || $value === '' || $value === array() ) {
				continue;
			}

			$section_id = (string) $id;

			if ( is_string( $value ) || is_numeric( $value ) ) {
				$sections[] = array(
					'id'        => $section_id,
					'label'     => self::label_for( $section_id ),
					'content'   => (string) $value,
					'truncated' => false,
				);
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			$sections[] = array(
				'id'        => $section_id,
				'label'     => (string) ( $value['label'] ?? self::label_for( $section_id ) ),
				'content'   => (string) ( $value['content'] ?? '' ),
				'truncated' => (bool) ( $value['truncated'] ?? false ),
			);
		}

		return array( 'sections' => $sections );
	}

	public static function label_for( string $id ): string {
		switch ( $id ) {
			case 'raw_response':
				return __( 'Raw model response', 'tainacan-ai' );
			case 'json_error':
				return __( 'JSON parser message', 'tainacan-ai' );
			case 'response_length':
				return __( 'Response length (bytes)', 'tainacan-ai' );
			case 'exception':
				return __( 'Exception type', 'tainacan-ai' );
			case 'trace':
				return __( 'Stack trace', 'tainacan-ai' );
			case 'underlying_error_code':
			case 'text_extraction_error_code':
			case 'visual_analysis_error_code':
				return __( 'Error code', 'tainacan-ai' );
			case 'underlying_error_message':
			case 'text_extraction_error_message':
			case 'visual_analysis_error_message':
				return __( 'Error message', 'tainacan-ai' );
			case 'extracted_text_length':
				return __( 'Extracted text length', 'tainacan-ai' );
			case 'extracted_text_sample':
				return __( 'Extracted text sample', 'tainacan-ai' );
			case 'extraction_methods_tried':
				return __( 'Extraction methods tried', 'tainacan-ai' );
			case 'vision_supported':
				return __( 'Vision analysis available', 'tainacan-ai' );
			case 'visual_analysis_status':
				return __( 'Visual analysis status', 'tainacan-ai' );
			case 'document_url':
				return __( 'Document URL', 'tainacan-ai' );
			case 'mime_type':
				return __( 'MIME type', 'tainacan-ai' );
			case 'file_path':
				return __( 'File name', 'tainacan-ai' );
			case 'file_size':
				return __( 'File size (bytes)', 'tainacan-ai' );
			case 'http_status':
				return __( 'HTTP status', 'tainacan-ai' );
			case 'pdf_pages_converted':
				return __( 'PDF pages sent to vision', 'tainacan-ai' );
			default:
				return ucwords( str_replace( '_', ' ', $id ) );
		}
	}
}

<?php
declare(strict_types=1);

namespace Tainacan\AI\Extraction;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes non-content HTML noise while preserving semantic markup useful for extraction.
 *
 * Also strips inline class and style attributes after structural cleanup.
 *
 * Kept on purpose: meta, title, headings, details/summary, geo, article/main, tables,
 * lists, paragraphs, links with text, img alt text (tag removed, alt kept when present).
 *
 * Intentionally not removed: nav, header, footer — they are often site chrome, but some
 * pages place meaningful headings or breadcrumbs there; stripping them is site-specific.
 */
final class HtmlContentFilter {

	/** @var list<string> */
	private const BLOCK_TAGS_TO_REMOVE = array(
		'script',
		'style',
		'noscript',
		'svg',
		'canvas',
		'iframe',
		'video',
		'audio',
		'embed',
		'object',
		'picture',
		'template',
		'form',
	);

	/** @var list<string> */
	private const VOID_TAGS_TO_REMOVE = array(
		'input',
		'button',
		'textarea',
		'select',
		'option',
		'datalist',
	);

	/**
	 * Strip scripts, styles, SVG/media embeds, forms, and other boilerplate.
	 */
	public static function filter( string $html ): string {
		if ( trim( $html ) === '' ) {
			return '';
		}

		$html = self::remove_tag_blocks( $html, self::BLOCK_TAGS_TO_REMOVE );
		$html = self::remove_void_tags( $html, self::VOID_TAGS_TO_REMOVE );
		$html = self::remove_icon_elements( $html );
		$html = self::replace_images_with_alt_text( $html );
		$html = self::strip_presentation_attributes( $html );
		$html = preg_replace( '/<!--[\s\S]*?-->/', '', $html ) ?? $html;
		$html = self::remove_noise_link_tags( $html );
		$html = self::normalize_non_breaking_spaces( $html );
		$html = self::collapse_repeated_whitespace( $html );
		$html = preg_replace( '/>\s+</', '><', $html ) ?? $html;
		$html = preg_replace( "/[ \t]+\n/", "\n", $html ) ?? $html;
		$html = preg_replace( "/\n{3,}/", "\n\n", $html ) ?? $html;

		return trim( $html );
	}

	/**
	 * @param list<string> $tag_names
	 */
	private static function remove_tag_blocks( string $html, array $tag_names ): string {
		foreach ( $tag_names as $tag_name ) {
			$pattern = sprintf(
				'/<\s*%1$s\b[^>]*>.*?<\s*\/\s*%1$s\s*>/is',
				preg_quote( $tag_name, '/' )
			);

			$previous = null;
			while ( $previous !== $html ) {
				$previous = $html;
				$html     = preg_replace( $pattern, '', $html ) ?? $html;
			}
		}

		return $html;
	}

	/**
	 * @param list<string> $tag_names
	 */
	private static function remove_void_tags( string $html, array $tag_names ): string {
		foreach ( $tag_names as $tag_name ) {
			$pattern = sprintf(
				'/<\s*%1$s\b[^>]*\/?>/is',
				preg_quote( $tag_name, '/' )
			);
			$html = preg_replace( $pattern, '', $html ) ?? $html;
		}

		return $html;
	}

	/**
	 * Remove empty icon-font elements (Font Awesome, Dashicons, etc.).
	 */
	private static function remove_icon_elements( string $html ): string {
		return preg_replace_callback(
			'/<\s*(i|span)\b[^>]*\bclass\s*=\s*["\'][^"\']*\b(fa[srb]?|fa-|dashicons|icon-)\b[^"\']*["\'][^>]*>\s*<\/\s*\1\s*>/is',
			static function (): string {
				return '';
			},
			$html
		) ?? $html;
	}

	/**
	 * Drop img tags but keep meaningful alt text inline for the model.
	 */
	private static function replace_images_with_alt_text( string $html ): string {
		return preg_replace_callback(
			'/<\s*img\b([^>]*)>/is',
			static function ( array $matches ): string {
				$attributes = $matches[1];

				if ( ! preg_match( '/\balt\s*=\s*("|\')(.*?)\1/is', $attributes, $alt_match ) ) {
					return '';
				}

				$alt = trim( html_entity_decode( $alt_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( $alt === '' ) {
					return '';
				}

				return '[Image: ' . $alt . ']';
			},
			$html
		) ?? $html;
	}

	/**
	 * Remove presentation attributes that add noise without helping extraction.
	 */
	private static function strip_presentation_attributes( string $html ): string {
		$patterns = array(
			'/\s+class\s*=\s*"[^"]*"/i',
			"/\s+class\s*=\s*'[^']*'/i",
			'/\s+style\s*=\s*"[^"]*"/i',
			"/\s+style\s*=\s*'[^']*'/i",
		);

		foreach ( $patterns as $pattern ) {
			$html = preg_replace( $pattern, '', $html ) ?? $html;
		}

		return $html;
	}

	private static function remove_noise_link_tags( string $html ): string {
		return preg_replace_callback(
			'/<\s*link\b[^>]*>/is',
			static function ( array $matches ): string {
				$tag = $matches[0];

				if ( preg_match( '/\brel\s*=\s*["\']?(stylesheet|preload|prefetch|icon|dns-prefetch|manifest|apple-touch-icon)/i', $tag ) ) {
					return '';
				}

				return $tag;
			},
			$html
		) ?? $html;
	}

	/**
	 * Normalize common non-breaking-space entities to regular spaces.
	 */
	private static function normalize_non_breaking_spaces( string $html ): string {
		return (string) preg_replace( '/&(nbsp|#160|#xA0);/i', ' ', $html );
	}

	/**
	 * Collapse repeated in-line spaces and tabs to reduce prompt noise.
	 */
	private static function collapse_repeated_whitespace( string $html ): string {
		return (string) preg_replace( '/[ \t]{2,}/', ' ', $html );
	}
}

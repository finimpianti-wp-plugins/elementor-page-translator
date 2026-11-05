<?php
/**
 * Fallback rule for unknown widgets: which settings look like visible text.
 *
 * Pure class (no WordPress), shared by the census and the Extractor.
 * Rule from SPECIFICHE.md, "Regola di riserva", plus the owner's decision that
 * values without any letter are not translatable.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Text heuristics.
 */
final class Text_Heuristics {

	/**
	 * A key is a candidate if it contains one of these words.
	 */
	public const KEY_WORDS = array( 'title', 'text', 'description', 'content', 'label', 'placeholder', 'caption', 'heading', 'editor', 'message', 'prefix', 'suffix', 'button', 'tooltip', 'name', 'job' );

	/**
	 * Keys starting with these are style settings.
	 */
	public const STYLE_PREFIXES = array( '_', 'typography_', 'align', 'color', 'background_', 'border_', 'css_' );

	/**
	 * Keys ending with these hold options, not text (e.g. "title_tag" = "h2", "show_title" handled by value).
	 */
	public const OPTION_SUFFIXES = array( '_tag', '_size', '_position', '_align', '_alignment', '_width', '_height', '_style', '_type', '_icon', '_color', '_layout', '_animation', '_spacing', '_padding', '_margin', '_display', '_view', '_shape', '_source', '_id', '_url', '_link' );

	/**
	 * Words that mark a style setting wherever they appear in the key
	 * (e.g. "flex_justify_content", "typography_text_decoration").
	 */
	public const STYLE_WORDS = array( 'typography', 'decoration', 'justify', 'flex_', 'align', 'transform', 'position', 'direction', 'overflow', 'display', 'animation', 'transition', 'motion_fx', 'sticky', 'hover_', '_hover', 'shadow', 'stroke', 'font_' );

	/**
	 * Responsive variants of a setting ("title_mobile" behaves like "title").
	 */
	public const RESPONSIVE_SUFFIXES = array( '_mobile_extra', '_tablet_extra', '_widescreen', '_laptop', '_tablet', '_mobile' );

	/**
	 * Strip a responsive suffix.
	 *
	 * @param string $key Key.
	 */
	public static function base_key( string $key ): string {
		foreach ( self::RESPONSIVE_SUFFIXES as $suffix ) {
			if ( str_ends_with( $key, $suffix ) ) {
				return substr( $key, 0, -strlen( $suffix ) );
			}
		}
		return $key;
	}

	/**
	 * Whether a setting key (last path segment) may hold visible text.
	 *
	 * @param string $key Setting key.
	 */
	public static function is_candidate_key( string $key ): bool {
		$key = self::base_key( strtolower( $key ) );
		foreach ( self::STYLE_WORDS as $word ) {
			if ( str_contains( $key, $word ) ) {
				return false;
			}
		}
		foreach ( self::STYLE_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return false;
			}
		}
		foreach ( self::OPTION_SUFFIXES as $suffix ) {
			if ( str_ends_with( $key, $suffix ) ) {
				return false;
			}
		}
		foreach ( self::KEY_WORDS as $word ) {
			if ( str_contains( $key, $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a value contains at least one letter (any script).
	 *
	 * @param string $value Value.
	 */
	public static function has_letters( string $value ): bool {
		return 1 === preg_match( '/\p{L}/u', self::strip_tags( $value ) );
	}

	/**
	 * Why a string value is not text, or null when it looks like text.
	 *
	 * @param string $value Value.
	 * @return string|null Reason code: empty, toggle, url, color, number, css_unit, id, icon, json, global, no_letters.
	 */
	public static function non_text_reason( string $value ): ?string {
		$v = trim( $value );
		if ( '' === $v ) {
			return 'empty';
		}
		if ( in_array( strtolower( $v ), array( 'yes', 'no', 'true', 'false', 'on', 'off' ), true ) ) {
			return 'toggle';
		}
		if ( preg_match( '#^((https?:)?//|mailto:|tel:|/[^\s]*$|\#[\w-]+$)#i', $v ) ) {
			return 'url';
		}
		if ( preg_match( '/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([^)]*\))$/i', $v ) ) {
			return 'color';
		}
		if ( is_numeric( $v ) ) {
			return 'number';
		}
		if ( preg_match( '/^-?\d*\.?\d+(px|em|rem|%|vh|vw|vmin|vmax|s|ms|deg|fr)$/i', $v ) ) {
			return 'css_unit';
		}
		if ( preg_match( '/^globals\//', $v ) ) {
			return 'global';
		}
		if ( preg_match( '/^(?=[a-f0-9]*\d)[a-f0-9]{7,8}$/', $v ) ) {
			return 'id';
		}
		if ( preg_match( '/^(fa[srbl]?|eicon|fab|fas|far)[ -][\w -]+$/i', $v ) || preg_match( '/^(eicon|fa)-[\w-]+$/i', $v ) ) {
			return 'icon';
		}
		if ( ( str_starts_with( $v, '{' ) || str_starts_with( $v, '[' ) ) && null !== json_decode( $v, true ) ) {
			return 'json';
		}
		if ( ! self::has_letters( $v ) ) {
			return 'no_letters';
		}
		return null;
	}

	/**
	 * Fallback rule: key and value both look like visible text.
	 *
	 * @param string $key   Setting key (last path segment).
	 * @param mixed  $value Value.
	 */
	public static function is_fallback_text( string $key, $value ): bool {
		return is_string( $value ) && self::is_candidate_key( $key ) && null === self::non_text_reason( $value );
	}

	/**
	 * Whether a string contains HTML tags.
	 *
	 * @param string $value Value.
	 */
	public static function has_html( string $value ): bool {
		return 1 === preg_match( '/<\/?[a-z][^>]*>/i', $value );
	}

	/**
	 * Strip tags without WordPress (same idea as wp_strip_all_tags()).
	 *
	 * @param string $text Text.
	 */
	public static function strip_tags( string $text ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		return strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure helper, no WordPress available.
	}
}

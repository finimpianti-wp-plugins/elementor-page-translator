<?php
/**
 * Simplified view of HTML texts in the translation screen (owner's request, v0.4.1).
 *
 * A text qualifies when, after removing editor noise, it is plain text and <br>
 * wrapped in zero or more outer tags that enclose everything
 * (e.g. <p><span style="white-space: pre-wrap;">…</span></p>). The screen then
 * shows plain text with line breaks; the wrapper is put back unchanged.
 *
 * Pure class. The screen (JS) rebuilds the HTML with the same rules as rebuild().
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Simple text.
 */
final class Simple_Text {

	/**
	 * Tags that may wrap the whole text.
	 */
	private const WRAPPERS = 'p|span|div|h[1-6]|strong|b|em|i|u';

	/**
	 * Split a text into wrapper and plain content, or null when it has real inline markup.
	 *
	 * @param string $html             HTML (already cleaned of noise attributes).
	 * @param bool   $newline_is_space Whether a raw newline renders as a space (true for most
	 *                                 widgets; false for the Text Editor, where wpautop turns it
	 *                                 into a line break).
	 * @return array{prefix: string, suffix: string, text: string}|null
	 */
	public static function analyze( string $html, bool $newline_is_space = false ): ?array {
		if ( ! Text_Heuristics::has_html( $html ) ) {
			return null;
		}
		$prefix = '';
		$suffix = '';
		$inner  = $html;
		while ( preg_match( '#^(\s*<(' . self::WRAPPERS . ')\b[^>]*>)(.*)(</\2>\s*)$#is', $inner, $m ) ) {
			// The wrapper must enclose everything: no other tag of the same name inside.
			if ( preg_match( '#<' . $m[2] . '\b#i', $m[3] ) ) {
				break;
			}
			$prefix .= $m[1];
			$suffix  = $m[4] . $suffix;
			$inner   = $m[3];
		}
		// A raw newline right after <br> changes nothing (wpautop ignores it too).
		$inner = (string) preg_replace( '#(<br\s*/?>)[ \t]*\r?\n#i', '$1', $inner );
		if ( $newline_is_space ) {
			$inner = (string) preg_replace( '/[ \t]*\r?\n[ \t]*/', ' ', $inner );
		}
		// Only text and line breaks may remain.
		if ( preg_match( '#<(?!br\s*/?>)#i', $inner ) || preg_match( "/[\r\n]/", $inner ) ) {
			return null;
		}
		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $inner );
		return array(
			'prefix' => $prefix,
			'suffix' => $suffix,
			'text'   => html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
		);
	}

	/**
	 * Put plain text back into its wrapper.
	 *
	 * @param string $prefix Opening wrapper tags.
	 * @param string $suffix Closing wrapper tags.
	 * @param string $text   Plain text with "\n" for line breaks.
	 */
	public static function rebuild( string $prefix, string $suffix, string $text ): string {
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
		$text = str_replace( array( "\r\n", "\r", "\n" ), '<br>', $text );
		return $prefix . $text . $suffix;
	}
}

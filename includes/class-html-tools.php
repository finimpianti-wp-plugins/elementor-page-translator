<?php
/**
 * Small pure helpers for HTML texts.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * HTML tools.
 */
final class Html_Tools {

	/**
	 * Hidden attributes left by copy-paste from design tools (Figma). Removed from the
	 * translated copy only (owner's decision, SPECIFICHE.md, question F2).
	 */
	public const NOISE_ATTRIBUTES = array( 'data-metadata', 'data-buffer' );

	/**
	 * Remove noise attributes from every tag.
	 *
	 * @param string $html HTML.
	 */
	public static function strip_noise( string $html ): string {
		if ( ! str_contains( $html, 'data-' ) ) {
			return $html;
		}
		$names = implode( '|', array_map( static fn( string $a ): string => preg_quote( $a, '/' ), self::NOISE_ATTRIBUTES ) );
		// A bare <span> may remain: harmless, and unwrapping nested spans safely needs a parser.
		return (string) preg_replace( '/\s(?:' . $names . ')\s*=\s*(["\']).*?\1/is', '', $html );
	}

	/**
	 * Tag names in order (opening and closing, e.g. ["p", "/p", "br"]).
	 *
	 * @param string $html HTML.
	 * @return list<string>
	 */
	public static function tags( string $html ): array {
		preg_match_all( '/<\s*(\/?)\s*([a-z][a-z0-9-]*)\b[^>]*>/i', $html, $m, PREG_SET_ORDER );
		return array_map( static fn( array $x ): string => $x[1] . strtolower( $x[2] ), $m );
	}

	/**
	 * Whether a translation has the same number of each tag as the original.
	 *
	 * @param string $original    Original HTML.
	 * @param string $translation Translated HTML.
	 */
	public static function same_tags( string $original, string $translation ): bool {
		$a = array_count_values( self::tags( $original ) );
		$b = array_count_values( self::tags( $translation ) );
		ksort( $a );
		ksort( $b );
		return $a === $b;
	}

	/**
	 * Strip tags.
	 *
	 * @param string $html HTML.
	 */
	public static function strip_tags( string $html ): string {
		return Text_Heuristics::strip_tags( $html );
	}
}

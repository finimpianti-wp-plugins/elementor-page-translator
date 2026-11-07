<?php
/**
 * Adapter for the Complianz cookie banner (SPECIFICHE.md, "Banner dei cookie").
 *
 * Verified on Complianz 7.5.7 (free):
 * - the texts written in the banner settings are translated only through WPML / Polylang:
 *   each one passes through the filter "wpml_translate_single_string" (domain
 *   "complianz", name = field name + banner key), applied even without WPML;
 * - the built-in labels follow the WordPress locale (already switched by this plugin);
 * - the links to the policy pages pass through the filter "wpml_object_id" with the
 *   two-letter code of the locale.
 * Two versions of the banner: Italian on Italian pages, English on all the others
 * (ept_fallback_language()). The English texts are written in Settings -> Traduzioni.
 * Policy pages link to their version in the language of the page (or English, or Italian).
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Complianz.
 */
final class Complianz {

	public const OPTION  = 'ept_complianz';
	public const STRINGS = 'ept_complianz_strings';

	/**
	 * Hooks (only when Complianz is active).
	 */
	public static function init(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_hook' ), 20 );
	}

	/**
	 * Whether Complianz is active.
	 */
	public static function active(): bool {
		return defined( 'cmplz_version' ) || class_exists( 'COMPLIANZ', false );
	}

	/**
	 * Hook the filters.
	 */
	public static function maybe_hook(): void {
		if ( ! self::active() ) {
			return;
		}
		add_filter( 'wpml_translate_single_string', array( self::class, 'translate' ), 20, 3 );
		add_filter( 'wpml_object_id', array( self::class, 'page_id' ), 20, 4 );
	}

	/**
	 * English texts: string name => text.
	 *
	 * @return array<string,string>
	 */
	public static function translations(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * Banner texts seen on the site: string name => Italian text.
	 *
	 * @return array<string,string>
	 */
	public static function strings(): array {
		$stored = get_option( self::STRINGS, array() );
		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * Save the English texts (only for known strings).
	 *
	 * @param array<string,mixed> $posted name => text.
	 */
	public static function save( array $posted ): void {
		$known = self::strings();
		$out   = array();
		foreach ( $posted as $name => $text ) {
			$name = (string) $name;
			if ( isset( $known[ $name ] ) && is_scalar( $text ) ) {
				$text = wp_kses_post( trim( (string) $text ) );
				if ( '' !== $text ) {
					$out[ $name ] = $text;
				}
			}
		}
		update_option( self::OPTION, $out, false );
	}

	/**
	 * Filter wpml_translate_single_string for the Complianz domain.
	 *
	 * @param mixed  $value  Text.
	 * @param string $domain Domain.
	 * @param string $name   String name.
	 * @return mixed
	 */
	public static function translate( $value, $domain = '', $name = '' ) {
		if ( 'complianz' !== $domain || ! is_string( $value ) || '' === (string) $name ) {
			return $value;
		}
		$name = (string) $name;
		if ( Languages::default_code() === Current_Language::code() || is_admin() ) {
			self::remember( $name, $value );
		}
		if ( is_admin() || 'en' !== ept_fallback_language() ) {
			return $value;
		}
		$english = self::translations()[ $name ] ?? '';
		return '' !== $english ? $english : $value;
	}

	/**
	 * Remember a banner text in the default language (listed in the settings).
	 *
	 * @param string $name  Name.
	 * @param string $value Text.
	 */
	private static function remember( string $name, string $value ): void {
		if ( '' === trim( wp_strip_all_tags( $value ) ) ) {
			return;
		}
		$strings = self::strings();
		if ( ( $strings[ $name ] ?? null ) !== $value ) {
			$strings[ $name ] = $value;
			update_option( self::STRINGS, $strings, false );
		}
	}

	/**
	 * Filter wpml_object_id for pages: the version in the requested language when it is
	 * published (Complianz asks with the language of the locale); English, then the
	 * original otherwise.
	 *
	 * @param mixed  $id                Post ID.
	 * @param string $type              Post type.
	 * @param bool   $return_original   Return the original when missing.
	 * @param string $lang              Two-letter language.
	 * @return mixed
	 */
	public static function page_id( $id, $type = '', $return_original = true, $lang = '' ) {
		unset( $return_original );
		if ( 'page' !== $type || ! is_numeric( $id ) || ! (int) $id || is_admin() ) {
			return $id;
		}
		$source = Translations::is_source( (int) $id ) ? (int) $id : Translations::source_of( (int) $id );
		if ( ! $source ) {
			return $id;
		}
		$lang = '' !== (string) $lang ? strtolower( (string) $lang ) : Current_Language::code();
		foreach ( array_unique( array( $lang, 'en' ) ) as $code ) {
			if ( Languages::default_code() === $code ) {
				return $source;
			}
			$target = Translations::get_id( $source, $code );
			if ( $target && 'publish' === get_post_status( $target ) && Languages::is_visible( $code ) ) {
				return $target;
			}
		}
		return $source;
	}
}

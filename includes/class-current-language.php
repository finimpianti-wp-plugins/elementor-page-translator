<?php
/**
 * Current language of a front-end request (SPECIFICHE.md, "Lingua corrente").
 *
 * - Taken from the page being viewed: its _ept_lang, else the default language.
 * - Search results: the language sent by the search form (hidden field "lang").
 * - 404: the language whose prefix is the first segment of the URL (/de/… -> de).
 * - Other non-singular requests (archives) use the default language.
 * - Once the page is known (action "wp"), the WordPress locale is switched with
 *   switch_to_locale(): theme and plugin strings and <html lang> follow. When the
 *   language pack is not installed, the locale is still filtered so <html lang>
 *   is right (strings then stay in the default language).
 * - Never runs in the admin area: the "wp" action only fires on the front end.
 * - The language depends only on the URL: no cookies, no sessions (cache friendly).
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Current language.
 */
final class Current_Language {

	/**
	 * Detected language code, or null before detection.
	 *
	 * @var string|null
	 */
	private static ?string $code = null;

	/**
	 * Locale forced through filters when switch_to_locale() is not possible.
	 *
	 * @var string
	 */
	private static string $forced_locale = '';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp', array( self::class, 'detect' ), 1 );
	}

	/**
	 * Detect the language of the request and switch the locale.
	 */
	public static function detect(): void {
		if ( is_admin() ) {
			return;
		}
		self::$code = self::language_of_request();
		if ( Languages::default_code() === self::$code ) {
			return;
		}
		$locale = (string) ( Languages::get( self::$code )['locale'] ?? '' );
		if ( '' === $locale || determine_locale() === $locale ) {
			return;
		}
		if ( ! switch_to_locale( $locale ) ) {
			// Language pack not installed: force the locale so <html lang> and get_locale() are
			// right, and unload the default-language translations already loaded, so strings of
			// WordPress, theme and plugins fall back to their original (English) text instead of
			// staying in the default language. Domains stay reloadable for the forced locale.
			self::$forced_locale = $locale;
			add_filter( 'locale', array( self::class, 'forced_locale' ), 99 );
			add_filter( 'determine_locale', array( self::class, 'forced_locale' ), 99 );
			global $l10n;
			foreach ( array_keys( is_array( $l10n ) ? $l10n : array() ) as $domain ) {
				if ( 'ept' !== $domain ) {
					unload_textdomain( (string) $domain, true );
				}
			}
		}
	}

	/**
	 * Filter callback returning the forced locale.
	 *
	 * @return string
	 */
	public static function forced_locale() {
		return self::$forced_locale;
	}

	/**
	 * Language of the current request (from the queried page).
	 */
	private static function language_of_request(): string {
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id ) {
				$lang = Translations::language_of( $id );
				if ( null !== Languages::get( $lang ) ) {
					return $lang;
				}
			}
		}
		if ( is_search() ) {
			// The search form of a language carries it in a hidden field.
			$lang = Search::requested_language();
			if ( '' !== $lang ) {
				return $lang;
			}
		}
		if ( is_404() ) {
			// /de/pagina-inesistente -> German (header, footer and switcher in German).
			$lang = self::language_of_path( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
			if ( '' !== $lang ) {
				return $lang;
			}
		}
		return Languages::default_code();
	}

	/**
	 * Language whose prefix is the first segment of a URL path ('' when none or not visible).
	 *
	 * @param string $uri Request URI.
	 */
	public static function language_of_path( string $uri ): string {
		$path  = (string) Links::internal_path( '/' . ltrim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' ) );
		$first = strtolower( (string) strtok( trim( $path, '/' ), '/' ) );
		if ( '' === $first ) {
			return '';
		}
		foreach ( Languages::all() as $code => $lang ) {
			if ( (string) $lang['prefix'] === $first && ! empty( $lang['active'] ) && Languages::is_visible( (string) $code ) ) {
				return (string) $code;
			}
		}
		return '';
	}

	/**
	 * Current language code (default language before the page is known, and in the admin).
	 */
	public static function code(): string {
		return self::$code ?? Languages::default_code();
	}

	/**
	 * Reset (tests).
	 */
	public static function reset(): void {
		if ( '' !== self::$forced_locale ) {
			remove_filter( 'locale', array( self::class, 'forced_locale' ), 99 );
			remove_filter( 'determine_locale', array( self::class, 'forced_locale' ), 99 );
			self::$forced_locale = '';
		}
		if ( is_locale_switched() ) {
			restore_current_locale();
		}
		self::$code = null;
	}

	/**
	 * BCP 47 tag of a WordPress locale (en_GB => en-GB, de_DE_formal => de-DE).
	 *
	 * @param string $locale Locale.
	 */
	public static function bcp47( string $locale ): string {
		$parts = explode( '_', $locale );
		return strtolower( $parts[0] ) . ( isset( $parts[1] ) && preg_match( '/^[A-Za-z]{2}$/', $parts[1] ) ? '-' . strtoupper( $parts[1] ) : '' );
	}
}

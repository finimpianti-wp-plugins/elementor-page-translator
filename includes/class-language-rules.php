<?php
/**
 * Pure validation and normalisation rules for language configuration.
 *
 * No WordPress calls here: everything is unit-testable.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Language rules.
 */
final class Language_Rules {

	/**
	 * Language subtags written right-to-left. They are excluded in v1.
	 * This is not a list of supported languages, only an exclusion filter.
	 */
	public const RTL_LANGUAGES = array( 'ar', 'arq', 'ary', 'azb', 'ckb', 'dv', 'fa', 'haz', 'he', 'ps', 'sd', 'skr', 'ug', 'ur', 'yi' );

	/**
	 * Top-level slugs WordPress uses itself; a language prefix must not collide with them.
	 */
	public const RESERVED_PREFIXES = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'comments', 'search', 'author', 'category', 'tag', 'type', 'embed', 'trackback', 'attachment', 'sitemap', 'wp-sitemap' );

	/**
	 * Whether a WordPress locale is written right-to-left.
	 *
	 * @param string $locale Locale such as "ar" or "he_IL".
	 */
	public static function is_rtl_locale( string $locale ): bool {
		$lang = strtolower( (string) strtok( $locale, '_' ) );
		return in_array( $lang, self::RTL_LANGUAGES, true );
	}

	/**
	 * Default language code proposed for a locale: "en_GB" => "en", "pt_BR" => "pt".
	 *
	 * @param string $locale WordPress locale.
	 */
	public static function code_from_locale( string $locale ): string {
		return strtolower( (string) strtok( $locale, '_' ) );
	}

	/**
	 * Whether a string is a syntactically valid WordPress locale.
	 *
	 * @param string $locale Locale.
	 */
	public static function is_valid_locale( string $locale ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]+)?$/', $locale );
	}

	/**
	 * Whether a language code is valid: "en", "pt-br".
	 *
	 * @param string $code Code.
	 */
	public static function is_valid_code( string $code ): bool {
		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $code );
	}

	/**
	 * Whether a URL prefix is syntactically valid (a single lowercase slug segment).
	 *
	 * @param string $prefix Prefix.
	 */
	public static function is_valid_prefix( string $prefix ): bool {
		return 1 === preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $prefix )
			&& ! in_array( $prefix, self::RESERVED_PREFIXES, true );
	}

	/**
	 * Normalise one additional language entry and collect validation errors.
	 *
	 * Error messages are returned as stable error codes; the caller turns them
	 * into translated messages.
	 *
	 * @param array<string,mixed>               $input        Raw values.
	 * @param array<string,array<string,mixed>> $others       Other configured languages keyed by code (excluding this one).
	 * @param string                            $default_code Default language code.
	 * @return array{0: array<string,mixed>, 1: list<string>} Normalised entry and error codes.
	 */
	public static function normalize( array $input, array $others, string $default_code ): array {
		$errors = array();

		$code   = strtolower( trim( (string) ( $input['code'] ?? '' ) ) );
		$locale = trim( (string) ( $input['locale'] ?? '' ) );
		$name   = trim( (string) ( $input['name'] ?? '' ) );
		$prefix = strtolower( trim( (string) ( $input['prefix'] ?? '' ) ) );
		if ( '' === $prefix ) {
			$prefix = $code;
		}

		if ( ! self::is_valid_locale( $locale ) ) {
			$errors[] = 'invalid_locale';
		} elseif ( self::is_rtl_locale( $locale ) ) {
			$errors[] = 'rtl_locale';
		}

		if ( ! self::is_valid_code( $code ) ) {
			$errors[] = 'invalid_code';
		} elseif ( $code === $default_code ) {
			$errors[] = 'code_is_default';
		} elseif ( isset( $others[ $code ] ) ) {
			$errors[] = 'duplicate_code';
		}

		if ( '' === $name ) {
			$errors[] = 'empty_name';
		}

		if ( ! self::is_valid_prefix( $prefix ) ) {
			$errors[] = 'invalid_prefix';
		} else {
			foreach ( $others as $other ) {
				if ( ( $other['prefix'] ?? '' ) === $prefix ) {
					$errors[] = 'duplicate_prefix';
					break;
				}
			}
		}

		$entry = array(
			'code'         => $code,
			'locale'       => $locale,
			'name'         => $name,
			'prefix'       => $prefix,
			'active'       => ! empty( $input['active'] ),
			'public'       => ! empty( $input['public'] ),
			'order'        => (int) ( $input['order'] ?? 0 ),
			'root_page_id' => max( 0, (int) ( $input['root_page_id'] ?? 0 ) ),
		);

		return array( $entry, $errors );
	}

	/**
	 * Sort languages by "order", then by code, keeping keys.
	 *
	 * @param array<string,array<string,mixed>> $languages Languages keyed by code.
	 * @return array<string,array<string,mixed>>
	 */
	public static function sort( array $languages ): array {
		uasort(
			$languages,
			static function ( array $a, array $b ): int {
				return array( (int) $a['order'], $a['code'] ) <=> array( (int) $b['order'], $b['code'] );
			}
		);
		return $languages;
	}
}

<?php
/**
 * Language configuration storage (option "ept_languages").
 *
 * Shape:
 * [
 *   'default'   => [ 'code' => 'it', 'locale' => 'it_IT', 'name' => 'Italiano' ],
 *   'languages' => [ 'en' => [ code, locale, name, prefix, active, public, order, root_page_id ], … ],
 * ]
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Languages.
 */
final class Languages {

	public const OPTION = 'ept_languages';

	/**
	 * Request-level cache.
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $config = null;

	/**
	 * Full configuration, with defaults filled in.
	 *
	 * @return array{default: array<string,string>, languages: array<string,array<string,mixed>>}
	 */
	public static function config(): array {
		if ( null === self::$config ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			$default = isset( $stored['default'] ) && is_array( $stored['default'] ) ? $stored['default'] : self::initial_default();

			$languages = isset( $stored['languages'] ) && is_array( $stored['languages'] ) ? $stored['languages'] : array();

			self::$config = array(
				'default'   => $default,
				'languages' => Language_Rules::sort( $languages ),
			);
		}
		return self::$config;
	}

	/**
	 * Default language derived from the site locale on first use (it_IT => it).
	 *
	 * @return array<string,string>
	 */
	public static function initial_default(): array {
		$locale = (string) get_option( 'WPLANG', '' );
		$locale = '' !== $locale ? $locale : 'en_US';
		return array(
			'code'   => Language_Rules::code_from_locale( $locale ),
			'locale' => $locale,
			'name'   => self::native_name( $locale ),
		);
	}

	/**
	 * Persist the configuration.
	 *
	 * @param array<string,mixed> $config Configuration.
	 */
	public static function save( array $config ): void {
		$config['languages'] = Language_Rules::sort( $config['languages'] ?? array() );
		update_option( self::OPTION, $config, true );
		self::$config = null;
		wp_cache_delete( 'ept_reserved_prefixes', 'ept' );
	}

	/**
	 * Clear the request cache (tests, option changes from elsewhere).
	 */
	public static function flush_cache(): void {
		self::$config = null;
	}

	/**
	 * Default language entry.
	 *
	 * @return array<string,string>
	 */
	public static function default_language(): array {
		return self::config()['default'];
	}

	/**
	 * Default language code.
	 */
	public static function default_code(): string {
		return (string) self::config()['default']['code'];
	}

	/**
	 * All additional languages, sorted.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return self::config()['languages'];
	}

	/**
	 * Active additional languages (can be translated).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function active(): array {
		return array_filter( self::all(), static fn( array $l ): bool => ! empty( $l['active'] ) );
	}

	/**
	 * Active and public languages (visible to visitors).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function published(): array {
		return array_filter( self::active(), static fn( array $l ): bool => ! empty( $l['public'] ) );
	}

	/**
	 * One additional language.
	 *
	 * @param string $code Code.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $code ): ?array {
		return self::all()[ $code ] ?? null;
	}

	/**
	 * Store one language entry (insert or replace).
	 *
	 * @param array<string,mixed> $entry Normalised entry.
	 */
	public static function put( array $entry ): void {
		$config                                = self::config();
		$config['languages'][ $entry['code'] ] = $entry;
		self::save( $config );
	}

	/**
	 * Remove a language from the configuration. Pages are never deleted.
	 *
	 * @param string $code Code.
	 */
	public static function remove( string $code ): void {
		$config = self::config();
		unset( $config['languages'][ $code ] );
		self::save( $config );
	}

	/**
	 * Prefixes of all configured languages (active or not), keyed by code.
	 *
	 * @return array<string,string>
	 */
	public static function prefixes(): array {
		return array_map( static fn( array $l ): string => (string) $l['prefix'], self::all() );
	}

	/**
	 * Locales WordPress offers for download, without right-to-left ones.
	 * Falls back to installed languages when the translations API is unreachable.
	 *
	 * @return array<string,string> locale => native name
	 */
	public static function available_locales(): array {
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$list         = array();
		$translations = wp_get_available_translations();
		foreach ( $translations as $locale => $data ) {
			$list[ $locale ] = (string) ( $data['native_name'] ?? $locale );
		}
		foreach ( get_available_languages() as $locale ) {
			if ( ! isset( $list[ $locale ] ) ) {
				$list[ $locale ] = $locale;
			}
		}
		$list['en_US'] = $list['en_US'] ?? 'English (United States)';
		$list          = array_filter(
			$list,
			static fn( string $name, string $locale ): bool => ! Language_Rules::is_rtl_locale( $locale ),
			ARRAY_FILTER_USE_BOTH
		);
		asort( $list, SORT_NATURAL | SORT_FLAG_CASE );
		return $list;
	}

	/**
	 * Native name of a locale ("Italiano"), or the locale itself.
	 *
	 * @param string $locale Locale.
	 */
	public static function native_name( string $locale ): string {
		if ( 'en_US' === $locale ) {
			return 'English (United States)';
		}
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$translations = wp_get_available_translations();
		return (string) ( $translations[ $locale ]['native_name'] ?? $locale );
	}

	/**
	 * Whether the WordPress language pack for a locale is installed.
	 *
	 * @param string $locale Locale.
	 */
	public static function is_language_pack_installed( string $locale ): bool {
		return 'en_US' === $locale || in_array( $locale, get_available_languages(), true );
	}

	/**
	 * Translated message for a validation error code from Language_Rules.
	 *
	 * @param string $code Error code.
	 */
	public static function error_message( string $code ): string {
		$messages = array(
			'invalid_locale'   => __( 'Locale non valido.', 'ept' ),
			'rtl_locale'       => __( 'Le lingue scritte da destra a sinistra non sono supportate.', 'ept' ),
			'invalid_code'     => __( 'Codice lingua non valido: usa lettere minuscole, per esempio "en" o "pt-br".', 'ept' ),
			'code_is_default'  => __( 'Il codice coincide con quello della lingua predefinita.', 'ept' ),
			'duplicate_code'   => __( 'Esiste già una lingua con questo codice.', 'ept' ),
			'empty_name'       => __( 'Il nome della lingua è obbligatorio.', 'ept' ),
			'invalid_prefix'   => __( 'Prefisso URL non valido o riservato da WordPress: usa lettere minuscole, numeri e trattini.', 'ept' ),
			'duplicate_prefix' => __( 'Il prefisso URL è già usato da un\'altra lingua.', 'ept' ),
		);
		return $messages[ $code ] ?? $code;
	}
}

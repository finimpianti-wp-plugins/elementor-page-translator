<?php
/**
 * Site name and tagline per language (SPECIFICHE.md, "Nome e motto del sito per lingua").
 *
 * Written in Settings -> Traduzioni; on the front end of a page in another language the
 * options blogname and blogdescription are filtered, so the browser tab title, search
 * results, Open Graph and the UAE Site Title / Site Tagline widgets follow the language.
 * Empty values keep the ones of WordPress.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Site identity.
 */
final class Site_Identity {

	public const OPTION = 'ept_site_identity';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'option_blogname', array( self::class, 'name' ) );
		add_filter( 'option_blogdescription', array( self::class, 'tagline' ) );
	}

	/**
	 * Stored values: lang => [name, tagline].
	 *
	 * @return array<string,array{name:string,tagline:string}>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $lang => $row ) {
			if ( is_array( $row ) ) {
				$out[ (string) $lang ] = array(
					'name'    => (string) ( $row['name'] ?? '' ),
					'tagline' => (string) ( $row['tagline'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/**
	 * Save values (sanitised).
	 *
	 * @param array<string,mixed> $posted lang => [name, tagline].
	 */
	public static function save( array $posted ): void {
		$out = array();
		foreach ( $posted as $lang => $row ) {
			$lang = sanitize_key( (string) $lang );
			if ( null === Languages::get( $lang ) || ! is_array( $row ) ) {
				continue;
			}
			$name    = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			$tagline = sanitize_text_field( (string) ( $row['tagline'] ?? '' ) );
			if ( '' !== $name || '' !== $tagline ) {
				$out[ $lang ] = array(
					'name'    => $name,
					'tagline' => $tagline,
				);
			}
		}
		update_option( self::OPTION, $out, true );
	}

	/**
	 * Value for the current language, or '' (front end only).
	 *
	 * @param string $field name|tagline.
	 */
	private static function value( string $field ): string {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		$lang = Current_Language::code();
		if ( Languages::default_code() === $lang ) {
			return '';
		}
		return self::all()[ $lang ][ $field ] ?? '';
	}

	/**
	 * Filter blogname.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function name( $value ) {
		$own = self::value( 'name' );
		return '' !== $own ? $own : $value;
	}

	/**
	 * Filter blogdescription.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function tagline( $value ) {
		$own = self::value( 'tagline' );
		return '' !== $own ? $own : $value;
	}
}

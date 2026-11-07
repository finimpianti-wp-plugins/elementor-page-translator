<?php
/**
 * Relations between Italian pages and their translations, and the translation
 * data stored on the Italian page (owner's decision, SPECIFICHE.md, F2):
 *
 * Italian page:
 *   _ept_translations     lang => translated post ID
 *   _ept_strings_{lang}   key  => [h => hash, o => original, t => translation, s => status]
 *   _ept_page_{lang}      [title, slug, status, parent_mode]
 * Translated page:
 *   _ept_lang, _ept_source_id, _ept_synced_source_hash, _ept_synced_output_hash, _ept_incomplete
 *
 * All relations are stored by ID, never by URL.
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Translations.
 */
final class Translations {

	public const META_MAP          = '_ept_translations';
	public const META_LANG         = '_ept_lang';
	public const META_SOURCE       = '_ept_source_id';
	public const META_SOURCE_HASH  = '_ept_synced_source_hash';
	public const META_OUTPUT_HASH  = '_ept_synced_output_hash';
	public const META_INCOMPLETE   = '_ept_incomplete';
	public const META_STRINGS_HASH = '_ept_synced_strings_hash';
	public const META_MISSING_KEYS = '_ept_missing_keys';

	/**
	 * Translation statuses for the pages list.
	 */
	public const STATE_OK       = 'ok';
	public const STATE_OUTDATED = 'outdated';
	public const STATE_MISSING  = 'missing';

	/**
	 * Row statuses of a string.
	 */
	public const STATUS_TRANSLATED = 'translated';
	public const STATUS_SAME       = 'same';
	public const STATUS_EXCLUDED   = 'excluded';

	/**
	 * Language of a post: its _ept_lang, or the default language.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function language_of( int $post_id ): string {
		$lang = (string) get_post_meta( $post_id, self::META_LANG, true );
		return '' !== $lang ? $lang : Languages::default_code();
	}

	/**
	 * Whether a post is in the default language (a possible translation source).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_source( int $post_id ): bool {
		return '' === (string) get_post_meta( $post_id, self::META_LANG, true );
	}

	/**
	 * Italian page of a translation (or 0).
	 *
	 * @param int $post_id Translated post ID.
	 */
	public static function source_of( int $post_id ): int {
		return (int) get_post_meta( $post_id, self::META_SOURCE, true );
	}

	/**
	 * Map lang => translated post ID of an Italian page; stale links are dropped.
	 *
	 * @param int $source_id Italian page ID.
	 * @return array<string,int>
	 */
	public static function map( int $source_id ): array {
		$cached = wp_cache_get( 'map_' . $source_id, 'ept' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$map = get_post_meta( $source_id, self::META_MAP, true );
		$map = is_array( $map ) ? array_map( 'intval', $map ) : array();
		$map = array_filter(
			$map,
			static function ( int $id ) use ( $source_id ): bool {
				$post = get_post( $id );
				return $post instanceof WP_Post && 'trash' !== $post->post_status && self::source_of( $id ) === $source_id;
			}
		);
		wp_cache_set( 'map_' . $source_id, $map, 'ept' );
		return $map;
	}

	/**
	 * Translated post ID for a language, or null.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language code.
	 */
	public static function get_id( int $source_id, string $lang ): ?int {
		return self::map( $source_id )[ $lang ] ?? null;
	}

	/**
	 * Link a translation to its Italian page.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 * @param int    $post_id   Translated post ID.
	 */
	public static function link( int $source_id, string $lang, int $post_id ): void {
		$map          = get_post_meta( $source_id, self::META_MAP, true );
		$map          = is_array( $map ) ? $map : array();
		$map[ $lang ] = $post_id;
		update_post_meta( $source_id, self::META_MAP, $map );
		update_post_meta( $post_id, self::META_LANG, $lang );
		update_post_meta( $post_id, self::META_SOURCE, $source_id );
		wp_cache_delete( 'map_' . $source_id, 'ept' );
	}

	/**
	 * Saved strings of a language.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 * @return array<string,array{h:string,o:string,t:string,s:string}>
	 */
	public static function strings( int $source_id, string $lang ): array {
		$data = get_post_meta( $source_id, '_ept_strings_' . $lang, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save strings of a language.
	 *
	 * @param int                 $source_id Italian page ID.
	 * @param string              $lang      Language.
	 * @param array<string,mixed> $strings   Strings.
	 */
	public static function save_strings( int $source_id, string $lang, array $strings ): void {
		update_post_meta( $source_id, '_ept_strings_' . $lang, wp_slash( $strings ) );
	}

	/**
	 * Saved page fields (title, slug, status, parent_mode) of a language.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 * @return array<string,string>
	 */
	public static function page_fields( int $source_id, string $lang ): array {
		$data = get_post_meta( $source_id, '_ept_page_' . $lang, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save page fields of a language.
	 *
	 * @param int                  $source_id Italian page ID.
	 * @param string               $lang      Language.
	 * @param array<string,string> $fields    Fields.
	 */
	public static function save_page_fields( int $source_id, string $lang, array $fields ): void {
		update_post_meta( $source_id, '_ept_page_' . $lang, wp_slash( $fields ) );
	}

	/**
	 * Hash of a stored _elementor_data (as saved in the database).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function data_hash( int $post_id, bool $portable = true ): string {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		$raw = is_string( $raw ) ? $raw : (string) wp_json_encode( $raw );
		return sha1( $portable ? self::portable( $raw ) : $raw );
	}

	/**
	 * Text with the site address replaced by a placeholder, so hashes survive a clone of the
	 * site with a new domain or folder (Duplicator, Better Search Replace, WP Staging push).
	 * Matches http and https, with or without www, with plain or JSON-escaped slashes.
	 *
	 * @param string $text Text.
	 */
	public static function portable( string $text ): string {
		$home = wp_parse_url( home_url() );
		$host = preg_replace( '/^www\./i', '', (string) ( $home['host'] ?? '' ) ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( '' === $host ) {
			return $text;
		}
		$path    = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		$slash   = '\\\\?/';
		$pattern = '#https?:(?:' . $slash . '){2}(?:www\.)?' . preg_quote( $host, '#' ) . str_replace( '/', $slash, preg_quote( $path, '#' ) ) . '(?![\w.-])#i';
		return (string) preg_replace( $pattern, '{home}', $text );
	}

	/**
	 * Fingerprint of everything a translation is generated from on the Italian side:
	 * Elementor data, page settings, page template and featured image.
	 *
	 * @param int $source_id Italian page ID.
	 */
	public static function source_fingerprint( int $source_id, bool $portable = true ): string {
		$settings = (string) wp_json_encode( get_post_meta( $source_id, '_elementor_page_settings', true ) );
		return sha1(
			self::data_hash( $source_id, $portable ) . '|' .
			( $portable ? self::portable( $settings ) : $settings ) . '|' .
			(string) get_post_meta( $source_id, '_wp_page_template', true ) . '|' .
			(string) get_post_meta( $source_id, '_thumbnail_id', true )
		);
	}

	/**
	 * Hash of the saved strings and page fields of a language (to spot drafts not yet applied).
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 */
	public static function strings_hash( int $source_id, string $lang, bool $portable = true ): string {
		$json = (string) wp_json_encode( array( self::strings( $source_id, $lang ), self::page_fields( $source_id, $lang ), Links::choices( $source_id, $lang ), get_post_meta( $source_id, '_ept_seo_' . $lang, true ), Refs::choices( $source_id, $lang ) ) );
		return sha1( $portable ? self::portable( $json ) : $json );
	}

	/**
	 * Whether a saved translation was made for this original text: same hash, or same
	 * original once the site address is ignored (cloned site).
	 *
	 * @param array<string,mixed> $entry Saved entry (h = hash, o = original).
	 * @param array<string,mixed> $item  Extracted string.
	 */
	public static function same_original( array $entry, array $item ): bool {
		if ( ( $entry['h'] ?? '' ) === $item['hash'] ) {
			return true;
		}
		$old = (string) ( $entry['o'] ?? '' );
		return '' !== $old && Extractor::normalize( self::portable( $old ) ) === Extractor::normalize( self::portable( (string) $item['text'] ) );
	}

	/**
	 * Status of a translation: ok, outdated (Italian page or draft changed since the last
	 * generation) or missing.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 */
	public static function status( int $source_id, string $lang ): string {
		$target = self::get_id( $source_id, $lang );
		if ( null === $target ) {
			return self::STATE_MISSING;
		}
		if ( get_post_meta( $target, self::META_SOURCE_HASH, true ) !== self::source_fingerprint( $source_id ) ) {
			return self::STATE_OUTDATED;
		}
		if ( get_post_meta( $target, self::META_STRINGS_HASH, true ) !== self::strings_hash( $source_id, $lang ) ) {
			return self::STATE_OUTDATED;
		}
		return self::STATE_OK;
	}

	/**
	 * Whether the translated page was edited by hand since the last generation.
	 *
	 * @param int $target_id Translated page ID.
	 */
	public static function has_manual_changes( int $target_id ): bool {
		$stored = (string) get_post_meta( $target_id, self::META_OUTPUT_HASH, true );
		return '' !== $stored && self::data_hash( $target_id ) !== $stored;
	}
}

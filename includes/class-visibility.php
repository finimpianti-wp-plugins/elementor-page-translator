<?php
/**
 * Languages that are not public (SPECIFICHE.md, "Lingue, URL e lingua corrente"): their pages
 * answer 404 to visitors and stay out of search results, page lists and the REST API.
 * Users who can edit pages see them, to prepare a language before publishing it.
 * Switcher, hreflang and sitemaps already skip them (Switcher, Seo).
 *
 * @package EPT
 */

namespace EPT;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Visibility of languages.
 */
final class Visibility {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp', array( self::class, 'guard' ), 0 ); // Before Current_Language::detect().
		add_filter( 'get_pages', array( self::class, 'filter_pages' ) );
		add_filter( 'rest_page_query', array( self::class, 'rest_query' ) );
	}

	/**
	 * Codes of the configured languages the current visitor may not see.
	 *
	 * @return list<string>
	 */
	public static function hidden_languages(): array {
		return array_values( array_filter( array_map( 'strval', array_keys( Languages::all() ) ), static fn( string $code ): bool => ! Languages::is_visible( $code ) ) );
	}

	/**
	 * A page of a hidden language requested by a visitor: 404.
	 */
	public static function guard(): void {
		global $wp_query;
		if ( is_admin() || ! $wp_query instanceof WP_Query || ! is_singular() || Editor_Request::active() ) {
			return;
		}
		$id   = (int) get_queried_object_id();
		$lang = (string) get_post_meta( $id, Translations::META_LANG, true );
		if ( '' === $lang || ! in_array( $lang, self::hidden_languages(), true ) ) {
			return;
		}
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Page lists (wp_list_pages, Pages widget): no pages of hidden languages.
	 *
	 * @param array<int,\WP_Post> $pages Pages.
	 * @return array<int,\WP_Post>
	 */
	public static function filter_pages( $pages ) {
		if ( is_admin() || ! is_array( $pages ) ) {
			return $pages;
		}
		$hidden = self::hidden_languages();
		if ( ! $hidden ) {
			return $pages;
		}
		return array_values(
			array_filter(
				$pages,
				static fn( $page ): bool => ! is_object( $page ) || ! in_array( (string) get_post_meta( (int) $page->ID, Translations::META_LANG, true ), $hidden, true )
			)
		);
	}

	/**
	 * REST API page collections: no pages of hidden languages.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>
	 */
	public static function rest_query( $args ) {
		$hidden = self::hidden_languages();
		if ( ! $hidden || ! is_array( $args ) ) {
			return $args;
		}
		$meta               = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta[]             = array(
			'relation' => 'OR',
			array(
				'key'     => Translations::META_LANG,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Translations::META_LANG,
				'value'   => $hidden,
				'compare' => 'NOT IN',
			),
		);
		$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}
}

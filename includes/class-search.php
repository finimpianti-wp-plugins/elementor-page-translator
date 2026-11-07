<?php
/**
 * Internal search per language (SPECIFICHE.md, "Ricerca interna").
 *
 * - Search forms get a hidden field "lang" with the current language: WordPress forms
 *   (get_search_form), the UAE Search widget (hfe-search-button, verified on 2.9.6: its own
 *   <form>, no hook) and, as a fallback, any other form with a field "s" (small script).
 * - Results: pages of the requested language (pages without language when there is no
 *   parameter); other post types are not filtered. Pages of hidden languages and the
 *   404 pages never appear.
 * - The search results page is shown in the requested language (Current_Language).
 *
 * @package EPT
 */

namespace EPT;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Search.
 */
final class Search {

	public const PARAM = 'lang';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'get_search_form', array( self::class, 'search_form' ), 20 );
		add_filter( 'elementor/widget/render_content', array( self::class, 'uae_form' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'fallback_script' ) );
		add_filter( 'posts_clauses', array( self::class, 'clauses' ), 10, 2 );
	}

	/**
	 * Language requested by the search form ('' = default; unknown or hidden ones ignored).
	 */
	public static function requested_language(): string {
		$lang = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search parameter.
		if ( '' === $lang || Languages::default_code() === $lang || null === Languages::get( $lang ) || ! Languages::is_visible( $lang ) ) {
			return '';
		}
		return $lang;
	}

	/**
	 * Hidden field of the current language ('' in the default language).
	 */
	public static function hidden_field(): string {
		$lang = Current_Language::code();
		if ( Languages::default_code() === $lang ) {
			return '';
		}
		return '<input type="hidden" name="' . esc_attr( self::PARAM ) . '" value="' . esc_attr( $lang ) . '">';
	}

	/**
	 * WordPress search forms.
	 *
	 * @param string $form Form HTML.
	 * @return string
	 */
	public static function search_form( $form ) {
		return self::add_field( (string) $form );
	}

	/**
	 * UAE Search widget.
	 *
	 * @param string $content Widget HTML.
	 * @param mixed  $widget  Widget.
	 * @return string
	 */
	public static function uae_form( $content, $widget ) {
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'hfe-search-button' !== $widget->get_name() ) {
			return $content;
		}
		return self::add_field( (string) $content );
	}

	/**
	 * Insert the hidden field after the opening tag of each form with a field "s".
	 *
	 * @param string $html HTML.
	 */
	private static function add_field( string $html ): string {
		$field = self::hidden_field();
		if ( '' === $field || ! str_contains( $html, 'name="s"' ) || str_contains( $html, 'name="' . self::PARAM . '"' ) ) {
			return $html;
		}
		return (string) preg_replace( '#<form\b[^>]*>#i', '$0' . $field, $html );
	}

	/**
	 * Fallback for search forms printed by other plugins.
	 */
	public static function fallback_script(): void {
		$lang = Current_Language::code();
		if ( Languages::default_code() === $lang ) {
			return;
		}
		wp_register_script( 'ept-search', '', array(), EPT_VERSION, true );
		wp_enqueue_script( 'ept-search' );
		wp_add_inline_script(
			'ept-search',
			'(function(l,p){document.querySelectorAll("form").forEach(function(f){if(f.querySelector("[name=s]")&&!f.querySelector("[name="+p+"]")){var i=document.createElement("input");i.type="hidden";i.name=p;i.value=l;f.appendChild(i);}});})(' . wp_json_encode( $lang ) . ',' . wp_json_encode( self::PARAM ) . ');'
		);
	}

	/**
	 * Filter the front-end search query by language.
	 *
	 * @param array<string,string> $clauses Clauses.
	 * @param WP_Query             $query   Query.
	 * @return array<string,string>
	 */
	public static function clauses( $clauses, $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || ! $query->is_search() || ! Languages::all() ) {
			return $clauses;
		}
		global $wpdb;
		$lang             = self::requested_language();
		$excluded         = Not_Found::page_ids();
		$alias            = 'ept_search_lang';
		$clauses['join'] .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ( {$alias}.post_id = {$wpdb->posts}.ID AND {$alias}.meta_key = %s )", Translations::META_LANG ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$match            = '' === $lang
			? "{$alias}.meta_id IS NULL"
			: $wpdb->prepare( "{$alias}.meta_value = %s", $lang ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$where            = " AND ( {$wpdb->posts}.post_type <> 'page' OR ( {$match} ) )";
		if ( $excluded ) {
			$where .= " AND {$wpdb->posts}.ID NOT IN (" . implode( ',', array_map( 'intval', $excluded ) ) . ')';
		}
		$clauses['where'] .= $where;
		return $clauses;
	}
}

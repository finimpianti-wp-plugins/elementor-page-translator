<?php
/**
 * Page 404 per language (SPECIFICHE.md, "Pagina 404").
 *
 * The language of a 404 comes from the first URL segment (Current_Language). With the
 * optional setting "Pagina 404" (an Italian Elementor page, translated like the others),
 * the content of its version in the language of the URL replaces the 404 of the theme,
 * keeping the HTTP status 404 and "noindex". Without the setting, or when that language has
 * no published version, the 404 of the theme is used.
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Not found.
 */
final class Not_Found {

	public const SETTING = 'not_found_page';

	/**
	 * Page shown in place of the 404 for this request (0 = none).
	 *
	 * @var int
	 */
	private static int $active = 0;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( self::class, 'replace' ), 99 );
		add_filter( 'wp_robots', array( self::class, 'robots' ), 99 );
		add_filter( 'wpseo_robots', array( self::class, 'plugin_robots' ), 99 );
		add_filter( 'rank_math/frontend/robots', array( self::class, 'plugin_robots_array' ), 99 );
		add_filter( 'wpseo_canonical', array( self::class, 'no_canonical' ), 99 );
		add_filter( 'rank_math/frontend/canonical', array( self::class, 'no_canonical' ), 99 );
	}

	/**
	 * Whether the current request shows the 404 page.
	 */
	public static function is_active(): bool {
		return self::$active > 0;
	}

	/**
	 * The Italian 404 page (0 = not set).
	 */
	public static function source_id(): int {
		$id   = (int) Settings::get( self::SETTING );
		$post = $id ? get_post( $id ) : null;
		return $post instanceof WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ? $id : 0;
	}

	/**
	 * The 404 page and its translations (excluded from search and sitemaps).
	 *
	 * @return list<int>
	 */
	public static function page_ids(): array {
		$source = self::source_id();
		return $source ? array_values( array_merge( array( $source ), array_map( 'intval', Translations::map( $source ) ) ) ) : array();
	}

	/**
	 * Published page to show for a language (0 = none: the theme 404 is used).
	 *
	 * @param string $lang Language.
	 */
	public static function page_for( string $lang ): int {
		$source = self::source_id();
		if ( ! $source ) {
			return 0;
		}
		$id = Languages::default_code() === $lang ? $source : (int) Translations::get_id( $source, $lang );
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/**
	 * On a 404, show the 404 page of the language with its normal page template.
	 */
	public static function replace(): void {
		global $wp_query, $post;
		if ( ! is_404() || ! $wp_query instanceof WP_Query ) {
			return;
		}
		$id = self::page_for( Current_Language::code() );
		if ( ! $id ) {
			return;
		}
		$page = get_post( $id );
		if ( ! $page instanceof WP_Post ) {
			return;
		}
		self::$active                = $id;
		$wp_query->posts             = array( $page );
		$wp_query->post              = $page;
		$wp_query->post_count        = 1;
		$wp_query->found_posts       = 1;
		$wp_query->max_num_pages     = 1;
		$wp_query->current_post      = -1;
		$wp_query->is_404            = false;
		$wp_query->is_page           = true;
		$wp_query->is_singular       = true;
		$wp_query->queried_object    = $page;
		$wp_query->queried_object_id = $id;
		$post                        = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the 404 page becomes the queried page.
		setup_postdata( $page );
		// The 404 page must not advertise its own address.
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Core robots: noindex on the 404 page.
	 *
	 * @param array<string,mixed> $robots Directives.
	 * @return array<string,mixed>
	 */
	public static function robots( $robots ) {
		if ( self::is_active() ) {
			$robots            = is_array( $robots ) ? $robots : array();
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}

	/**
	 * Yoast robots string.
	 *
	 * @param string $robots Robots.
	 * @return string
	 */
	public static function plugin_robots( $robots ) {
		return self::is_active() ? 'noindex, follow' : $robots;
	}

	/**
	 * Rank Math robots array.
	 *
	 * @param array<string,string> $robots Robots.
	 * @return array<string,string>
	 */
	public static function plugin_robots_array( $robots ) {
		return self::is_active() ? array(
			'index'  => 'noindex',
			'follow' => 'follow',
		) : $robots;
	}

	/**
	 * No canonical on the 404 page.
	 *
	 * @param mixed $canonical Canonical.
	 * @return mixed
	 */
	public static function no_canonical( $canonical ) {
		return self::is_active() ? false : $canonical;
	}

	/**
	 * Reset (tests).
	 */
	public static function reset(): void {
		self::$active = 0;
	}
}

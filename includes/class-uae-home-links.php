<?php
/**
 * UAE 2.9.6 widgets linking to the home page, on pages in another language.
 *
 * Verified on the installed plugin: the breadcrumbs (first crumb), the site logo
 * (link source "default") and the site title link to home_url() / get_home_url().
 * While one of these widgets renders on a page in another language, home_url('/')
 * points to the home of that language. In the breadcrumbs, the next crumbs are the page
 * ancestors (the first one is the root page of the language): a crumb repeating the
 * previous link is removed.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * UAE breadcrumbs.
 */
final class Uae_Home_Links {

	public const WIDGET = 'hfe-breadcrumbs-widget';

	/**
	 * Widgets whose home link follows the language.
	 */
	public const WIDGETS = array( 'hfe-breadcrumbs-widget', 'site-logo', 'hfe-site-title' );

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'elementor/frontend/widget/before_render', array( self::class, 'before' ) );
		add_action( 'elementor/frontend/widget/after_render', array( self::class, 'after' ) );
		add_filter( 'elementor/widget/render_content', array( self::class, 'dedupe' ), 10, 2 );
		add_filter( 'elementor/element/is_dynamic_content', array( self::class, 'dynamic' ), 10, 3 );
	}

	/**
	 * Widgets whose output depends on the language of the page (home link, site name and
	 * tagline, search form field, menus with the language switcher) are not stored in Elementor's element cache: a header shared
	 * by several languages would otherwise show the cached output of the first language.
	 *
	 * @param bool  $dynamic  Dynamic.
	 * @param array $raw_data Element data.
	 * @param mixed $element  Element.
	 * @return bool
	 */
	public static function dynamic( $dynamic, $raw_data = array(), $element = null ) {
		unset( $raw_data );
		if ( ! $dynamic && Languages::all() && is_object( $element ) && method_exists( $element, 'get_name' )
			&& in_array( $element->get_name(), array_merge( self::WIDGETS, array( 'hfe-site-tagline', 'hfe-search-button', 'navigation-menu', 'nav-menu' ) ), true ) ) {
			return true;
		}
		return (bool) $dynamic;
	}

	/**
	 * Whether the widget is the UAE breadcrumbs on a page not in the default language.
	 *
	 * @param mixed $widget Widget.
	 */
	private static function applies( $widget, array $names = self::WIDGETS ): bool {
		return is_object( $widget ) && method_exists( $widget, 'get_name' ) && in_array( $widget->get_name(), $names, true )
			&& Languages::default_code() !== Current_Language::code();
	}

	/**
	 * Before rendering: home_url('/') = home of the current language.
	 *
	 * @param mixed $widget Widget.
	 */
	public static function before( $widget ): void {
		if ( self::applies( $widget ) ) {
			add_filter( 'home_url', array( self::class, 'home_url' ), 10, 2 );
		}
	}

	/**
	 * After rendering: remove the filter.
	 *
	 * @param mixed $widget Widget.
	 */
	public static function after( $widget ): void {
		unset( $widget );
		remove_filter( 'home_url', array( self::class, 'home_url' ), 10 );
	}

	/**
	 * Filter home_url() for the bare home only.
	 *
	 * @param string $url  URL.
	 * @param string $path Path.
	 * @return string
	 */
	public static function home_url( $url, $path ) {
		if ( '' !== trim( (string) $path, '/' ) ) {
			return $url;
		}
		remove_filter( 'home_url', array( self::class, 'home_url' ), 10 ); // Avoid recursion via get_permalink().
		$home = Switcher::home_url( Current_Language::code() );
		add_filter( 'home_url', array( self::class, 'home_url' ), 10, 2 );
		return '' !== $home ? $home : $url;
	}

	/**
	 * Remove a crumb that repeats the link of the previous one (with its separator).
	 *
	 * @param string $content Widget HTML.
	 * @param mixed  $widget  Widget.
	 * @return string
	 */
	public static function dedupe( $content, $widget ) {
		if ( ! self::applies( $widget, array( self::WIDGET ) ) || ! preg_match_all( '#<li class="hfe-breadcrumbs-(item|separator)[^"]*">.*?</li>#s', (string) $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}
		$parts   = array();
		$last    = null;
		$pending = null;
		$dropped = false;
		foreach ( $m[0] as $i => $match ) {
			$html = $match[0];
			if ( 'separator' === $m[1][ $i ][0] ) {
				$pending = $html;
				continue;
			}
			$href = preg_match( '#<a href="([^"]*)"#', $html, $h ) ? untrailingslashit( html_entity_decode( $h[1] ) ) : null;
			if ( null !== $href && $href === $last ) {
				$pending = null;
				$dropped = true;
				continue;
			}
			if ( null !== $pending ) {
				$parts[] = $pending;
				$pending = null;
			}
			$parts[] = $html;
			$last    = $href;
		}
		if ( ! $dropped ) {
			return $content;
		}
		$start = $m[0][0][1];
		$end   = $m[0][ count( $m[0] ) - 1 ][1] + strlen( $m[0][ count( $m[0] ) - 1 ][0] );
		return substr( (string) $content, 0, $start ) . implode( '', $parts ) . substr( (string) $content, $end );
	}
}

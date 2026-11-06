<?php
/**
 * UAE 2.9.6 breadcrumbs widget (hfe-breadcrumbs-widget) on translated pages.
 *
 * Verified on the installed plugin: the first crumb links to home_url() and the next
 * crumbs are the page ancestors (on a translated page the first ancestor is the root
 * page of the language, i.e. its home). While this widget renders on a page in another
 * language, home_url('/') points to the home of that language, and a crumb repeating
 * the previous link is removed.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * UAE breadcrumbs.
 */
final class Uae_Breadcrumbs {

	public const WIDGET = 'hfe-breadcrumbs-widget';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'elementor/frontend/widget/before_render', array( self::class, 'before' ) );
		add_action( 'elementor/frontend/widget/after_render', array( self::class, 'after' ) );
		add_filter( 'elementor/widget/render_content', array( self::class, 'dedupe' ), 10, 2 );
	}

	/**
	 * Whether the widget is the UAE breadcrumbs on a page not in the default language.
	 *
	 * @param mixed $widget Widget.
	 */
	private static function applies( $widget ): bool {
		return is_object( $widget ) && method_exists( $widget, 'get_name' ) && self::WIDGET === $widget->get_name()
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
		if ( ! self::applies( $widget ) || ! preg_match_all( '#<li class="hfe-breadcrumbs-(item|separator)[^"]*">.*?</li>#s', (string) $content, $m, PREG_OFFSET_CAPTURE ) ) {
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

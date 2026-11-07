<?php
/**
 * Language switcher shortcode [ept_language_switcher] (SPECIFICHE.md, "Selettore lingua").
 *
 * Attributes: style="list|dropdown", show="code,name,flag" (any combination),
 * hide_current="0|1", missing="home|hide", separator="/".
 *
 * Each item leads to the version of the current page in that language; when it does
 * not exist or is not published, to the home of the language (or the item is hidden).
 * Languages that are not public are shown only to users who can edit pages.
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Switcher.
 */
final class Switcher {

	/**
	 * Default language codes without region => flag file.
	 */
	private const LANG_FLAGS = array(
		'en' => 'gb',
		'it' => 'it',
		'de' => 'de',
		'fr' => 'fr',
		'es' => 'es',
		'pt' => 'pt',
		'nl' => 'nl',
		'pl' => 'pl',
		'el' => 'gr',
		'ro' => 'ro',
		'ga' => 'ie',
	);

	/**
	 * Instance counter (unique IDs for dropdowns).
	 *
	 * @var int
	 */
	private static int $count = 0;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_shortcode( 'ept_language_switcher', array( self::class, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_assets' ) );
	}

	/**
	 * Register CSS/JS (enqueued only when the shortcode is rendered).
	 */
	public static function register_assets(): void {
		if ( wp_style_is( 'ept-switcher', 'registered' ) ) {
			return;
		}
		wp_register_style( 'ept-switcher', EPT_URL . 'assets/switcher.css', array(), EPT_VERSION );
		wp_register_script( 'ept-switcher', EPT_URL . 'assets/switcher.js', array(), EPT_VERSION, true );
	}

	/**
	 * Languages shown in the switcher: default + public ones (+ active non-public for editors).
	 *
	 * @return array<string,array<string,mixed>> code => language (with "locale", "name", "public")
	 */
	public static function languages(): array {
		$default         = Languages::default_language();
		$out             = array(
			$default['code'] => array(
				'code'   => $default['code'],
				'locale' => $default['locale'],
				'name'   => $default['name'],
				'public' => true,
			),
		);
		$can_see_private = current_user_can( 'edit_pages' );
		foreach ( Languages::active() as $code => $lang ) {
			if ( ! empty( $lang['public'] ) || $can_see_private ) {
				$out[ (string) $code ] = array(
					'code'   => (string) $code,
					'locale' => (string) $lang['locale'],
					'name'   => (string) $lang['name'],
					'public' => ! empty( $lang['public'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Italian page corresponding to the current request, or 0.
	 */
	public static function current_source(): int {
		if ( ! is_singular( 'page' ) ) {
			return 0;
		}
		$id = (int) get_queried_object_id();
		if ( Translations::is_source( $id ) ) {
			return $id;
		}
		$source = Translations::source_of( $id );
		if ( ! $source && null !== Root_Pages::root_language( $id ) && 'page' === get_option( 'show_on_front' ) ) {
			$source = (int) get_option( 'page_on_front' ); // Root page before the home is translated.
		}
		return $source;
	}

	/**
	 * Home URL of a language, or '' when it has no published home.
	 *
	 * @param string $code Language code.
	 */
	public static function home_url( string $code ): string {
		if ( Languages::default_code() === $code ) {
			return home_url( '/' );
		}
		$root = (int) ( Languages::get( $code )['root_page_id'] ?? 0 );
		if ( ! $root ) {
			return '';
		}
		$status = get_post_status( $root );
		if ( 'publish' === $status || ( $status && current_user_can( 'edit_page', $root ) ) ) {
			return (string) get_permalink( $root );
		}
		return '';
	}

	/**
	 * URL of the version of a page in a language, or '' when missing / not visible.
	 *
	 * @param int    $source Italian page ID.
	 * @param string $code   Language code.
	 */
	public static function page_url( int $source, string $code ): string {
		if ( ! $source ) {
			return '';
		}
		$id = Languages::default_code() === $code ? $source : Translations::get_id( $source, $code );
		if ( ! $id && Languages::default_code() !== $code && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $source ) {
			return self::home_url( $code );
		}
		if ( ! $id ) {
			return '';
		}
		$status = get_post_status( $id );
		if ( 'publish' === $status || ( $status && 'trash' !== $status && current_user_can( 'edit_page', $id ) ) ) {
			return (string) get_permalink( $id );
		}
		return '';
	}

	/**
	 * Items of the switcher for the current request.
	 *
	 * @param string $missing home|hide.
	 * @return list<array{code:string,name:string,locale:string,url:string,current:bool,fallback:bool,public:bool}>
	 */
	public static function items( string $missing = 'home' ): array {
		$current = Current_Language::code();
		$source  = self::current_source();
		$items   = array();
		foreach ( self::languages() as $code => $lang ) {
			$url      = self::page_url( $source, $code );
			$fallback = false;
			if ( '' === $url ) {
				if ( 'hide' === $missing && $code !== $current ) {
					continue;
				}
				$url      = self::home_url( $code );
				$fallback = true;
			}
			if ( '' === $url ) {
				continue; // No home in that language yet.
			}
			$items[] = array(
				'code'     => $code,
				'name'     => $lang['name'],
				'locale'   => $lang['locale'],
				'url'      => $url,
				'current'  => $code === $current,
				'fallback' => $fallback,
				'public'   => $lang['public'],
			);
		}
		return $items;
	}

	/**
	 * Flag file URL for a locale, or '' when no flag is bundled.
	 *
	 * @param string $locale Locale.
	 */
	public static function flag_url( string $locale ): string {
		$parts   = explode( '_', $locale );
		$country = isset( $parts[1] ) && preg_match( '/^[A-Z]{2}$/', $parts[1] ) ? strtolower( $parts[1] ) : ( self::LANG_FLAGS[ strtolower( $parts[0] ) ] ?? '' );
		if ( '' === $country || ! is_readable( EPT_DIR . 'assets/flags/' . $country . '.svg' ) ) {
			return '';
		}
		return EPT_URL . 'assets/flags/' . $country . '.svg';
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string,string>|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts    = shortcode_atts(
			array(
				'style'        => 'list',
				'show'         => 'code',
				'hide_current' => '0',
				'missing'      => 'home',
				'separator'    => '',
			),
			is_array( $atts ) ? $atts : array(),
			'ept_language_switcher'
		);
		$style   = 'dropdown' === $atts['style'] ? 'dropdown' : 'list';
		$show    = array_values( array_intersect( array_map( 'trim', explode( ',', strtolower( (string) $atts['show'] ) ) ), array( 'code', 'name', 'flag' ) ) );
		$show    = $show ? $show : array( 'code' );
		$missing = 'hide' === $atts['missing'] ? 'hide' : 'home';
		$items   = self::items( $missing );
		if ( '1' === (string) $atts['hide_current'] ) {
			$items = array_values( array_filter( $items, static fn( array $i ): bool => ! $i['current'] ) );
		}
		if ( ! $items ) {
			return '';
		}
		self::register_assets(); // The shortcode may render before wp_enqueue_scripts.
		wp_enqueue_style( 'ept-switcher' );

		return 'dropdown' === $style ? self::render_dropdown( $items, $show ) : self::render_list( $items, $show, (string) $atts['separator'] );
	}

	/**
	 * Label of an item.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param list<string>        $show Parts to show.
	 */
	private static function label( array $item, array $show ): string {
		$html = '';
		foreach ( $show as $part ) {
			if ( 'flag' === $part ) {
				$flag = self::flag_url( (string) $item['locale'] );
				if ( '' !== $flag ) {
					// Decorative when a text is shown too; otherwise it carries the language name.
					$alt   = ( in_array( 'code', $show, true ) || in_array( 'name', $show, true ) ) ? '' : (string) $item['name'];
					$html .= '<img class="ept-switcher__flag" src="' . esc_url( $flag ) . '" alt="' . esc_attr( $alt ) . '" width="20" height="14" loading="lazy" decoding="async">';
				} elseif ( ! in_array( 'code', $show, true ) && ! in_array( 'name', $show, true ) ) {
					$html .= '<span class="ept-switcher__code">' . esc_html( strtoupper( (string) $item['code'] ) ) . '</span>';
				}
			} elseif ( 'code' === $part ) {
				$html .= '<span class="ept-switcher__code">' . esc_html( strtoupper( (string) $item['code'] ) ) . '</span>';
			} else {
				$html .= '<span class="ept-switcher__name">' . esc_html( (string) $item['name'] ) . '</span>';
			}
		}
		if ( ! $item['public'] ) {
			$html .= '<span class="ept-switcher__private" title="' . esc_attr__( 'Lingua non ancora pubblica: la vedono solo gli utenti che possono modificare il sito.', 'ept' ) . '">' . esc_html__( '(lingua non pubblica)', 'ept' ) . '</span>';
		}
		return $html;
	}

	/**
	 * One link.
	 *
	 * @param array<string,mixed> $item Item.
	 * @param list<string>        $show Parts to show.
	 */
	private static function link( array $item, array $show ): string {
		$classes = 'ept-switcher__link';
		$attrs   = sprintf(
			' href="%1$s" hreflang="%2$s" lang="%2$s"',
			esc_url( (string) $item['url'] ),
			esc_attr( Current_Language::bcp47( (string) $item['locale'] ) )
		);
		if ( $item['current'] ) {
			$attrs .= ' aria-current="true"';
		}
		if ( ! in_array( 'name', $show, true ) ) {
			$attrs .= ' title="' . esc_attr( (string) $item['name'] ) . '"';
			if ( ! in_array( 'flag', $show, true ) || '' === self::flag_url( (string) $item['locale'] ) ) {
				$attrs .= ' aria-label="' . esc_attr( (string) $item['name'] ) . '"';
			}
		}
		return '<a class="' . esc_attr( $classes ) . '"' . $attrs . '>' . self::label( $item, $show ) . '</a>';
	}

	/**
	 * Item classes.
	 *
	 * @param array<string,mixed> $item Item.
	 */
	private static function item_class( array $item ): string {
		return 'ept-switcher__item ept-switcher__item--' . sanitize_html_class( (string) $item['code'] )
			. ( $item['current'] ? ' ept-switcher__item--current' : '' )
			. ( $item['fallback'] ? ' ept-switcher__item--fallback' : '' );
	}

	/**
	 * Plain list.
	 *
	 * @param list<array<string,mixed>> $items     Items.
	 * @param list<string>              $show      Parts.
	 * @param string                    $separator Separator text.
	 */
	private static function render_list( array $items, array $show, string $separator ): string {
		$html = '<nav class="ept-switcher ept-switcher--list" aria-label="' . esc_attr__( 'Lingua', 'ept' ) . '"><ul class="ept-switcher__list">';
		foreach ( $items as $i => $item ) {
			if ( $i > 0 && '' !== $separator ) {
				$html .= '<li class="ept-switcher__separator" aria-hidden="true">' . esc_html( $separator ) . '</li>';
			}
			$html .= '<li class="' . esc_attr( self::item_class( $item ) ) . '">' . self::link( $item, $show ) . '</li>';
		}
		return $html . '</ul></nav>';
	}

	/**
	 * Dropdown: a disclosure button and a list of links (keyboard and touch, no library).
	 *
	 * @param list<array<string,mixed>> $items Items.
	 * @param list<string>              $show  Parts.
	 */
	private static function render_dropdown( array $items, array $show ): string {
		wp_enqueue_script( 'ept-switcher' );
		$id      = 'ept-switcher-' . ( ++self::$count );
		$current = null;
		foreach ( $items as $item ) {
			if ( $item['current'] ) {
				$current = $item;
			}
		}
		$current = $current ?? array(
			'code'     => Current_Language::code(),
			'name'     => (string) ( Languages::get( Current_Language::code() )['name'] ?? Languages::default_language()['name'] ),
			'locale'   => (string) ( Languages::get( Current_Language::code() )['locale'] ?? Languages::default_language()['locale'] ),
			'public'   => true,
			'current'  => true,
			'fallback' => false,
			'url'      => '',
		);
		$html    = '<div class="ept-switcher ept-switcher--dropdown" data-ept-switcher>';
		$html   .= '<button type="button" class="ept-switcher__toggle" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '">';
		/* translators: %s: current language name. */
		$html .= '<span class="screen-reader-text ept-switcher__sr">' . esc_html( sprintf( __( 'Lingua: %s. Cambia lingua', 'ept' ), $current['name'] ) ) . '</span>';
		$html .= '<span aria-hidden="true" class="ept-switcher__current">' . self::label( $current, $show ) . '</span>';
		$html .= '<span class="ept-switcher__arrow" aria-hidden="true"></span></button>';
		$html .= '<ul class="ept-switcher__list" id="' . esc_attr( $id ) . '" hidden>';
		foreach ( $items as $item ) {
			$html .= '<li class="' . esc_attr( self::item_class( $item ) ) . '">' . self::link( $item, $show ) . '</li>';
		}
		return $html . '</ul></div>';
	}
}

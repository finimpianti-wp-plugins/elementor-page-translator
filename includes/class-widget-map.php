<?php
/**
 * Explicit map of translatable fields for known classic widgets.
 *
 * Built from the specification and verified on the installed versions
 * (Elementor 4.3.6, UAE 2.9.6) with the census of 2026-11-05 (docs/verifiche.md).
 *
 * Field syntax: "title" for a plain setting, "tabs[].tab_title" for a repeater item field.
 * Formats: text (single line or short), html (rich text, tags preserved), code (raw HTML widget).
 *
 * Widgets mapped to an empty list have no visible text (or only references/links):
 * the fallback rule is not applied to them. Unknown widgets use the fallback rule.
 * Atomic elements (Elementor 4) are handled by value type, not by this map.
 *
 * Pure class: the WordPress layer passes the result of the "ept_translatable_fields" filter.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Widget map.
 */
final class Widget_Map {

	public const TEXT = 'text';
	public const HTML = 'html';
	public const CODE = 'code';

	/**
	 * Default map: widgetType => [ field path => format ].
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function defaults(): array {
		return array(
			// Elementor (free).
			'heading'                => array( 'title' => self::HTML ),
			'text-editor'            => array( 'editor' => self::HTML ),
			'button'                 => array( 'text' => self::TEXT ),
			'icon-box'               => array(
				'title_text'       => self::TEXT,
				'description_text' => self::HTML,
			),
			'image-box'              => array(
				'title_text'       => self::TEXT,
				'description_text' => self::HTML,
			),
			'icon-list'              => array( 'icon_list[].text' => self::TEXT ),
			'accordion'              => array(
				'tabs[].tab_title'   => self::TEXT,
				'tabs[].tab_content' => self::HTML,
			),
			'toggle'                 => array(
				'tabs[].tab_title'   => self::TEXT,
				'tabs[].tab_content' => self::HTML,
			),
			'tabs'                   => array(
				'tabs[].tab_title'   => self::TEXT,
				'tabs[].tab_content' => self::HTML,
			),
			'nested-accordion'       => array( 'items[].item_title' => self::TEXT ),
			'nested-tabs'            => array( 'tabs[].tab_title' => self::TEXT ),
			'testimonial'            => array(
				'testimonial_content' => self::HTML,
				'testimonial_name'    => self::TEXT,
				'testimonial_job'     => self::TEXT,
			),
			'alert'                  => array(
				'alert_title'       => self::TEXT,
				'alert_description' => self::HTML,
			),
			'counter'                => array(
				'title'  => self::TEXT,
				'prefix' => self::TEXT,
				'suffix' => self::TEXT,
			),
			'progress'               => array(
				'title'      => self::TEXT,
				'inner_text' => self::TEXT,
			),
			'image'                  => array( 'caption' => self::TEXT ),
			'divider'                => array( 'text' => self::TEXT ),
			'google_maps'            => array( 'address' => self::TEXT ),
			'html'                   => array( 'html' => self::CODE ),
			'star-rating'            => array( 'title' => self::TEXT ),
			'spacer'                 => array(),
			'shortcode'              => array(),
			'icon'                   => array(),
			'social-icons'           => array(),
			'video'                  => array(),
			'image-gallery'          => array(),
			'image-carousel'         => array(),
			'menu-anchor'            => array(),
			'wp-widget-nav_menu'     => array( 'wp.title' => self::TEXT ),

			// UAE 2.9.6 (controls read from the installed plugin).
			'hfe-breadcrumbs-widget' => array(
				'separator_text' => self::TEXT,
				'home_text'      => self::TEXT,
				'search_text'    => self::TEXT,
				'error_text'     => self::TEXT,
			),
			'navigation-menu'        => array(),
			'site-logo'              => array( 'caption' => self::TEXT ),
			'retina'                 => array( 'caption' => self::TEXT ),
			'copyright'              => array( 'shortcode' => self::TEXT ),
			'hfe-site-title'         => array(
				'before' => self::TEXT,
				'after'  => self::TEXT,
			),
			'page-title'             => array(
				'before' => self::TEXT,
				'after'  => self::TEXT,
			),
			'hfe-site-tagline'       => array(
				'before' => self::TEXT,
				'after'  => self::TEXT,
			),
			'hfe-search-button'      => array( 'placeholder' => self::TEXT ),
			'hfe-infocard'           => array(
				'infocard_title'       => self::TEXT,
				'infocard_description' => self::HTML,
				'infocard_link_text'   => self::TEXT,
				'infocard_button_text' => self::TEXT,
			),
			'hfe-basic-posts'        => array(
				'meta_separator' => self::TEXT,
				'read_more_text' => self::TEXT,
			),
			'hfe-counter'            => array(
				'title'  => self::TEXT,
				'prefix' => self::TEXT,
				'suffix' => self::TEXT,
			),
			'hfe-cart'               => array(),
			'hfe-scroll-to-top'      => array(),
		);
	}

	/**
	 * Map in use.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $map;

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,string>>|null $map Map (defaults() when null).
	 */
	public function __construct( ?array $map = null ) {
		$this->map = $map ?? self::defaults();
	}

	/**
	 * Fields of a widget, or null when the widget is unknown (fallback rule applies).
	 *
	 * @param string $widget_type Widget type.
	 * @return array<string,string>|null
	 */
	public function fields( string $widget_type ): ?array {
		return $this->map[ $widget_type ] ?? null;
	}

	/**
	 * The whole map.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function all(): array {
		return $this->map;
	}
}

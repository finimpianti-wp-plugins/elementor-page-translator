<?php
/**
 * Extractor: walks Elementor JSON (classic widgets and Elementor 4 atomic elements)
 * and lists translatable texts, links, references (shortcodes, menus) and media.
 *
 * Pure class: no WordPress calls. Covered by PHPUnit on real fixtures.
 *
 * Stable keys: "{element id}|{setting path}", e.g. "3f9a2c1|title",
 * "3f9a2c1|tabs.a1b2c3d.tab_title" (repeater items use their "_id", or the index
 * when they have none), "3f9a2c1|editor#href0" (first href inside an HTML field).
 *
 * Each item also carries "path": the concrete location inside the element node
 * (e.g. ["settings","tabs",2,"tab_title"]), which the Rebuilder uses.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Extractor.
 */
final class Extractor {

	/**
	 * Atomic value types holding visible rich text.
	 */
	public const ATOMIC_HTML_TYPES = array( 'escaped-html', 'html', 'html-v2', 'html-v3' );

	/**
	 * HTML attributes (custom attributes of atomic elements) whose value is visible text.
	 */
	public const VISIBLE_ATTRIBUTES = array( 'title', 'alt', 'aria-label', 'aria-description', 'placeholder' );

	/**
	 * Atomic props that are never text even if their key looks like one.
	 */
	private const ATOMIC_NON_TEXT_PROPS = array( 'tag', '_cssid', 'classes', 'tab-id', 'default-active-tab', 'form-name', 'source', 'start', 'end', 'preload', 'state' );

	/**
	 * Widget map.
	 *
	 * @var Widget_Map
	 */
	private Widget_Map $map;

	/**
	 * Result being built.
	 *
	 * @var array{strings: list<array<string,mixed>>, links: list<array<string,mixed>>, refs: list<array<string,mixed>>, media: list<array<string,mixed>>}
	 */
	private array $out;

	/**
	 * Running order counter (page order).
	 *
	 * @var int
	 */
	private int $order = 0;

	/**
	 * Constructor.
	 *
	 * @param Widget_Map|null $map Widget map.
	 */
	public function __construct( ?Widget_Map $map = null ) {
		$this->map = $map ?? new Widget_Map();
	}

	/**
	 * Normalised hash of a text: whitespace (including non-breaking spaces) collapsed.
	 *
	 * @param string $text Text.
	 */
	public static function hash( string $text ): string {
		return sha1( self::normalize( $text ) );
	}

	/**
	 * Whitespace-normalised text.
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$text = str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Extract everything from a list of top-level elements.
	 *
	 * @param array<int,mixed> $elements Decoded _elementor_data.
	 * @return array{strings: list<array<string,mixed>>, links: list<array<string,mixed>>, refs: list<array<string,mixed>>, media: list<array<string,mixed>>}
	 */
	public function extract( array $elements ): array {
		$this->out   = array(
			'strings' => array(),
			'links'   => array(),
			'refs'    => array(),
			'media'   => array(),
		);
		$this->order = 0;
		foreach ( $elements as $node ) {
			if ( is_array( $node ) ) {
				$this->walk_node( $node, (string) ( $node['id'] ?? '' ) );
			}
		}
		return $this->out;
	}

	/**
	 * Visit one element and its children.
	 *
	 * @param array<string,mixed> $node  Element node.
	 * @param string              $group Top-level element ID (section in page order).
	 */
	private function walk_node( array $node, string $group ): void {
		$id       = (string) ( $node['id'] ?? '' );
		$el_type  = (string) ( $node['elType'] ?? '' );
		$widget   = 'widget' === $el_type ? (string) ( $node['widgetType'] ?? '' ) : $el_type;
		$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$ctx      = array(
			'element_id' => $id,
			'widget'     => $widget,
			'group'      => $group,
		);

		if ( $this->is_atomic( $settings ) ) {
			$this->extract_atomic( $settings, $ctx );
		} elseif ( 'widget' === $el_type ) {
			$this->extract_classic_widget( $settings, $ctx );
		}
		if ( ! $this->is_atomic( $settings ) ) {
			$this->extract_classic_links_and_media( $settings, array( 'settings' ), array(), $ctx );
		}
		$this->extract_refs( $settings, $ctx );

		foreach ( (array) ( $node['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$this->walk_node( $child, $group );
			}
		}
	}

	/**
	 * Whether the element uses typed atomic values.
	 *
	 * @param array<string,mixed> $settings Settings.
	 */
	private function is_atomic( array $settings ): bool {
		foreach ( $settings as $value ) {
			if ( is_array( $value ) && isset( $value['$$type'] ) ) {
				return true;
			}
		}
		return false;
	}

	/* --------------------------------------------------------------------
	 * Classic widgets
	 * ------------------------------------------------------------------ */

	/**
	 * Texts of a classic widget, from the map or the fallback rule.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $ctx      Context.
	 */
	private function extract_classic_widget( array $settings, array $ctx ): void {
		$dynamic = isset( $settings['__dynamic__'] ) && is_array( $settings['__dynamic__'] ) ? $settings['__dynamic__'] : array();
		$fields  = $this->map->fields( $ctx['widget'] );

		if ( null !== $fields ) {
			foreach ( $fields as $field => $format ) {
				if ( str_contains( $field, '[].' ) ) {
					list( $list, $sub ) = explode( '[].', $field, 2 );
					foreach ( $this->repeater_items( $settings[ $list ] ?? null ) as $index => $item ) {
						$item_key = $this->item_key( $item, $index );
						$this->add_classic_string( $item[ $sub ] ?? null, $list . '.' . $item_key . '.' . $sub, array( 'settings', $list, $index, $sub ), $format, 'map', false, $ctx );
					}
					continue;
				}
				if ( str_contains( $field, '.' ) ) {
					$parts = explode( '.', $field );
					$value = $settings;
					foreach ( $parts as $part ) {
						$value = is_array( $value ) ? ( $value[ $part ] ?? null ) : null;
					}
					$this->add_classic_string( $value, $field, array_merge( array( 'settings' ), $parts ), $format, 'map', isset( $dynamic[ $parts[0] ] ), $ctx );
					continue;
				}
				$this->add_classic_string( $settings[ $field ] ?? null, $field, array( 'settings', $field ), $format, 'map', isset( $dynamic[ $field ] ), $ctx );
			}
			return;
		}

		// Fallback rule for unknown widgets (custom plugins, Royal Addons, …).
		foreach ( $dynamic as $bound => $tag ) {
			if ( Text_Heuristics::is_candidate_key( (string) $bound ) ) {
				$this->add_dynamic( (string) $bound, array( 'settings', (string) $bound ), $ctx );
			}
		}
		$this->fallback_walk( $settings, array( 'settings' ), array(), $dynamic, $ctx, 0 );
	}

	/**
	 * Fallback rule: walk settings recursively (repeaters included).
	 *
	 * @param mixed               $value    Value.
	 * @param list<string|int>    $path     Concrete path.
	 * @param list<string>        $key_path Key path segments.
	 * @param array<string,mixed> $dynamic  Dynamic bindings of the widget.
	 * @param array<string,mixed> $ctx      Context.
	 * @param int                 $depth    Depth.
	 */
	private function fallback_walk( $value, array $path, array $key_path, array $dynamic, array $ctx, int $depth ): void {
		if ( $depth > 5 || ! is_array( $value ) ) {
			return;
		}
		$is_list = array_is_list( $value );
		foreach ( $value as $k => $v ) {
			$k = (string) $k;
			if ( 0 === $depth && ( '__dynamic__' === $k || '__globals__' === $k ) ) {
				continue;
			}
			if ( $is_list ) {
				if ( is_array( $v ) ) {
					$this->fallback_walk( $v, array_merge( $path, array( (int) $k ) ), array_merge( $key_path, array( $this->item_key( $v, (int) $k ) ) ), $dynamic, $ctx, $depth + 1 );
				}
				continue;
			}
			if ( is_string( $v ) ) {
				if ( 0 === $depth && isset( $dynamic[ $k ] ) ) {
					continue;
				}
				if ( Text_Heuristics::is_fallback_text( $k, $v ) ) {
					$format = Text_Heuristics::has_html( $v ) ? Widget_Map::HTML : Widget_Map::TEXT;
					$this->add_string( $v, implode( '.', array_merge( $key_path, array( $k ) ) ), array_merge( $path, array( $k ) ), $format, 'fallback', $ctx );
				}
				continue;
			}
			if ( is_array( $v ) && ! $this->is_url_control( $v ) && ! $this->is_media_control( $v ) ) {
				$this->fallback_walk( $v, array_merge( $path, array( $k ) ), array_merge( $key_path, array( $k ) ), $dynamic, $ctx, $depth + 1 );
			}
		}
	}

	/**
	 * Add a mapped classic string (or a dynamic placeholder).
	 *
	 * @param mixed               $value   Value.
	 * @param string              $key_path Key path.
	 * @param list<string|int>    $path    Concrete path.
	 * @param string              $format  Format.
	 * @param string              $source  map|fallback.
	 * @param bool                $is_dynamic Bound to a dynamic tag.
	 * @param array<string,mixed> $ctx     Context.
	 */
	private function add_classic_string( $value, string $key_path, array $path, string $format, string $source, bool $is_dynamic, array $ctx ): void {
		if ( $is_dynamic ) {
			$this->add_dynamic( $key_path, $path, $ctx );
			return;
		}
		if ( ! is_string( $value ) ) {
			return;
		}
		$this->add_string( $value, $key_path, $path, $format, $source, $ctx );
	}

	/**
	 * Repeater items (list of arrays) keyed by index.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 */
	private function repeater_items( $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}
		return array_filter( $value, 'is_array' );
	}

	/**
	 * Stable key segment of a repeater item: its "_id", else its index.
	 *
	 * @param array<string,mixed> $item  Item.
	 * @param int                 $index Index.
	 */
	private function item_key( array $item, int $index ): string {
		return isset( $item['_id'] ) && is_string( $item['_id'] ) && '' !== $item['_id'] ? $item['_id'] : (string) $index;
	}

	/**
	 * Classic URL controls and media controls anywhere in the settings.
	 *
	 * @param mixed               $value    Value.
	 * @param list<string|int>    $path     Concrete path.
	 * @param list<string>        $key_path Key path.
	 * @param array<string,mixed> $ctx      Context.
	 * @param int                 $depth    Depth.
	 */
	private function extract_classic_links_and_media( $value, array $path, array $key_path, array $ctx, int $depth = 0 ): void {
		if ( ! is_array( $value ) || $depth > 5 ) {
			return;
		}
		$dynamic = 0 === $depth && isset( $value['__dynamic__'] ) && is_array( $value['__dynamic__'] ) ? $value['__dynamic__'] : array();
		$is_list = array_is_list( $value );
		foreach ( $value as $k => $v ) {
			if ( ! is_array( $v ) || ( 0 === $depth && in_array( (string) $k, array( '__dynamic__', '__globals__' ), true ) ) ) {
				continue;
			}
			$segment = $is_list ? $this->item_key( $v, (int) $k ) : (string) $k;
			$kp      = array_merge( $key_path, array( $segment ) );
			$p       = array_merge( $path, array( $k ) );
			if ( $this->is_url_control( $v ) ) {
				if ( ! isset( $dynamic[ (string) $k ] ) && is_string( $v['url'] ) && '' !== trim( $v['url'] ) ) {
					$this->add_link( 'url', $v['url'], implode( '.', $kp ) . '.url', array_merge( $p, array( 'url' ) ), $ctx );
				}
				continue;
			}
			if ( $this->is_media_control( $v ) ) {
				if ( ! empty( $v['id'] ) ) {
					$this->out['media'][] = array(
						'attachment_id' => (int) $v['id'],
						'element_id'    => $ctx['element_id'],
						'widget'        => $ctx['widget'],
						'setting'       => implode( '.', $kp ),
					);
				}
				continue;
			}
			$this->extract_classic_links_and_media( $v, $p, $kp, $ctx, $depth + 1 );
		}
	}

	/**
	 * Elementor URL control: {url, is_external, nofollow, custom_attributes}.
	 *
	 * @param array<mixed> $v Value.
	 */
	private function is_url_control( array $v ): bool {
		return array_key_exists( 'url', $v ) && ( array_key_exists( 'is_external', $v ) || array_key_exists( 'nofollow', $v ) || array_key_exists( 'custom_attributes', $v ) );
	}

	/**
	 * Elementor media control: {url, id, …} without link flags.
	 *
	 * @param array<mixed> $v Value.
	 */
	private function is_media_control( array $v ): bool {
		return array_key_exists( 'url', $v ) && array_key_exists( 'id', $v ) && ! $this->is_url_control( $v );
	}

	/* --------------------------------------------------------------------
	 * Atomic elements (Elementor 4)
	 * ------------------------------------------------------------------ */

	/**
	 * Texts, links and media of an atomic element, by value type.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $ctx      Context.
	 */
	private function extract_atomic( array $settings, array $ctx ): void {
		foreach ( $settings as $prop => $value ) {
			$prop = (string) $prop;
			if ( ! is_array( $value ) || ! isset( $value['$$type'] ) || in_array( $prop, self::ATOMIC_NON_TEXT_PROPS, true ) ) {
				continue;
			}
			$this->atomic_value( $value, $prop, array( 'settings', $prop ), $prop, $ctx, 0 );
		}
	}

	/**
	 * Visit one typed value.
	 *
	 * @param array<string,mixed> $value    Typed value {$$type, value}.
	 * @param string              $key_path Key path.
	 * @param list<string|int>    $path     Concrete path to the typed value.
	 * @param string              $leaf     Prop name.
	 * @param array<string,mixed> $ctx      Context.
	 * @param int                 $depth    Depth.
	 */
	private function atomic_value( array $value, string $key_path, array $path, string $leaf, array $ctx, int $depth ): void {
		if ( $depth > 6 ) {
			return;
		}
		$type  = (string) $value['$$type'];
		$inner = $value['value'] ?? null;

		if ( 'dynamic' === $type ) {
			if ( 0 === $depth && ( in_array( $leaf, array( 'title', 'paragraph', 'text' ), true ) || Text_Heuristics::is_candidate_key( $leaf ) ) ) {
				$this->add_dynamic( $key_path, $path, $ctx );
			}
			return;
		}

		if ( in_array( $type, self::ATOMIC_HTML_TYPES, true ) ) {
			if ( is_string( $inner ) ) {
				$this->add_string( $inner, $key_path, array_merge( $path, array( 'value' ) ), Widget_Map::HTML, 'atomic', $ctx );
			} elseif ( is_array( $inner ) && isset( $inner['content'] ) && is_array( $inner['content'] ) ) {
				// Rich text in html-v2 / html-v3 lives in value.content (a typed string).
				$this->atomic_value( $inner['content'], $key_path, array_merge( $path, array( 'value', 'content' ) ), $leaf, $ctx, $depth + 1 );
			}
			return;
		}

		switch ( $type ) {
			case 'string':
				if ( is_string( $inner ) && ( 'content' === $leaf || Text_Heuristics::is_candidate_key( $leaf ) || 'alt' === $leaf ) ) {
					$format = 'content' === $leaf ? Widget_Map::HTML : Widget_Map::TEXT;
					$this->add_string( $inner, $key_path, array_merge( $path, array( 'value' ) ), $format, 'content' === $leaf || 'alt' === $leaf ? 'atomic' : 'fallback', $ctx );
				}
				return;

			case 'link':
				if ( is_array( $inner ) && isset( $inner['destination'] ) && is_array( $inner['destination'] ) ) {
					$this->atomic_destination( $inner['destination'], $key_path . '.destination', array_merge( $path, array( 'value', 'destination' ) ), $ctx );
				}
				return;

			case 'image':
				if ( is_array( $inner ) && isset( $inner['src'] ) && is_array( $inner['src'] ) ) {
					$this->atomic_value( $inner['src'], $key_path . '.src', array_merge( $path, array( 'value', 'src' ) ), 'src', $ctx, $depth + 1 );
				}
				return;

			case 'image-src':
				if ( is_array( $inner ) ) {
					$id = $inner['id']['value'] ?? null;
					if ( is_numeric( $id ) && (int) $id > 0 ) {
						$this->out['media'][] = array(
							'attachment_id' => (int) $id,
							'element_id'    => $ctx['element_id'],
							'widget'        => $ctx['widget'],
							'setting'       => $key_path,
						);
					}
					if ( isset( $inner['alt'] ) && is_array( $inner['alt'] ) && isset( $inner['alt']['$$type'] ) ) {
						$this->atomic_value( $inner['alt'], $key_path . '.alt', array_merge( $path, array( 'value', 'alt' ) ), 'alt', $ctx, $depth + 1 );
					}
				}
				return;

			case 'attributes':
				foreach ( is_array( $inner ) ? $inner : array() as $i => $pair ) {
					$name = $pair['value']['key']['value'] ?? null;
					$val  = $pair['value']['value'] ?? null;
					if ( is_string( $name ) && in_array( strtolower( $name ), self::VISIBLE_ATTRIBUTES, true ) && is_array( $val ) && 'string' === ( $val['$$type'] ?? '' ) && is_string( $val['value'] ?? null ) ) {
						$this->add_string( $val['value'], $key_path . '.' . strtolower( $name ), array_merge( $path, array( 'value', $i, 'value', 'value', 'value' ) ), Widget_Map::TEXT, 'atomic', $ctx );
					}
				}
				return;
		}

		// Unknown object types: look inside for typed values.
		if ( is_array( $inner ) && ! array_is_list( $inner ) ) {
			foreach ( $inner as $k => $v ) {
				if ( is_array( $v ) && isset( $v['$$type'] ) ) {
					$this->atomic_value( $v, $key_path . '.' . $k, array_merge( $path, array( 'value', $k ) ), (string) $k, $ctx, $depth + 1 );
				}
			}
		}
	}

	/**
	 * Destination of an atomic link: URL string or query (post ID).
	 *
	 * @param array<string,mixed> $dest     Typed destination.
	 * @param string              $key_path Key path.
	 * @param list<string|int>    $path     Concrete path to the typed destination.
	 * @param array<string,mixed> $ctx      Context.
	 */
	private function atomic_destination( array $dest, string $key_path, array $path, array $ctx ): void {
		$type = $dest['$$type'] ?? '';
		if ( 'url' === $type && is_string( $dest['value'] ?? null ) && '' !== trim( $dest['value'] ) ) {
			$this->add_link( 'url', $dest['value'], $key_path, array_merge( $path, array( 'value' ) ), $ctx );
		} elseif ( 'query' === $type && is_array( $dest['value'] ?? null ) ) {
			$id = $dest['value']['id']['value'] ?? null;
			if ( is_numeric( $id ) ) {
				$link            = $this->add_link( 'query', '', $key_path, $path, $ctx );
				$link['post_id'] = (int) $id;
				$link['label']   = is_string( $dest['value']['label']['value'] ?? null ) ? $dest['value']['label']['value'] : '';
				$this->out['links'][ count( $this->out['links'] ) - 1 ] = $link;
			}
		}
	}

	/* --------------------------------------------------------------------
	 * References: shortcodes and menus
	 * ------------------------------------------------------------------ */

	/**
	 * Shortcode widget and menu references.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @param array<string,mixed> $ctx      Context.
	 */
	private function extract_refs( array $settings, array $ctx ): void {
		if ( 'shortcode' === $ctx['widget'] && is_string( $settings['shortcode'] ?? null ) && '' !== trim( $settings['shortcode'] ) ) {
			$this->add_ref( 'shortcode', $ctx['element_id'] . '|shortcode', array( 'settings', 'shortcode' ), $settings['shortcode'], $ctx );
		}
		if ( 'navigation-menu' === $ctx['widget'] && is_string( $settings['menu'] ?? null ) && '' !== $settings['menu'] ) {
			$this->add_ref( 'menu_slug', $ctx['element_id'] . '|menu', array( 'settings', 'menu' ), $settings['menu'], $ctx );
		}
		if ( 'wp-widget-nav_menu' === $ctx['widget'] && isset( $settings['wp']['nav_menu'] ) ) {
			$this->add_ref( 'menu_id', $ctx['element_id'] . '|wp.nav_menu', array( 'settings', 'wp', 'nav_menu' ), (string) $settings['wp']['nav_menu'], $ctx );
		}
	}

	/**
	 * Add a reference.
	 *
	 * @param string              $type  shortcode|shortcode_inline|menu_slug|menu_id.
	 * @param string              $key   Key.
	 * @param list<string|int>    $path  Concrete path.
	 * @param string              $value Current value.
	 * @param array<string,mixed> $ctx   Context.
	 * @param array<string,mixed> $extra Extra fields.
	 */
	private function add_ref( string $type, string $key, array $path, string $value, array $ctx, array $extra = array() ): void {
		$name                = 'shortcode' === $type || 'shortcode_inline' === $type ? self::shortcode_name( $value ) : '';
		$this->out['refs'][] = array_merge(
			array(
				'key'        => $key,
				'type'       => $type,
				'value'      => $value,
				'name'       => $name,
				'path'       => $path,
				'element_id' => $ctx['element_id'],
				'widget'     => $ctx['widget'],
				'group'      => $ctx['group'],
				'order'      => ++$this->order,
			),
			$extra
		);
	}

	/**
	 * Name of the first shortcode in a string.
	 *
	 * @param string $value Value.
	 */
	public static function shortcode_name( string $value ): string {
		return preg_match( '/\[([a-zA-Z][\w-]*)/', $value, $m ) ? $m[1] : '';
	}

	/* --------------------------------------------------------------------
	 * Items
	 * ------------------------------------------------------------------ */

	/**
	 * Add a translatable string (skipping empty and letter-less values) plus
	 * the hrefs and shortcodes it contains.
	 *
	 * @param string              $text     Text.
	 * @param string              $key_path Key path.
	 * @param list<string|int>    $path     Concrete path.
	 * @param string              $format   Format.
	 * @param string              $source   map|fallback|atomic.
	 * @param array<string,mixed> $ctx      Context.
	 */
	private function add_string( string $text, string $key_path, array $path, string $format, string $source, array $ctx ): void {
		$key = $ctx['element_id'] . '|' . $key_path;
		if ( '' !== trim( $text ) && Text_Heuristics::has_letters( $text ) ) {
			$this->out['strings'][] = array(
				'key'        => $key,
				'text'       => $text,
				'hash'       => self::hash( $text ),
				'format'     => Widget_Map::TEXT === $format && Text_Heuristics::has_html( $text ) ? Widget_Map::HTML : $format,
				'source'     => $source,
				'dynamic'    => false,
				'path'       => $path,
				'element_id' => $ctx['element_id'],
				'widget'     => $ctx['widget'],
				'group'      => $ctx['group'],
				'order'      => ++$this->order,
			);
		}

		// Links inside HTML.
		if ( preg_match_all( '/\shref\s*=\s*(["\'])(.*?)\1/i', $text, $m ) ) {
			foreach ( $m[2] as $i => $url ) {
				if ( '' === trim( $url ) ) {
					continue;
				}
				$link          = $this->add_link( 'href', html_entity_decode( $url, ENT_QUOTES ), $key_path . '#href' . $i, $path, $ctx );
				$link['index'] = $i;
				$link['raw']   = $url;
				$this->out['links'][ count( $this->out['links'] ) - 1 ] = $link;
			}
		}

		// Shortcodes inside texts (e.g. the UAE Copyright widget).
		if ( str_contains( $text, '[' ) && preg_match_all( '/\[([a-zA-Z][\w-]*)(?=[\s\]\/])[^\]]*\]/', $text, $m ) ) {
			foreach ( $m[0] as $i => $shortcode ) {
				$this->add_ref(
					'shortcode_inline',
					$key . '#sc' . $i,
					$path,
					$shortcode,
					$ctx,
					array(
						'index'      => $i,
						'string_key' => $key,
					)
				);
			}
		}
	}

	/**
	 * Add a placeholder for a dynamic value: shown, never translated.
	 *
	 * @param string              $key_path Key path.
	 * @param list<string|int>    $path     Concrete path.
	 * @param array<string,mixed> $ctx      Context.
	 */
	private function add_dynamic( string $key_path, array $path, array $ctx ): void {
		$this->out['strings'][] = array(
			'key'        => $ctx['element_id'] . '|' . $key_path,
			'text'       => '',
			'hash'       => '',
			'format'     => Widget_Map::TEXT,
			'source'     => 'dynamic',
			'dynamic'    => true,
			'path'       => $path,
			'element_id' => $ctx['element_id'],
			'widget'     => $ctx['widget'],
			'group'      => $ctx['group'],
			'order'      => ++$this->order,
		);
	}

	/**
	 * Add a link.
	 *
	 * @param string              $kind     url|query|href.
	 * @param string              $url      URL ('' for query).
	 * @param string              $key_path Key path.
	 * @param list<string|int>    $path     Concrete path (for href: path of the text field).
	 * @param array<string,mixed> $ctx      Context.
	 * @return array<string,mixed> The added link.
	 */
	private function add_link( string $kind, string $url, string $key_path, array $path, array $ctx ): array {
		$link                 = array(
			'key'        => $ctx['element_id'] . '|' . $key_path,
			'kind'       => $kind,
			'url'        => $url,
			'path'       => $path,
			'element_id' => $ctx['element_id'],
			'widget'     => $ctx['widget'],
			'group'      => $ctx['group'],
			'order'      => ++$this->order,
		);
		$this->out['links'][] = $link;
		return $link;
	}
}

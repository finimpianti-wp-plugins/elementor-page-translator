<?php
/**
 * Census collector: walks Elementor JSON documents and aggregates, per element
 * type, which settings are used and what their values look like.
 *
 * Pure class (no WordPress): unit-tested on the fixtures.
 *
 * Setting paths:
 *   title                    plain setting
 *   tabs[].tab_title         repeater item field (list of objects)
 *   link.url                 classic URL control
 *   title ($$type)           atomic typed value, recorded with its $$type
 *   link.destination         field inside an atomic object value
 *   __dynamic__.title        dynamic tag bound to "title"
 *
 * @package EPT
 */

namespace EPT\Census;

use EPT\Text_Heuristics;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Collector.
 */
final class Collector {

	private const MAX_SAMPLES   = 3;
	private const SAMPLE_LENGTH = 160;
	private const MAX_DEPTH     = 6;

	/**
	 * Keys of an element node that are part of the standard structure.
	 */
	private const STANDARD_NODE_KEYS = array( 'id', 'elType', 'widgetType', 'settings', 'elements', 'isInner' );

	/**
	 * Aggregated types.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $types = array();

	/**
	 * Documents seen.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $documents = array();

	/**
	 * Page settings keys.
	 *
	 * @var array<string,int>
	 */
	private array $page_settings = array();

	/**
	 * Shortcodes found in any string setting.
	 *
	 * @var array<string,array{count:int, examples:list<string>, documents:list<int>}>
	 */
	private array $shortcodes = array();

	/**
	 * Hosts of absolute URLs found in settings.
	 *
	 * @var array<string,int>
	 */
	private array $url_hosts = array();

	/**
	 * Document currently being walked.
	 *
	 * @var int
	 */
	private int $current_doc = 0;

	/**
	 * Add one document.
	 *
	 * @param array<string,mixed> $meta          Document info (id, post_type, title, status, …).
	 * @param array<int,mixed>    $elements      Top-level elements (_elementor_data decoded).
	 * @param array<string,mixed> $page_settings Page settings (_elementor_page_settings).
	 */
	public function add_document( array $meta, array $elements, array $page_settings = array() ): void {
		$this->current_doc = (int) ( $meta['id'] ?? count( $this->documents ) + 1 );
		$counts            = array( 'elements' => 0 );
		$this->walk_elements( $elements, $counts, 0 );
		$meta['element_count'] = $counts['elements'];
		$this->documents[]     = $meta;
		foreach ( array_keys( $page_settings ) as $key ) {
			$this->page_settings[ (string) $key ] = ( $this->page_settings[ (string) $key ] ?? 0 ) + 1;
		}
	}

	/**
	 * The aggregated report (without site/registry information).
	 *
	 * @return array<string,mixed>
	 */
	public function report(): array {
		$types = $this->types;
		ksort( $types );
		foreach ( $types as &$type ) {
			ksort( $type['settings'] );
			$type['documents']  = array_values( array_unique( $type['documents'] ) );
			$type['extra_keys'] = $type['extra_keys'] ? $type['extra_keys'] : new \stdClass();
			foreach ( $type['settings'] as &$setting ) {
				arsort( $setting['kinds'] );
			}
			unset( $setting );
			$type['settings'] = $type['settings'] ? $type['settings'] : new \stdClass();
		}
		unset( $type );
		ksort( $this->page_settings );
		ksort( $this->shortcodes );
		arsort( $this->url_hosts );
		return array(
			'documents'     => $this->documents,
			'types'         => $types,
			'page_settings' => $this->page_settings ? $this->page_settings : new \stdClass(),
			'shortcodes'    => $this->shortcodes ? $this->shortcodes : new \stdClass(),
			'url_hosts'     => $this->url_hosts ? $this->url_hosts : new \stdClass(),
		);
	}

	/**
	 * Walk a list of element nodes.
	 *
	 * @param array<int,mixed> $elements Nodes.
	 * @param array<string,int> $counts  Counters (by reference).
	 * @param int              $depth    Nesting depth.
	 */
	private function walk_elements( array $elements, array &$counts, int $depth ): void {
		foreach ( $elements as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			++$counts['elements'];
			$el_type = (string) ( $node['elType'] ?? '?' );
			$key     = 'widget' === $el_type ? 'widget:' . (string) ( $node['widgetType'] ?? '?' ) : 'element:' . $el_type;

			if ( ! isset( $this->types[ $key ] ) ) {
				$this->types[ $key ] = array(
					'elType'     => $el_type,
					'widgetType' => 'widget' === $el_type ? (string) ( $node['widgetType'] ?? '' ) : null,
					'count'      => 0,
					'documents'  => array(),
					'max_depth'  => 0,
					'extra_keys' => array(),
					'settings'   => array(),
				);
			}
			$type                = &$this->types[ $key ];
			$type['count']      += 1;
			$type['documents'][] = $this->current_doc;
			$type['max_depth']   = max( $type['max_depth'], $depth );
			foreach ( array_keys( $node ) as $node_key ) {
				if ( ! in_array( $node_key, self::STANDARD_NODE_KEYS, true ) ) {
					$type['extra_keys'][ $node_key ] = ( $type['extra_keys'][ $node_key ] ?? 0 ) + 1;
				}
			}
			unset( $type );

			$settings = $node['settings'] ?? array();
			if ( is_array( $settings ) ) {
				foreach ( $settings as $setting_key => $value ) {
					$this->walk_setting( $key, (string) $setting_key, (string) $setting_key, $value, 0 );
				}
			}

			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$this->walk_elements( $node['elements'], $counts, $depth + 1 );
			}
		}
	}

	/**
	 * Record one setting value and descend where text can live.
	 *
	 * @param string $type_key Element type key.
	 * @param string $path     Setting path.
	 * @param string $leaf     Last path segment (used by the fallback rule).
	 * @param mixed  $value    Value.
	 * @param int    $depth    Recursion depth.
	 */
	private function walk_setting( string $type_key, string $path, string $leaf, $value, int $depth ): void {
		if ( $depth > self::MAX_DEPTH || '__globals__' === $path ) {
			return;
		}

		if ( '__dynamic__' === $path && is_array( $value ) ) {
			foreach ( $value as $bound_key => $tag ) {
				$this->record( $type_key, '__dynamic__.' . $bound_key, 'dynamic', is_string( $tag ) ? $tag : null, false );
			}
			return;
		}

		// Atomic typed value: an object with the keys "$$type" and "value".
		if ( is_array( $value ) && isset( $value['$$type'] ) && array_key_exists( 'value', $value ) ) {
			$inner  = $value['value'];
			$kind   = '$$type:' . (string) $value['$$type'];
			$sample = is_string( $inner ) ? $inner : null;
			$this->record( $type_key, $path, $kind, $sample, is_string( $inner ) && self::is_atomic_text( (string) $value['$$type'], $leaf, $inner ) );
			if ( is_string( $inner ) ) {
				$this->scan_string( $inner );
			} elseif ( is_array( $inner ) ) {
				if ( array_is_list( $inner ) ) {
					foreach ( $inner as $item ) {
						$this->walk_setting( $type_key, $path . '[]', $leaf, $item, $depth + 1 );
					}
				} else {
					foreach ( $inner as $k => $v ) {
						$this->walk_setting( $type_key, $path . '.' . $k, (string) $k, $v, $depth + 1 );
					}
				}
			}
			return;
		}

		if ( is_string( $value ) ) {
			$this->record( $type_key, $path, 'string', $value, Text_Heuristics::is_fallback_text( $leaf, $value ) );
			$this->scan_string( $value );
			return;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$this->record( $type_key, $path, 'number', null, false );
			return;
		}
		if ( is_bool( $value ) || null === $value ) {
			$this->record( $type_key, $path, null === $value ? 'null' : 'bool', null, false );
			return;
		}
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( array() === $value ) {
			$this->record( $type_key, $path, 'empty_array', null, false );
			return;
		}

		// Repeater: list of objects.
		if ( array_is_list( $value ) ) {
			$is_repeater = is_array( $value[0] ) && ! array_is_list( $value[0] );
			$this->record( $type_key, $path, $is_repeater ? 'repeater' : 'list', null, false );
			if ( $is_repeater ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						foreach ( $item as $k => $v ) {
							$this->walk_setting( $type_key, $path . '[].' . $k, (string) $k, $v, $depth + 1 );
						}
					}
				}
			}
			return;
		}

		// URL control: {url, is_external, nofollow, custom_attributes}.
		if ( array_key_exists( 'url', $value ) && ( array_key_exists( 'is_external', $value ) || array_key_exists( 'nofollow', $value ) || array_key_exists( 'custom_attributes', $value ) ) ) {
			$this->record( $type_key, $path, 'url_control', is_string( $value['url'] ) ? $value['url'] : null, false );
			if ( is_string( $value['url'] ) ) {
				$this->note_url( $value['url'] );
			}
			return;
		}

		// Media, icon and slider objects: only their shape is recorded.
		$shape = array_keys( $value );
		sort( $shape );
		$this->record( $type_key, $path, 'object{' . implode( ',', array_slice( $shape, 0, 8 ) ) . '}', null, false );
		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			$this->note_url( $value['url'] );
		}
		// Some objects hold text (e.g. "alt" of a media control): descend one level for string fields.
		foreach ( $value as $k => $v ) {
			if ( is_string( $v ) && Text_Heuristics::is_fallback_text( (string) $k, $v ) ) {
				$this->record( $type_key, $path . '.' . $k, 'string', $v, true );
			}
		}
	}

	/**
	 * Whether an atomic string value looks like visible text: HTML-like types always,
	 * plain "string" values only when the key passes the fallback rule.
	 *
	 * @param string $prop_type $$type of the value.
	 * @param string $leaf      Key.
	 * @param string $value     Value.
	 */
	public static function is_atomic_text( string $prop_type, string $leaf, string $value ): bool {
		if ( null !== Text_Heuristics::non_text_reason( $value ) ) {
			return false;
		}
		if ( in_array( $prop_type, array( 'escaped-html', 'html', 'html-v2', 'html-v3' ), true ) ) {
			return true;
		}
		return 'string' === $prop_type && Text_Heuristics::is_candidate_key( $leaf );
	}

	/**
	 * Record a setting occurrence.
	 *
	 * @param string      $type_key Element type key.
	 * @param string      $path     Path.
	 * @param string      $kind     Value kind.
	 * @param string|null $sample   Sample value.
	 * @param bool        $looks_text Whether the fallback rule would pick it.
	 */
	private function record( string $type_key, string $path, string $kind, ?string $sample, bool $looks_text ): void {
		$settings = &$this->types[ $type_key ]['settings'];
		if ( ! isset( $settings[ $path ] ) ) {
			$settings[ $path ] = array(
				'count'         => 0,
				'kinds'         => array(),
				'fallback_text' => 0,
				'has_html'      => 0,
				'samples'       => array(),
			);
		}
		$s                   = &$settings[ $path ];
		$s['count']         += 1;
		$s['kinds'][ $kind ] = ( $s['kinds'][ $kind ] ?? 0 ) + 1;
		if ( $looks_text ) {
			++$s['fallback_text'];
		}
		if ( null !== $sample && '' !== trim( $sample ) ) {
			if ( Text_Heuristics::has_html( $sample ) ) {
				++$s['has_html'];
			}
			$short = mb_substr( $sample, 0, self::SAMPLE_LENGTH );
			if ( count( $s['samples'] ) < self::MAX_SAMPLES && ! in_array( $short, $s['samples'], true ) ) {
				$s['samples'][] = $short;
			}
		}
	}

	/**
	 * Find shortcodes and absolute URLs inside a string.
	 *
	 * @param string $value Value.
	 */
	private function scan_string( string $value ): void {
		if ( str_contains( $value, '[' ) && preg_match_all( '/\[([a-zA-Z][\w-]*)(?=[\s\]\/])[^\]]*\]/', $value, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$name = $match[1];
				if ( ! isset( $this->shortcodes[ $name ] ) ) {
					$this->shortcodes[ $name ] = array(
						'count'     => 0,
						'examples'  => array(),
						'documents' => array(),
					);
				}
				++$this->shortcodes[ $name ]['count'];
				if ( count( $this->shortcodes[ $name ]['examples'] ) < self::MAX_SAMPLES && ! in_array( $match[0], $this->shortcodes[ $name ]['examples'], true ) ) {
					$this->shortcodes[ $name ]['examples'][] = $match[0];
				}
				if ( ! in_array( $this->current_doc, $this->shortcodes[ $name ]['documents'], true ) ) {
					$this->shortcodes[ $name ]['documents'][] = $this->current_doc;
				}
			}
		}
		if ( preg_match_all( '#(?:href|src)=["\']([^"\']+)["\']#i', $value, $m ) ) {
			foreach ( $m[1] as $url ) {
				$this->note_url( $url );
			}
		}
	}

	/**
	 * Count the host of an absolute URL ("(relative)" for others).
	 *
	 * @param string $url URL.
	 */
	private function note_url( string $url ): void {
		if ( '' === trim( $url ) ) {
			return;
		}
		$host                     = preg_match( '#^(?:https?:)?//([^/:?\#]+)#i', $url, $m ) ? strtolower( $m[1] ) : ( preg_match( '#^(mailto|tel):#i', $url, $m2 ) ? strtolower( $m2[1] ) . ':' : '(relative)' );
		$this->url_hosts[ $host ] = ( $this->url_hosts[ $host ] ?? 0 ) + 1;
	}
}

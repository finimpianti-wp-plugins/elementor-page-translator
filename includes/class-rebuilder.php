<?php
/**
 * Rebuilder: applies translations, link choices and reference choices to a copy
 * of the Italian Elementor JSON. Element IDs and everything else stay identical.
 *
 * Pure function of its inputs: no WordPress calls, no sanitisation (done by the
 * caller before saving). Covered by PHPUnit on real fixtures.
 *
 * Inputs, all keyed by the Extractor's stable keys:
 * - $strings: key => translated text. Missing keys keep the original text.
 * - $links:   key => final URL (string); for atomic "query" links an int post ID
 *             or ['post_id' => int, 'label' => string]. Missing keys keep the original.
 * - $refs:    key => replacement value (shortcode text, menu slug or menu ID).
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Rebuilder.
 */
final class Rebuilder {

	/**
	 * Extractor (same map as the one used to build the keys).
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param Extractor|null $extractor Extractor.
	 */
	public function __construct( ?Extractor $extractor = null ) {
		$this->extractor = $extractor ?? new Extractor();
	}

	/**
	 * Rebuild and return only the new elements.
	 *
	 * @param array<int,mixed>     $elements Original elements.
	 * @param array<string,string> $strings  Translations.
	 * @param array<string,mixed>  $links    Link choices.
	 * @param array<string,string> $refs     Reference choices.
	 * @return array<int,mixed>
	 */
	public function rebuild( array $elements, array $strings = array(), array $links = array(), array $refs = array() ): array {
		return $this->apply( $elements, $strings, $links, $refs )['elements'];
	}

	/**
	 * Rebuild and report what was applied.
	 *
	 * @param array<int,mixed>     $elements Original elements.
	 * @param array<string,string> $strings  Translations.
	 * @param array<string,mixed>  $links    Link choices.
	 * @param array<string,string> $refs     Reference choices.
	 * @return array{elements: array<int,mixed>, applied: list<string>, unknown: list<string>, failed: list<string>}
	 */
	public function apply( array $elements, array $strings = array(), array $links = array(), array $refs = array() ): array {
		$found = $this->extractor->extract( $elements );

		// Field edits grouped by element ID and field path, applied in order:
		// 1. translated text, 2. inline shortcodes, 3. hrefs inside HTML.
		$edits   = array();
		$applied = array();
		$known   = array();

		foreach ( $found['strings'] as $item ) {
			$known[ $item['key'] ] = true;
			if ( $item['dynamic'] || ! array_key_exists( $item['key'], $strings ) || ! is_string( $strings[ $item['key'] ] ) ) {
				continue;
			}
			$edits[ $item['element_id'] ][ self::path_id( $item['path'] ) ]['path']   = $item['path'];
			$edits[ $item['element_id'] ][ self::path_id( $item['path'] ) ]['text'][] = array( $item['key'], $strings[ $item['key'] ] );
		}

		foreach ( $found['refs'] as $ref ) {
			$known[ $ref['key'] ] = true;
			if ( ! array_key_exists( $ref['key'], $refs ) || ! is_string( $refs[ $ref['key'] ] ) ) {
				continue;
			}
			$pid = self::path_id( $ref['path'] );
			$edits[ $ref['element_id'] ][ $pid ]['path'] = $ref['path'];
			if ( 'shortcode_inline' === $ref['type'] ) {
				$edits[ $ref['element_id'] ][ $pid ]['inline'][] = array( $ref['key'], $ref['value'], $refs[ $ref['key'] ] );
			} else {
				$edits[ $ref['element_id'] ][ $pid ]['text'][] = array( $ref['key'], $refs[ $ref['key'] ] );
			}
		}

		foreach ( $found['links'] as $link ) {
			$known[ $link['key'] ] = true;
			if ( ! array_key_exists( $link['key'], $links ) ) {
				continue;
			}
			$choice = $links[ $link['key'] ];
			$pid    = self::path_id( $link['path'] );
			$edits[ $link['element_id'] ][ $pid ]['path'] = $link['path'];
			if ( 'href' === $link['kind'] ) {
				if ( is_string( $choice ) ) {
					$edits[ $link['element_id'] ][ $pid ]['href'][] = array( $link['key'], (int) $link['index'], $link['raw'], $choice );
				}
			} elseif ( 'query' === $link['kind'] ) {
				$edits[ $link['element_id'] ][ $pid ]['query'][] = array( $link['key'], $choice );
			} elseif ( is_string( $choice ) ) {
				$edits[ $link['element_id'] ][ $pid ]['text'][] = array( $link['key'], $choice );
			}
		}

		$failed   = array();
		$elements = $this->walk( $elements, $edits, $applied, $failed );

		$requested = array_merge( array_keys( $strings ), array_keys( $links ), array_keys( $refs ) );
		$unknown   = array_values( array_diff( array_unique( $requested ), array_keys( $known ) ) );

		return array(
			'elements' => $elements,
			'applied'  => array_values( array_unique( $applied ) ),
			'unknown'  => $unknown,
			'failed'   => array_values( array_unique( $failed ) ),
		);
	}

	/**
	 * Walk the tree and apply the edits of each element.
	 *
	 * @param array<int,mixed>                       $elements Elements.
	 * @param array<string,array<string,mixed>>      $edits    Edits by element ID.
	 * @param list<string>                           $applied  Applied keys (by reference).
	 * @param list<string>                           $failed   Keys that could not be applied (by reference).
	 * @return array<int,mixed>
	 */
	private function walk( array $elements, array $edits, array &$applied, array &$failed ): array {
		foreach ( $elements as $i => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id = (string) ( $node['id'] ?? '' );
			if ( isset( $edits[ $id ] ) ) {
				foreach ( $edits[ $id ] as $field ) {
					$node = $this->apply_field( $node, $field, $applied, $failed );
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$node['elements'] = $this->walk( $node['elements'], $edits, $applied, $failed );
			}
			$elements[ $i ] = $node;
		}
		return $elements;
	}

	/**
	 * Apply the edits of one field to an element node.
	 *
	 * @param array<string,mixed> $node    Node.
	 * @param array<string,mixed> $field   Edits for one path.
	 * @param list<string>        $applied Applied keys.
	 * @param list<string>        $failed  Failed keys.
	 * @return array<string,mixed>
	 */
	private function apply_field( array $node, array $field, array &$applied, array &$failed ): array {
		$path = $field['path'];

		if ( ! empty( $field['query'] ) ) {
			list( $key, $choice ) = $field['query'][0];
			$target               = self::get( $node, $path );
			$post_id              = is_array( $choice ) ? (int) ( $choice['post_id'] ?? 0 ) : (int) $choice;
			if ( is_array( $target ) && $post_id > 0 ) {
				$target['value']['id']['value'] = $post_id;
				if ( is_array( $choice ) && isset( $choice['label'] ) && isset( $target['value']['label'] ) ) {
					$target['value']['label']['value'] = (string) $choice['label'];
				}
				$node      = self::set( $node, $path, $target );
				$applied[] = $key;
			} else {
				$failed[] = $key;
			}
			return $node;
		}

		$value = self::get( $node, $path );
		if ( ! is_string( $value ) ) {
			foreach ( array( 'text', 'inline', 'href' ) as $kind ) {
				foreach ( $field[ $kind ] ?? array() as $edit ) {
					$failed[] = $edit[0];
				}
			}
			return $node;
		}

		foreach ( $field['text'] ?? array() as $edit ) {
			$value     = $edit[1];
			$applied[] = $edit[0];
		}

		foreach ( $field['inline'] ?? array() as $edit ) {
			list( $key, $original, $replacement ) = $edit;
			if ( str_contains( $value, $original ) ) {
				$value     = str_replace( $original, $replacement, $value );
				$applied[] = $key;
			} else {
				$failed[] = $key;
			}
		}

		if ( ! empty( $field['href'] ) ) {
			$value = $this->replace_hrefs( $value, $field['href'], $applied, $failed );
		}

		return self::set( $node, $path, $value );
	}

	/**
	 * Replace href values inside an HTML string.
	 *
	 * The n-th href of the original is replaced by position when the (translated)
	 * HTML still has as many links as the original; otherwise by matching the
	 * original URL value.
	 *
	 * @param string                 $html    HTML.
	 * @param list<array{0:string,1:int,2:string,3:string}> $edits [key, index, original raw value, new URL].
	 * @param list<string>           $applied Applied keys.
	 * @param list<string>           $failed  Failed keys.
	 */
	private function replace_hrefs( string $html, array $edits, array &$applied, array &$failed ): string {
		$pattern = '/(\shref\s*=\s*)(["\'])(.*?)\2/i';
		preg_match_all( $pattern, $html, $m, PREG_OFFSET_CAPTURE );
		$current = array_map( static fn( array $x ): string => $x[0], $m[3] );

		$targets = array();
		$used    = array();
		foreach ( $edits as $edit ) {
			list( $key, $index, $raw, $url ) = $edit;
			$pos                             = null;
			if ( isset( $current[ $index ] ) && $current[ $index ] === $raw && ! isset( $used[ $index ] ) ) {
				$pos = $index;
			} else {
				foreach ( $current as $i => $value ) {
					if ( $value === $raw && ! isset( $used[ $i ] ) ) {
						$pos = $i;
						break;
					}
				}
			}
			if ( null === $pos ) {
				$failed[] = $key;
				continue;
			}
			$used[ $pos ]    = true;
			$targets[ $pos ] = array( $key, $url );
		}

		// Replace from the end so offsets stay valid.
		krsort( $targets );
		foreach ( $targets as $pos => $target ) {
			list( $key, $url ) = $target;
			$quote             = $m[2][ $pos ][0];
			$encoded           = '"' === $quote ? str_replace( '"', '&quot;', $url ) : str_replace( "'", '&#039;', $url );
			$html              = substr_replace( $html, $encoded, $m[3][ $pos ][1], strlen( $m[3][ $pos ][0] ) );
			$applied[]         = $key;
		}
		return $html;
	}

	/**
	 * Identifier of a concrete path.
	 *
	 * @param list<string|int> $path Path.
	 */
	private static function path_id( array $path ): string {
		return implode( "\x1f", $path );
	}

	/**
	 * Read a nested value.
	 *
	 * @param array<mixed>     $data Data.
	 * @param list<string|int> $path Path.
	 * @return mixed
	 */
	private static function get( array $data, array $path ) {
		foreach ( $path as $segment ) {
			if ( ! is_array( $data ) || ! array_key_exists( $segment, $data ) ) {
				return null;
			}
			$data = $data[ $segment ];
		}
		return $data;
	}

	/**
	 * Write a nested value (copy-on-write).
	 *
	 * @param array<mixed>     $data  Data.
	 * @param list<string|int> $path  Path.
	 * @param mixed            $value Value.
	 * @return array<mixed>
	 */
	private static function set( array $data, array $path, $value ): array {
		$segment = array_shift( $path );
		if ( array() === $path ) {
			$data[ $segment ] = $value;
			return $data;
		}
		$child            = isset( $data[ $segment ] ) && is_array( $data[ $segment ] ) ? $data[ $segment ] : array();
		$data[ $segment ] = self::set( $child, $path, $value );
		return $data;
	}
}

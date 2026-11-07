<?php
/**
 * References: menus and shortcodes per language (SPECIFICHE.md, "Riferimenti").
 *
 * - Menus (UAE Navigation Menu stores the menu slug; WordPress menu widget the menu ID):
 *   the user picks the menu of the language; a menu whose name or slug carries the
 *   language code is proposed.
 * - Shortcodes (Shortcode widget and shortcodes inside texts): kept, or replaced with
 *   another shortcode (e.g. the Forminator form of the language). Content of forms is
 *   never translated by this plugin.
 *
 * Choices are stored on the Italian page: _ept_refs_{lang} = key => [value, orig].
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Refs.
 */
final class Refs {

	/**
	 * Saved choices.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 * @return array<string,array{value:string,orig:string}>
	 */
	public static function choices( int $source_id, string $lang ): array {
		$data = get_post_meta( $source_id, '_ept_refs_' . $lang, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Navigation menus: [id, slug, name].
	 *
	 * @return list<array{id:int,slug:string,name:string}>
	 */
	public static function menus(): array {
		$out = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$out[] = array(
				'id'   => (int) $menu->term_id,
				'slug' => (string) $menu->slug,
				'name' => (string) $menu->name,
			);
		}
		return $out;
	}

	/**
	 * Menu proposed for a language: its name or slug carries the language code
	 * (e.g. "Header Menu EN", "header-menu-en"), preferring one similar to the original.
	 *
	 * @param string $original Original menu slug or ID.
	 * @param string $type     menu_slug | menu_id.
	 * @param string $lang     Language code.
	 */
	public static function propose_menu( string $original, string $type, string $lang ): string {
		$menus = self::menus();
		$orig  = null;
		foreach ( $menus as $menu ) {
			if ( ( 'menu_id' === $type ? (string) $menu['id'] : $menu['slug'] ) === $original ) {
				$orig = $menu;
			}
		}
		$base = null !== $orig ? (string) preg_replace( '/[-_ ](ita?|italiano)$/i', '', $orig['slug'] ) : '';
		$best = null;
		foreach ( $menus as $menu ) {
			$marks = preg_match( '/(^|[-_ ])' . preg_quote( $lang, '/' ) . '$/i', $menu['slug'] ) || preg_match( '/(^|[\s(\[-])' . preg_quote( $lang, '/' ) . '[\s)\]]*$/i', $menu['name'] );
			if ( ! $marks ) {
				continue;
			}
			if ( null === $best || ( '' !== $base && str_starts_with( $menu['slug'], $base ) ) ) {
				$best = $menu;
			}
		}
		if ( null === $best ) {
			return '';
		}
		return 'menu_id' === $type ? (string) $best['id'] : $best['slug'];
	}

	/**
	 * Rows of the references table of the screen.
	 *
	 * @param int              $source_id Italian page ID.
	 * @param string           $lang      Language.
	 * @param array<int,mixed> $elements  Italian elements.
	 * @return list<array<string,mixed>>
	 */
	public static function screen_rows( int $source_id, string $lang, array $elements ): array {
		$choices = self::choices( $source_id, $lang );
		$labels  = Translation_Service::widget_labels();
		$rows    = array();
		foreach ( Content::extractor()->extract( $elements )['refs'] as $ref ) {
			$choice   = $choices[ $ref['key'] ] ?? null;
			$stale    = is_array( $choice ) && ( $choice['orig'] ?? '' ) !== $ref['value'];
			$value    = is_array( $choice ) && ! $stale ? (string) $choice['value'] : $ref['value'];
			$proposed = false;
			if ( ( ! is_array( $choice ) || $stale ) && in_array( $ref['type'], array( 'menu_slug', 'menu_id' ), true ) ) {
				$candidate = self::propose_menu( $ref['value'], $ref['type'], $lang );
				if ( '' !== $candidate ) {
					$value    = $candidate;
					$proposed = true;
				}
			}
			$rows[] = array(
				'key'      => $ref['key'],
				'type'     => $ref['type'],
				'name'     => $ref['name'],
				'widget'   => $labels[ $ref['widget'] ] ?? ( 'navigation-menu' === $ref['widget'] ? __( 'Menu di navigazione (UAE)', 'ept' ) : $ref['widget'] ),
				'original' => $ref['value'],
				'value'    => $value,
				'proposed' => $proposed,
				'changed'  => $stale,
			);
		}
		return $rows;
	}

	/**
	 * Save choices posted by the screen.
	 *
	 * @param int                  $source_id Italian page ID.
	 * @param string               $lang      Language.
	 * @param array<string,mixed>  $posted    key => value.
	 */
	public static function save_choices( int $source_id, string $lang, array $posted ): void {
		$refs = array();
		foreach ( Content::extractor()->extract( Content::elements( $source_id ) )['refs'] as $ref ) {
			$refs[ $ref['key'] ] = $ref;
		}
		$choices = self::choices( $source_id, $lang );
		$menus   = self::menus();
		foreach ( $posted as $key => $value ) {
			$key = (string) $key;
			if ( ! isset( $refs[ $key ] ) || ! is_scalar( $value ) ) {
				continue;
			}
			$ref   = $refs[ $key ];
			$value = trim( (string) $value );
			switch ( $ref['type'] ) {
				case 'menu_slug':
					$valid = in_array( $value, array_column( $menus, 'slug' ), true );
					break;
				case 'menu_id':
					$valid = in_array( (int) $value, array_column( $menus, 'id' ), true );
					$value = (string) (int) $value;
					break;
				default:
					$value = sanitize_text_field( $value );
					$valid = '' !== $value;
			}
			if ( ! $valid || $value === $ref['value'] ) {
				unset( $choices[ $key ] ); // Keep the original.
				continue;
			}
			$choices[ $key ] = array(
				'value' => $value,
				'orig'  => $ref['value'],
			);
		}
		update_post_meta( $source_id, '_ept_refs_' . $lang, wp_slash( $choices ) );
	}

	/**
	 * Values for the Rebuilder: key => replacement (only changed ones).
	 *
	 * @param int              $source_id Italian page ID.
	 * @param string           $lang      Language.
	 * @param array<int,mixed> $elements  Italian elements.
	 * @return array<string,string>
	 */
	public static function final_values( int $source_id, string $lang, array $elements ): array {
		$choices = self::choices( $source_id, $lang );
		$values  = array();
		foreach ( Content::extractor()->extract( $elements )['refs'] as $ref ) {
			$choice = $choices[ $ref['key'] ] ?? null;
			if ( is_array( $choice ) && ( $choice['orig'] ?? '' ) === $ref['value'] && $choice['value'] !== $ref['value'] ) {
				$values[ $ref['key'] ] = (string) $choice['value'];
			}
		}
		return $values;
	}

	/**
	 * Forminator forms, as shortcode suggestions (empty when Forminator is not active).
	 *
	 * @return list<array{shortcode:string,name:string}>
	 */
	public static function form_suggestions(): array {
		if ( ! class_exists( '\Forminator_API' ) || ! method_exists( '\Forminator_API', 'get_forms' ) ) {
			return array();
		}
		$out   = array();
		$forms = \Forminator_API::get_forms( null, 1, 100 );
		foreach ( is_array( $forms ) ? $forms : array() as $form ) {
			$id   = (int) ( $form->id ?? 0 );
			$name = (string) ( $form->name ?? ( $form->settings['formName'] ?? '' ) );
			if ( $id ) {
				$out[] = array(
					'shortcode' => '[forminator_form id="' . $id . '"]',
					'name'      => '' !== $name ? $name : '#' . $id,
				);
			}
		}
		return $out;
	}
}

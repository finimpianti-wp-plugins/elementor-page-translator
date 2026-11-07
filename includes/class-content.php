<?php
/**
 * WordPress entry point to the pure Extractor and Rebuilder: builds them with the
 * widget map filtered by "ept_translatable_fields".
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Content.
 */
final class Content {

	/**
	 * Widget map after the public filter.
	 *
	 * Filter "ept_translatable_fields": array widgetType => [ field => format ].
	 * Add a widget, add/remove fields, or map a widget to [] to say it has no text.
	 * Formats: "text", "html", "code". Repeater fields: "items[].title".
	 */
	public static function widget_map(): Widget_Map {
		$map = apply_filters( 'ept_translatable_fields', Widget_Map::defaults() );
		return new Widget_Map( is_array( $map ) ? $map : Widget_Map::defaults() );
	}

	/**
	 * Extractor with the filtered map.
	 */
	public static function extractor(): Extractor {
		return new Extractor( self::widget_map() );
	}

	/**
	 * Rebuilder with the filtered map.
	 */
	public static function rebuilder(): Rebuilder {
		return new Rebuilder( self::extractor() );
	}

	/**
	 * Decoded _elementor_data of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,mixed>
	 */
	public static function elements( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$data = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Write Elementor data directly (no user permission needed) and drop the page's
	 * generated CSS and element cache, which Elementor rebuilds on the next view.
	 * Used only for link updates on pages whose data was already saved through Elementor.
	 *
	 * @param int              $post_id  Post ID.
	 * @param array<int,mixed> $elements Elements.
	 */
	public static function write_elements( int $post_id, array $elements ): void {
		update_metadata( 'post', $post_id, '_elementor_data', wp_slash( (string) wp_json_encode( $elements ) ) );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		if ( class_exists( '\\Elementor\\Core\\Files\\CSS\\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
		}
		clean_post_cache( $post_id );
	}

	/**
	 * Drop Elementor's element cache (rendered HTML of non-dynamic widgets such as the UAE
	 * site logo and breadcrumbs) of some posts, so it is rebuilt in the right context.
	 *
	 * @param list<int> $post_ids Post IDs.
	 */
	public static function flush_element_cache( array $post_ids ): void {
		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $id ) {
			if ( $id > 0 ) {
				delete_post_meta( $id, '_elementor_element_cache' );
			}
		}
	}

	/**
	 * IDs of all posts (pages and UAE templates) of a language.
	 *
	 * @param string $lang Language code.
	 * @return list<int>
	 */
	public static function posts_of_language( string $lang ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => array( 'page', Uae_Templates::POST_TYPE ),
					'post_status'      => 'any',
					'fields'           => 'ids',
					'posts_per_page'   => -1,
					'suppress_filters' => true,
					'meta_key'         => Translations::META_LANG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $lang, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}
}

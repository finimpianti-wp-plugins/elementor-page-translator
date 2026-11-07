<?php
/**
 * Multilingual SEO (SPECIFICHE.md, "SEO").
 *
 * Always: hreflang alternates (published versions in public languages, plus x-default to
 * the default language), og:locale:alternate, translated image alt texts, sitemap
 * exclusion of pages of non-public languages.
 * Without an SEO plugin: <title> of the language, meta description, Open Graph
 * (the canonical is WordPress' own rel_canonical, pointing to the page itself).
 * With Yoast SEO or Rank Math: their title/description fields of the translated page are
 * written at generation, and their output filters fall back to our values; their own
 * title, description, canonical and Open Graph are left alone (no duplicates).
 *
 * Verified on Yoast SEO 28.8 and Rank Math 1.0.281 (docs/verifiche.md).
 *
 * Storage: _ept_seo_{lang} on the Italian page = [title, description, alts{attachment ID: alt}];
 * _ept_meta_description on the Italian page = its own description (when no SEO plugin).
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * SEO.
 */
final class Seo {

	public const META_DESCRIPTION = '_ept_meta_description';
	public const TITLE_LENGTH     = 60;
	public const DESC_LENGTH      = 155;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( self::class, 'head' ), 2 );
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );

		// Yoast / Rank Math: fall back to our values when their fields are empty.
		add_filter( 'wpseo_title', array( self::class, 'plugin_title' ), 20 );
		add_filter( 'wpseo_metadesc', array( self::class, 'plugin_description' ), 20 );
		add_filter( 'rank_math/frontend/title', array( self::class, 'plugin_title' ), 20 );
		add_filter( 'rank_math/frontend/description', array( self::class, 'plugin_description' ), 20 );

		// Translated alt texts.
		add_filter( 'get_post_metadata', array( self::class, 'alt_meta' ), 10, 4 );
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'image_attributes' ), 20, 2 );

		// Sitemaps: no pages of non-public languages.
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'core_sitemap_args' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( self::class, 'excluded_ids' ) );
		add_filter( 'rank_math/sitemap/posts_to_exclude', array( self::class, 'excluded_ids' ) );
	}

	/**
	 * Active SEO plugin whose front end is running: yoast | rankmath | ''.
	 * Rank Math starts its front end only once its setup/registration is done
	 * (verified on 1.0.281): before that, this plugin prints title, description and OG itself.
	 */
	public static function seo_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) ) && self::rank_math_front_end() ) {
			return 'rankmath';
		}
		return '';
	}

	/**
	 * Active SEO plugin, whether or not its front end runs (for writing its fields).
	 */
	public static function installed_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		return defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' ) ? 'rankmath' : '';
	}

	/**
	 * Whether Rank Math's front-end head output is hooked.
	 */
	private static function rank_math_front_end(): bool {
		global $wp_filter;
		if ( ! isset( $wp_filter['wp_head'] ) || ! is_object( $wp_filter['wp_head'] ) ) {
			return false;
		}
		foreach ( (array) $wp_filter['wp_head']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( is_array( $fn ) && is_object( $fn[0] ) && str_starts_with( get_class( $fn[0] ), 'RankMath\\' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * SEO fields of a language, stored on the Italian page.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 * @return array{title:string,description:string,alts:array<int,string>}
	 */
	public static function fields( int $source_id, string $lang ): array {
		$data = get_post_meta( $source_id, '_ept_seo_' . $lang, true );
		$data = is_array( $data ) ? $data : array();
		return array(
			'title'       => (string) ( $data['title'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'alts'        => array_map( 'strval', is_array( $data['alts'] ?? null ) ? $data['alts'] : array() ),
		);
	}

	/**
	 * Save SEO fields posted by the screen.
	 *
	 * @param int                 $source_id Italian page ID.
	 * @param string              $lang      Language.
	 * @param array<string,mixed> $posted    title, description, alts{id: text}.
	 */
	public static function save_fields( int $source_id, string $lang, array $posted ): void {
		$fields = self::fields( $source_id, $lang );
		if ( array_key_exists( 'title', $posted ) ) {
			$fields['title'] = sanitize_text_field( (string) $posted['title'] );
		}
		if ( array_key_exists( 'description', $posted ) ) {
			$fields['description'] = sanitize_textarea_field( (string) $posted['description'] );
		}
		if ( isset( $posted['alts'] ) && is_array( $posted['alts'] ) ) {
			$allowed = array_flip( self::image_ids( $source_id ) );
			foreach ( $posted['alts'] as $id => $alt ) {
				$id = (int) $id;
				if ( isset( $allowed[ $id ] ) ) {
					$alt = sanitize_text_field( (string) $alt );
					if ( '' === $alt ) {
						unset( $fields['alts'][ $id ] );
					} else {
						$fields['alts'][ $id ] = $alt;
					}
				}
			}
		}
		update_post_meta( $source_id, '_ept_seo_' . $lang, wp_slash( $fields ) );
	}

	/**
	 * Attachment IDs of the images used in a page (Elementor media + featured image).
	 *
	 * @param int $source_id Italian page ID.
	 * @return list<int>
	 */
	public static function image_ids( int $source_id ): array {
		$ids   = array_column( Content::extractor()->extract( Content::elements( $source_id ) )['media'], 'attachment_id' );
		$thumb = (int) get_post_thumbnail_id( $source_id );
		if ( $thumb ) {
			$ids[] = $thumb;
		}
		return array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => 'attachment' === get_post_type( $id ) ) ) );
	}

	/**
	 * Original (default-language) SEO values of the Italian page.
	 *
	 * @param WP_Post $source Italian page.
	 * @return array{title:string,description:string}
	 */
	public static function originals( WP_Post $source ): array {
		$description = (string) get_post_meta( $source->ID, self::META_DESCRIPTION, true );
		if ( '' === $description ) {
			$description = (string) get_post_meta( $source->ID, '_yoast_wpseo_metadesc', true );
		}
		if ( '' === $description ) {
			$description = (string) get_post_meta( $source->ID, 'rank_math_description', true );
		}
		$title = (string) get_post_meta( $source->ID, '_yoast_wpseo_title', true );
		if ( '' === $title || str_contains( $title, '%%' ) ) {
			$title = (string) get_post_meta( $source->ID, 'rank_math_title', true );
		}
		if ( '' === $title || str_contains( $title, '%' ) ) {
			$title = $source->post_title . ' - ' . get_bloginfo( 'name' );
		}
		return array(
			'title'       => $title,
			'description' => str_contains( $description, '%%' ) ? '' : $description,
		);
	}

	/**
	 * Data of the SEO panel of the screen.
	 *
	 * @param WP_Post $source Italian page.
	 * @param string  $lang   Language.
	 * @return array<string,mixed>
	 */
	public static function screen_data( WP_Post $source, string $lang ): array {
		$fields = self::fields( $source->ID, $lang );
		$images = array();
		foreach ( self::image_ids( $source->ID ) as $id ) {
			$images[] = array(
				'id'       => $id,
				'thumb'    => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
				'file'     => wp_basename( (string) get_attached_file( $id ) ),
				'original' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'alt'      => $fields['alts'][ $id ] ?? '',
			);
		}
		return array(
			'title'       => $fields['title'],
			'description' => $fields['description'],
			'originals'   => self::originals( $source ),
			'images'      => $images,
			'plugin'      => self::seo_plugin(),
			'site_name'   => get_bloginfo( 'name' ),
			'title_max'   => self::TITLE_LENGTH,
			'desc_max'    => self::DESC_LENGTH,
		);
	}

	/**
	 * Write title and description into the SEO plugin fields of the translated page.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param int    $target_id Translated page ID.
	 * @param string $lang      Language.
	 */
	public static function write_plugin_fields( int $source_id, int $target_id, string $lang ): void {
		$fields = self::fields( $source_id, $lang );
		$plugin = self::installed_plugin();
		$keys   = array();
		if ( 'yoast' === $plugin ) {
			$keys = array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		} elseif ( 'rankmath' === $plugin ) {
			$keys = array( 'rank_math_title', 'rank_math_description' );
		}
		if ( ! $keys ) {
			return;
		}
		foreach ( array( $fields['title'], $fields['description'] ) as $i => $value ) {
			if ( '' !== $value ) {
				update_post_meta( $target_id, $keys[ $i ], wp_slash( $value ) );
			} else {
				delete_post_meta( $target_id, $keys[ $i ] );
			}
		}
	}

	/* --------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Translated page being viewed: [source ID, lang], or null.
	 *
	 * @return array{0:int,1:string}|null
	 */
	private static function current_translation(): ?array {
		if ( is_admin() || ! is_singular( 'page' ) ) {
			return null;
		}
		$id = (int) get_queried_object_id();
		if ( Translations::is_source( $id ) ) {
			return null;
		}
		$source = Translations::source_of( $id );
		return $source ? array( $source, Translations::language_of( $id ) ) : null;
	}

	/**
	 * Published versions of the current page in public languages (hreflang set).
	 *
	 * @return array<string,array{code:string,locale:string,url:string}> code => version
	 */
	public static function alternates(): array {
		if ( ! is_singular( 'page' ) ) {
			return array();
		}
		$id     = (int) get_queried_object_id();
		$source = Translations::is_source( $id ) ? $id : Translations::source_of( $id );
		if ( ! $source ) {
			return array();
		}
		$default  = Languages::default_language();
		$versions = array();
		if ( 'publish' === get_post_status( $source ) ) {
			$versions[ $default['code'] ] = array(
				'code'   => $default['code'],
				'locale' => $default['locale'],
				'url'    => (string) get_permalink( $source ),
			);
		}
		foreach ( Languages::published() as $code => $lang ) {
			$tid = Translations::get_id( $source, (string) $code );
			if ( $tid && 'publish' === get_post_status( $tid ) ) {
				$versions[ (string) $code ] = array(
					'code'   => (string) $code,
					'locale' => (string) $lang['locale'],
					'url'    => (string) get_permalink( $tid ),
				);
			}
		}
		return count( $versions ) > 1 ? $versions : array();
	}

	/**
	 * Output in <head>.
	 */
	public static function head(): void {
		if ( is_admin() ) {
			return;
		}
		$alternates = self::alternates();
		$default    = Languages::default_code();
		foreach ( $alternates as $version ) {
			printf( '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n", esc_attr( Current_Language::bcp47( $version['locale'] ) ), esc_url( $version['url'] ) );
		}
		if ( isset( $alternates[ $default ] ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $alternates[ $default ]['url'] ) );
		}

		$current = Current_Language::code();
		$locales = array();
		foreach ( $alternates as $code => $version ) {
			if ( $code !== $current ) {
				$locales[] = $version['locale'];
			}
		}

		if ( '' !== self::seo_plugin() || ! is_singular() ) {
			foreach ( $locales as $locale ) {
				printf( '<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr( $locale ) );
			}
			return;
		}

		// No SEO plugin: description and Open Graph.
		$post        = get_queried_object();
		$description = self::description();
		if ( '' !== $description ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
		}
		$tags = array(
			'og:locale'      => get_locale(),
			'og:type'        => 'website',
			'og:title'       => wp_get_document_title(),
			'og:description' => $description,
			'og:url'         => $post instanceof WP_Post ? (string) get_permalink( $post ) : '',
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:image'       => $post instanceof WP_Post && has_post_thumbnail( $post ) ? (string) get_the_post_thumbnail_url( $post, 'full' ) : '',
		);
		foreach ( $tags as $property => $content ) {
			if ( '' !== $content ) {
				printf( '<meta property="%1$s" content="%2$s" />' . "\n", esc_attr( $property ), 'og:url' === $property || 'og:image' === $property ? esc_url( $content ) : esc_attr( $content ) );
			}
		}
		foreach ( $locales as $locale ) {
			printf( '<meta property="og:locale:alternate" content="%s" />' . "\n", esc_attr( $locale ) );
		}
	}

	/**
	 * Meta description of the current page ('' when none).
	 */
	public static function description(): string {
		$translation = self::current_translation();
		if ( null !== $translation ) {
			return self::fields( $translation[0], $translation[1] )['description'];
		}
		if ( is_singular( 'page' ) ) {
			return (string) get_post_meta( (int) get_queried_object_id(), self::META_DESCRIPTION, true );
		}
		return '';
	}

	/**
	 * <title> of a translated page (no SEO plugin).
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function document_title( $title ) {
		if ( '' !== self::seo_plugin() ) {
			return $title;
		}
		$translation = self::current_translation();
		if ( null === $translation ) {
			return $title;
		}
		$meta = self::fields( $translation[0], $translation[1] )['title'];
		return '' !== $meta ? $meta : $title;
	}

	/**
	 * SEO plugin title: our meta title when the plugin field of the translated page is empty.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function plugin_title( $title ) {
		$translation = self::current_translation();
		if ( null === $translation ) {
			return $title;
		}
		$id    = (int) get_queried_object_id();
		$field = 'yoast' === self::installed_plugin() ? '_yoast_wpseo_title' : 'rank_math_title';
		if ( '' !== (string) get_post_meta( $id, $field, true ) ) {
			return $title;
		}
		$meta = self::fields( $translation[0], $translation[1] )['title'];
		return '' !== $meta ? $meta : $title;
	}

	/**
	 * SEO plugin description: our meta description when the plugin field is empty.
	 *
	 * @param string $description Description.
	 * @return string
	 */
	public static function plugin_description( $description ) {
		if ( '' !== trim( (string) $description ) ) {
			return $description;
		}
		$own = self::description();
		return '' !== $own ? $own : $description;
	}

	/**
	 * Translated alt of an attachment on the current translated page, or null.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function translated_alt( int $attachment_id ): ?string {
		$translation = self::current_translation();
		if ( null === $translation ) {
			return null;
		}
		$alt = self::fields( $translation[0], $translation[1] )['alts'][ $attachment_id ] ?? '';
		if ( '' !== $alt ) {
			return $alt;
		}
		// Same image translated on another page: the memory knows it.
		remove_filter( 'get_post_metadata', array( self::class, 'alt_meta' ), 10 );
		$original = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		add_filter( 'get_post_metadata', array( self::class, 'alt_meta' ), 10, 4 );
		if ( '' === $original ) {
			return null;
		}
		$memory = Memory::suggestions( $translation[1], array( Extractor::hash( $original ) ) );
		return $memory[ Extractor::hash( $original ) ] ?? null;
	}

	/**
	 * Filter _wp_attachment_image_alt reads on translated pages (Elementor reads it directly).
	 *
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single.
	 * @return mixed
	 */
	public static function alt_meta( $value, $object_id, $meta_key, $single ) {
		if ( '_wp_attachment_image_alt' !== $meta_key || is_admin() || ! did_action( 'wp' ) ) {
			return $value;
		}
		$alt = self::translated_alt( (int) $object_id );
		if ( null === $alt ) {
			return $value;
		}
		return $single ? $alt : array( $alt );
	}

	/**
	 * Alt attribute of wp_get_attachment_image() on translated pages.
	 *
	 * @param array<string,string> $attr       Attributes.
	 * @param WP_Post              $attachment Attachment.
	 * @return array<string,string>
	 */
	public static function image_attributes( $attr, $attachment ) {
		if ( $attachment instanceof WP_Post && ! is_admin() ) {
			$alt = self::translated_alt( $attachment->ID );
			if ( null !== $alt ) {
				$attr['alt'] = $alt;
			}
		}
		return $attr;
	}

	/**
	 * IDs of pages of languages that are not public (excluded from sitemaps).
	 *
	 * @param mixed $ids Existing exclusions.
	 * @return array<int,int>
	 */
	public static function excluded_ids( $ids = array() ) {
		$ids     = is_array( $ids ) ? $ids : array();
		$private = array_keys( array_diff_key( Languages::all(), Languages::published() ) );
		if ( ! $private ) {
			return $ids;
		}
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Translations::META_LANG,
						'value'   => array_map( 'strval', $private ),
						'compare' => 'IN',
					),
				),
			)
		);
		return array_values( array_unique( array_merge( array_map( 'intval', $ids ), array_map( 'intval', $pages ) ) ) );
	}

	/**
	 * WordPress core sitemap: exclude pages of non-public languages.
	 *
	 * @param array<string,mixed> $args      Query args.
	 * @param string              $post_type Post type.
	 * @return array<string,mixed>
	 */
	public static function core_sitemap_args( $args, $post_type ) {
		if ( 'page' === $post_type ) {
			$excluded = self::excluded_ids();
			if ( $excluded ) {
				$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $excluded );
			}
		}
		return $args;
	}
}

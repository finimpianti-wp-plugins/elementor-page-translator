<?php
/**
 * Unique slugs for translations.
 *
 * WordPress makes a slug unique only when the content is published: a draft may keep the slug
 * of another post. For UAE templates (no language prefix in the URL) a draft translation with
 * the slug of the Italian template answered the address of the Italian one too: the Elementor
 * preview of the Italian template (/elementor-hf/footer01/?elementor-preview=588) loaded the
 * English copy and the editor stayed on "Loading".
 *
 * Rules, for every status:
 * - UAE templates (not hierarchical): the slug of a translation is unique among all templates;
 *   when taken, it gets the language suffix ("footer01" → "footer01-en", then "-en-2"...).
 * - Pages: the slug must be unique among the pages with the same parent (the address is
 *   /en/services/ for /services/: the same slug under the language page is fine and kept).
 * - The home of a language (root page, slug = prefix) is never touched.
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Slugs.
 */
final class Slugs {

	/**
	 * Post types translated by the plugin.
	 */
	public const TYPES = array( 'page', Uae_Templates::POST_TYPE );

	/**
	 * Statuses that do not hold a slug.
	 */
	private const FREE_STATUSES = array( 'trash', 'auto-draft', 'inherit' );

	/**
	 * Re-entrancy guard of the save filter.
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'on_save' ), 20, 2 );
		add_action( 'init', array( self::class, 'run_pending_fix' ), 99 );
	}

	/**
	 * Option set by the update to 0.16.2: duplicate slugs still to fix.
	 */
	public const PENDING_OPTION = 'ept_slug_fix_pending';

	/**
	 * Option with the last automatic fix, shown once in the dashboard.
	 */
	public const REPORT_OPTION = 'ept_slug_fix_report';

	/**
	 * After the update: fix the duplicate slugs of translations and keep the list for the notice.
	 */
	public static function run_pending_fix(): void {
		if ( ! get_option( self::PENDING_OPTION ) ) {
			return;
		}
		delete_option( self::PENDING_OPTION );
		$changes = self::fix_duplicates();
		if ( $changes ) {
			update_option(
				self::REPORT_OPTION,
				array(
					'time'    => time(),
					'changes' => $changes,
				),
				false
			);
		}
	}

	/**
	 * Unique slug for a translation.
	 *
	 * @param string $wanted    Requested slug (already sanitised or not).
	 * @param string $post_type Post type.
	 * @param int    $parent_id Parent (pages).
	 * @param int    $exclude   ID of the translation itself (0 when it does not exist yet).
	 * @param string $lang      Language code.
	 */
	public static function for_translation( string $wanted, string $post_type, int $parent_id, int $exclude, string $lang ): string {
		$slug = sanitize_title( $wanted );
		if ( '' === $slug ) {
			$slug = 'translation';
		}
		if ( ! self::taken( $slug, $post_type, $parent_id, $exclude ) ) {
			return $slug;
		}
		$suffix = '-' . sanitize_title( $lang );
		$base   = str_ends_with( $slug, $suffix ) ? $slug : self::trim_for_suffix( $slug, $suffix ) . $suffix;
		if ( ! self::taken( $base, $post_type, $parent_id, $exclude ) ) {
			return $base;
		}
		for ( $n = 2; $n < 1000; $n++ ) {
			$candidate = self::trim_for_suffix( $base, '-' . $n ) . '-' . $n;
			if ( ! self::taken( $candidate, $post_type, $parent_id, $exclude ) ) {
				return $candidate;
			}
		}
		return $base . '-' . wp_generate_password( 4, false, false );
	}

	/**
	 * Keep slug + suffix within the 200 characters of post_name.
	 *
	 * @param string $slug   Slug.
	 * @param string $suffix Suffix.
	 */
	private static function trim_for_suffix( string $slug, string $suffix ): string {
		return substr( $slug, 0, 200 - strlen( $suffix ) );
	}

	/**
	 * Whether another post holds the slug (any status but trash / auto-draft / revisions).
	 *
	 * @param string $slug      Slug.
	 * @param string $post_type Post type.
	 * @param int    $parent_id Parent (only for hierarchical types).
	 * @param int    $exclude   Post to ignore.
	 */
	public static function taken( string $slug, string $post_type, int $parent_id, int $exclude ): bool {
		global $wpdb;
		if ( is_post_type_hierarchical( $post_type ) ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID <> %d AND post_status NOT IN ('trash','auto-draft','inherit') AND post_parent = %d LIMIT 1", $slug, $post_type, $exclude, $parent_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s AND ID <> %d AND post_status NOT IN ('trash','auto-draft','inherit') LIMIT 1", $slug, $post_type, $exclude ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return null !== $found;
	}

	/**
	 * Whether a post is a translation whose slug the plugin manages (not a language home).
	 *
	 * @param int $post_id Post ID.
	 */
	private static function managed( int $post_id ): bool {
		return $post_id > 0 && Translations::source_of( $post_id ) > 0 && null === Root_Pages::active_root_language( $post_id );
	}

	/**
	 * Every save of a translation (Elementor, quick edit, the plugin itself): unique slug.
	 *
	 * @param array<string,mixed> $data    Sanitised post data.
	 * @param array<string,mixed> $postarr Raw post data.
	 * @return array<string,mixed>
	 */
	public static function on_save( $data, $postarr ) {
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		$type    = (string) ( $data['post_type'] ?? '' );
		if ( self::$running || ! in_array( $type, self::TYPES, true ) || in_array( (string) ( $data['post_status'] ?? '' ), self::FREE_STATUSES, true )
			|| '' === (string) ( $data['post_name'] ?? '' ) || ! self::managed( $post_id ) ) {
			return $data;
		}
		self::$running     = true;
		$data['post_name'] = self::for_translation( (string) $data['post_name'], $type, (int) ( $data['post_parent'] ?? 0 ), $post_id, Translations::language_of( $post_id ) );
		self::$running     = false;
		return $data;
	}

	/**
	 * Groups of posts of the translated types sharing a slug (same parent for pages).
	 *
	 * @return array<int,array{type:string,slug:string,posts:array<int,array{id:int,title:string,status:string,lang:string,translation:bool}>}>
	 */
	public static function duplicates(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.post_type, p.post_name, p.post_parent FROM {$wpdb->posts} p
				WHERE p.post_type IN (%s, %s) AND p.post_name <> '' AND p.post_status NOT IN ('trash','auto-draft','inherit')
				GROUP BY p.post_type, p.post_name, CASE WHEN p.post_type = 'page' THEN p.post_parent ELSE 0 END
				HAVING COUNT(*) > 1",
				self::TYPES[0],
				self::TYPES[1]
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$args = array(
				'post_type'        => $row['post_type'],
				'name'             => $row['post_name'],
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			);
			if ( 'page' === $row['post_type'] ) {
				$args['post_parent'] = (int) $row['post_parent'];
			}
			$posts = array();
			foreach ( get_posts( $args ) as $post ) {
				if ( ! $post instanceof WP_Post || $post->post_name !== $row['post_name'] ) {
					continue;
				}
				$posts[] = array(
					'id'          => (int) $post->ID,
					'title'       => (string) $post->post_title,
					'status'      => (string) $post->post_status,
					'lang'        => Translations::language_of( (int) $post->ID ),
					'translation' => self::managed( (int) $post->ID ),
				);
			}
			if ( count( $posts ) > 1 ) {
				$out[] = array(
					'type'  => (string) $row['post_type'],
					'slug'  => (string) $row['post_name'],
					'posts' => $posts,
				);
			}
		}
		return $out;
	}

	/**
	 * Give a unique slug to every translation that shares its slug. The Italian contents keep
	 * theirs. Returns the changes made.
	 *
	 * @return array<int,array{id:int,title:string,type:string,lang:string,old:string,new:string}>
	 */
	public static function fix_duplicates(): array {
		$changes = array();
		foreach ( self::duplicates() as $group ) {
			$translations = array_values( array_filter( $group['posts'], static fn( array $p ): bool => $p['translation'] ) );
			$others       = count( $group['posts'] ) - count( $translations );
			// When only translations share the slug, the first one keeps it.
			if ( 0 === $others ) {
				array_shift( $translations );
			}
			foreach ( $translations as $p ) {
				$post = get_post( $p['id'] );
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				$new = self::for_translation( $post->post_name, $post->post_type, (int) $post->post_parent, (int) $post->ID, $p['lang'] );
				if ( $new === $post->post_name ) {
					continue;
				}
				self::$running = true; // The slug is final: no second pass in on_save().
				$result        = wp_update_post(
					array(
						'ID'        => (int) $post->ID,
						'post_name' => $new,
					),
					true
				);
				self::$running = false;
				if ( is_wp_error( $result ) ) {
					continue;
				}
				clean_post_cache( (int) $post->ID );
				$changes[] = array(
					'id'    => (int) $post->ID,
					'title' => (string) $post->post_title,
					'type'  => (string) $post->post_type,
					'lang'  => $p['lang'],
					'old'   => (string) $post->post_name,
					'new'   => (string) get_post_field( 'post_name', (int) $post->ID ),
				);
			}
		}
		return $changes;
	}
}

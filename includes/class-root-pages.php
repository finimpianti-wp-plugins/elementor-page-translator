<?php
/**
 * Root pages: one top-level page per language whose slug is the URL prefix (/en/).
 *
 * - Created as a draft when a language is activated (owner's decision, see SPECIFICHE.md).
 * - Linked by ID in the language option, and also marked with the meta
 *   "_ept_root_lang" so the link can be recovered if the option is lost.
 * - Protected while the language is active: no trash, no delete, no slug or parent change.
 * - Italian top-level pages cannot take a slug equal to a language prefix.
 *
 * @package EPT
 */

namespace EPT;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Root pages.
 */
final class Root_Pages {

	public const META_ROOT = '_ept_root_lang';
	public const META_LANG = '_ept_lang';

	/**
	 * True while the plugin itself updates a root page.
	 *
	 * @var bool
	 */
	private static bool $bypass = false;

	/**
	 * Hook protections.
	 */
	public static function init(): void {
		add_filter( 'pre_trash_post', array( self::class, 'block_trash' ), 10, 2 );
		add_filter( 'pre_delete_post', array( self::class, 'block_delete' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'lock_slug_and_parent' ), 10, 2 );
		add_filter( 'wp_unique_post_slug', array( self::class, 'avoid_prefix_slugs' ), 10, 6 );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 10, 2 );
		add_filter( 'page_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	/**
	 * Make sure the root page of a language exists and has the right slug.
	 *
	 * @param string $code Language code.
	 * @return int|WP_Error Page ID.
	 */
	public static function ensure( string $code ) {
		$lang = Languages::get( $code );
		if ( null === $lang ) {
			return new WP_Error( 'ept_unknown_language', __( 'Lingua sconosciuta.', 'ept' ) );
		}
		$prefix = (string) $lang['prefix'];

		$page_id = (int) $lang['root_page_id'];
		$page    = $page_id ? get_post( $page_id ) : null;

		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
			$page = self::find_marked_root( $code );
		}

		if ( $page instanceof WP_Post && 'trash' === $page->post_status ) {
			return new WP_Error(
				'ept_root_in_trash',
				sprintf(
					/* translators: 1: language name, 2: page title. */
					__( 'La pagina radice di %1$s ("%2$s") è nel cestino. Ripristinala, oppure eliminala definitivamente per farne creare una nuova, poi riattiva la lingua.', 'ept' ),
					$lang['name'],
					$page->post_title
				)
			);
		}

		$conflict = self::find_conflicting_page( $prefix, $page instanceof WP_Post ? $page->ID : 0 );
		if ( $conflict instanceof WP_Post ) {
			return new WP_Error(
				'ept_prefix_conflict',
				sprintf(
					/* translators: 1: URL prefix, 2: page title, 3: page ID. */
					__( 'Esiste già una pagina di primo livello con slug "%1$s": "%2$s" (ID %3$d). Cambia lo slug di quella pagina o scegli un altro prefisso, poi riprova.', 'ept' ),
					$prefix,
					$conflict->post_title,
					$conflict->ID
				)
			);
		}

		if ( $page instanceof WP_Post ) {
			if ( $page->post_name !== $prefix || 0 !== (int) $page->post_parent ) {
				self::$bypass = true;
				wp_update_post(
					array(
						'ID'          => $page->ID,
						'post_name'   => $prefix,
						'post_parent' => 0,
					)
				);
				self::$bypass = false;
			}
			$page_id = $page->ID;
		} else {
			self::$bypass = true;
			$page_id      = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_title'  => (string) $lang['name'],
					'post_name'   => $prefix,
					'post_parent' => 0,
				),
				true
			);
			self::$bypass = false;
			if ( is_wp_error( $page_id ) ) {
				return $page_id;
			}
		}

		update_post_meta( $page_id, self::META_ROOT, $code );
		update_post_meta( $page_id, self::META_LANG, $code );

		if ( (int) $lang['root_page_id'] !== (int) $page_id ) {
			$lang['root_page_id'] = (int) $page_id;
			Languages::put( $lang );
		}

		return (int) $page_id;
	}

	/**
	 * A non-trashed top-level page already marked as root of this language.
	 *
	 * @param string $code Language code.
	 */
	private static function find_marked_root( string $code ): ?WP_Post {
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
				'meta_key'         => self::META_ROOT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		return $pages[0] ?? null;
	}

	/**
	 * Another top-level page (not trashed) using the slug.
	 *
	 * @param string $slug       Slug.
	 * @param int    $exclude_id Page to ignore.
	 */
	public static function find_conflicting_page( string $slug, int $exclude_id = 0 ): ?WP_Post {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin-only check, must see drafts too.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_parent = 0 AND post_name = %s AND post_status NOT IN ('trash','auto-draft','inherit') AND ID <> %d ORDER BY ID LIMIT 1",
				$slug,
				$exclude_id
			)
		);
		return $id ? get_post( $id ) : null;
	}

	/**
	 * Language code of the active language whose root page this is, or null.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function active_root_language( int $post_id ): ?string {
		foreach ( Languages::active() as $code => $lang ) {
			if ( (int) $lang['root_page_id'] === $post_id ) {
				return (string) $code;
			}
		}
		return null;
	}

	/**
	 * Language code of any configured language whose root page this is, or null.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function root_language( int $post_id ): ?string {
		foreach ( Languages::all() as $code => $lang ) {
			if ( (int) $lang['root_page_id'] === $post_id ) {
				return (string) $code;
			}
		}
		return null;
	}

	/**
	 * Filter: forbid moving an active root page to the trash.
	 *
	 * @param mixed   $check Short-circuit value.
	 * @param WP_Post $post  Post.
	 * @return mixed
	 */
	public static function block_trash( $check, $post ) {
		if ( $post instanceof WP_Post && null !== self::active_root_language( $post->ID ) ) {
			Admin\Notices::add( 'error', self::protected_message( $post->ID ) );
			return false;
		}
		return $check;
	}

	/**
	 * Filter: forbid deleting an active root page.
	 *
	 * @param mixed   $check Short-circuit value.
	 * @param WP_Post $post  Post.
	 * @return mixed
	 */
	public static function block_delete( $check, $post ) {
		return self::block_trash( $check, $post );
	}

	/**
	 * Filter: keep slug and parent of an active root page fixed.
	 *
	 * @param array<string,mixed> $data    Sanitised post data.
	 * @param array<string,mixed> $postarr Raw post data, including ID.
	 * @return array<string,mixed>
	 */
	public static function lock_slug_and_parent( $data, $postarr ) {
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( self::$bypass || ! $post_id || 'page' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		$code = self::active_root_language( $post_id );
		if ( null === $code ) {
			return $data;
		}
		$prefix = (string) Languages::get( $code )['prefix'];
		if ( ( $data['post_name'] ?? '' ) !== $prefix || 0 !== (int) ( $data['post_parent'] ?? 0 ) ) {
			if ( 'trash' !== ( $data['post_status'] ?? '' ) ) {
				Admin\Notices::add( 'warning', self::protected_message( $post_id ) );
			}
			$data['post_name']   = $prefix;
			$data['post_parent'] = 0;
		}
		return $data;
	}

	/**
	 * Filter: a top-level page that is not a root page cannot use a language prefix as slug.
	 *
	 * @param string $slug          Unique slug computed by WordPress.
	 * @param int    $post_id       Post ID.
	 * @param string $post_status   Status.
	 * @param string $post_type     Post type.
	 * @param int    $post_parent   Parent ID.
	 * @param string $original_slug Requested slug.
	 * @return string
	 */
	public static function avoid_prefix_slugs( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug ) {
		unset( $post_status, $original_slug );
		if ( self::$bypass || 'page' !== $post_type || 0 !== (int) $post_parent ) {
			return $slug;
		}
		$prefixes = Languages::prefixes();
		if ( ! in_array( $slug, $prefixes, true ) ) {
			return $slug;
		}
		$owner = array_search( $slug, $prefixes, true );
		if ( (int) Languages::get( (string) $owner )['root_page_id'] === (int) $post_id ) {
			return $slug;
		}
		$suffix = 2;
		do {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		} while ( in_array( $candidate, $prefixes, true ) || null !== self::find_conflicting_page( $candidate, (int) $post_id ) );
		return $candidate;
	}

	/**
	 * Label root pages in the page list.
	 *
	 * @param array<string,string> $states States.
	 * @param WP_Post              $post   Post.
	 * @return array<string,string>
	 */
	public static function post_states( $states, $post ) {
		$code = $post instanceof WP_Post ? self::root_language( $post->ID ) : null;
		if ( null !== $code ) {
			$states['ept_root'] = sprintf(
				/* translators: %s: language name. */
				__( 'Pagina radice %s', 'ept' ),
				Languages::get( $code )['name']
			);
		}
		return $states;
	}

	/**
	 * Remove "Trash" from protected root pages.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param WP_Post              $post    Post.
	 * @return array<string,string>
	 */
	public static function row_actions( $actions, $post ) {
		if ( $post instanceof WP_Post && null !== self::active_root_language( $post->ID ) ) {
			unset( $actions['trash'], $actions['delete'] );
		}
		return $actions;
	}

	/**
	 * Message explaining the protection.
	 *
	 * @param int $post_id Root page ID.
	 */
	private static function protected_message( int $post_id ): string {
		$code = (string) self::active_root_language( $post_id );
		return sprintf(
			/* translators: %s: language name. */
			__( 'Questa è la pagina radice della lingua %s: non si può cestinare, eliminare né cambiarne slug o genitore finché la lingua è attiva. Disattiva prima la lingua in Impostazioni → Traduzioni.', 'ept' ),
			Languages::get( $code )['name'] ?? $code
		);
	}
}

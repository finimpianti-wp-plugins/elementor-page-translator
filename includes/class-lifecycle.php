<?php
/**
 * Lifecycle of Italian pages and of their translations (SPECIFICHE.md,
 * "Sincronizzazione e ciclo di vita" and "Redirect").
 *
 * Italian page events never act on translations by themselves: they create a notice in
 * the admin with the proposed action, applied only after confirmation.
 *   slug changed   -> new translated slug per language (editable), applied on confirmation;
 *   parent changed -> move translations under the translation of the new parent;
 *   trashed        -> "trash the translations too?" yes / no, unlink them;
 *   restored       -> restore the translations trashed with it;
 *   deleted        -> translations in the trash deleted only on confirmation,
 *                     the others stay and are marked "orphan".
 * Translated page trashed: its link disappears (badge back to "missing"; restoring it
 * brings it back); deleted: the link and its index rows are removed.
 * Redirects: WordPress does not redirect old URLs of pages (hierarchical types are
 * skipped by wp_check_for_changed_slugs, verified on 7.1.4): when a translated page (or a
 * parent of it) changes address, the old path is kept and 404s on it redirect (301).
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle.
 */
final class Lifecycle {

	public const OPTION       = 'ept_lifecycle_events';
	public const META_OLD     = '_ept_old_path';
	public const META_ORPHAN  = '_ept_orphan';
	public const META_TRASHED = '_ept_trashed_with';

	/**
	 * Whether translations are being restored by a confirmed action.
	 *
	 * @var bool
	 */
	private static bool $restoring = false;

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'post_updated', array( self::class, 'on_update' ), 10, 3 );
		add_action( 'trashed_post', array( self::class, 'on_trashed' ) );
		add_action( 'untrashed_post', array( self::class, 'on_untrashed' ) );
		add_action( 'before_delete_post', array( self::class, 'on_delete' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'redirect_old_paths' ), 1 );
		add_filter( 'wp_untrash_post_status', array( self::class, 'untrash_status' ), 10, 3 );
	}

	/* --------------------------------------------------------------------
	 * Events store
	 * ------------------------------------------------------------------ */

	/**
	 * Pending events.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function events(): array {
		$events = get_option( self::OPTION, array() );
		return is_array( $events ) ? $events : array();
	}

	/**
	 * Add (or replace) an event; one per source page and type.
	 *
	 * @param string              $type   slug|parent|trash|untrash|delete.
	 * @param int                 $source Italian page ID.
	 * @param array<string,mixed> $data   Data.
	 */
	public static function add_event( string $type, int $source, array $data ): void {
		$events        = self::events();
		$id            = $type . '-' . $source;
		$events[ $id ] = array_merge(
			$data,
			array(
				'id'     => $id,
				'type'   => $type,
				'source' => $source,
				'time'   => time(),
			)
		);
		update_option( self::OPTION, $events, false );
	}

	/**
	 * Remove an event.
	 *
	 * @param string $id Event ID.
	 */
	public static function remove_event( string $id ): void {
		$events = self::events();
		unset( $events[ $id ] );
		update_option( self::OPTION, $events, false );
	}

	/**
	 * Translations of an Italian page with any status (trash included).
	 *
	 * @param int $source Italian page ID.
	 * @return array<string,int> lang => ID
	 */
	public static function all_translations( int $source ): array {
		$ids = get_posts(
			array(
				'post_type'        => array( 'page', Uae_Templates::POST_TYPE ),
				'post_status'      => 'any,trash',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				'suppress_filters' => true,
				'meta_key'         => Translations::META_SOURCE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$out = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$out[ Translations::language_of( $id ) ] = $id;
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * Events on pages
	 * ------------------------------------------------------------------ */

	/**
	 * Slug and parent changes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   After.
	 * @param WP_Post $before  Before.
	 */
	public static function on_update( $post_id, $after, $before ): void {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || 'page' !== $after->post_type ) {
			return;
		}
		if ( in_array( 'trash', array( $after->post_status, $before->post_status ), true ) ) {
			return; // Trashing renames the slug ("__trashed"): not a slug change.
		}
		$moved = $after->post_name !== $before->post_name || (int) $after->post_parent !== (int) $before->post_parent;
		if ( ! $moved ) {
			return;
		}

		if ( ! Translations::is_source( (int) $post_id ) ) {
			// Translated page: remember the old address of it and of its sub-pages for redirects.
			if ( 'publish' === $before->post_status && '' !== $before->post_name ) {
				self::remember_old_paths( (int) $post_id, (string) get_permalink( $before ), (string) get_permalink( $after ) );
			}
			return;
		}

		$translations = Translations::map( (int) $post_id );
		if ( ! $translations ) {
			return;
		}
		if ( $after->post_name !== $before->post_name && '' !== $before->post_name ) {
			$proposals = array();
			foreach ( $translations as $lang => $tid ) {
				$proposals[ $lang ] = array(
					'target'   => $tid,
					'current'  => (string) get_post_field( 'post_name', $tid ),
					'proposed' => self::propose_slug( $after, $lang, $tid ),
				);
			}
			self::add_event(
				'slug',
				(int) $post_id,
				array(
					'old'   => $before->post_name,
					'new'   => $after->post_name,
					'langs' => $proposals,
				)
			);
		}
		if ( (int) $after->post_parent !== (int) $before->post_parent ) {
			$moves = array();
			foreach ( $translations as $lang => $tid ) {
				$parent         = Translation_Service::target_parent( $after, $lang );
				$moves[ $lang ] = array(
					'target'   => $tid,
					'current'  => (int) wp_get_post_parent_id( $tid ),
					'proposed' => $parent['ok'] ? (int) $parent['id'] : 0,
					'ok'       => $parent['ok'],
					'message'  => $parent['message'],
				);
			}
			self::add_event( 'parent', (int) $post_id, array( 'langs' => $moves ) );
		}
	}

	/**
	 * Proposed translated slug: the new Italian slug translated (memory or DeepL when
	 * available), else the current translated slug.
	 *
	 * @param WP_Post $source Italian page (after the change).
	 * @param string  $lang   Language.
	 * @param int     $target Translated page.
	 */
	private static function propose_slug( WP_Post $source, string $lang, int $target ): string {
		$words  = str_replace( '-', ' ', $source->post_name );
		$memory = Memory::suggestions( $lang, array( Extractor::hash( $words ) ) );
		if ( isset( $memory[ Extractor::hash( $words ) ] ) ) {
			return sanitize_title( $memory[ Extractor::hash( $words ) ] );
		}
		$client = Proposals::client();
		$locale = (string) ( Languages::get( $lang )['locale'] ?? '' );
		if ( null !== $client && '' !== $locale ) {
			$result = $client->translate( array( 'slug' => $words ), Deepl::target_lang( $locale ), Deepl::source_lang( (string) Languages::default_language()['locale'] ), false, $source->post_title );
			if ( is_array( $result ) && '' !== trim( (string) ( $result['slug'] ?? '' ) ) ) {
				return sanitize_title( (string) $result['slug'] );
			}
		}
		return (string) get_post_field( 'post_name', $target );
	}

	/**
	 * Page moved to the trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_trashed( $post_id ): void {
		$post_id = (int) $post_id;
		self::refresh_map_of( $post_id );
		if ( 'page' !== get_post_type( $post_id ) || ! Translations::is_source( $post_id ) ) {
			return;
		}
		$alive = array_filter( self::all_translations( $post_id ), static fn( int $id ): bool => 'trash' !== get_post_status( $id ) );
		if ( $alive ) {
			self::add_event( 'trash', $post_id, array( 'translations' => $alive ) );
		}
		self::remove_event( 'untrash-' . $post_id );
	}

	/**
	 * Translated page trashed or restored: its link disappears or comes back.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function refresh_map_of( int $post_id ): void {
		$source = Translations::source_of( $post_id );
		if ( $source ) {
			wp_cache_delete( 'map_' . $source, 'ept' );
		}
	}

	/**
	 * Status of a translation restored with its Italian page: the one it had before the
	 * trash (WordPress would restore it as a draft).
	 *
	 * @param string $status          Status proposed by WordPress.
	 * @param int    $post_id         Post ID.
	 * @param string $previous_status Status before the trash.
	 */
	public static function untrash_status( $status, $post_id, $previous_status ) {
		if ( self::$restoring && '' !== (string) $previous_status ) {
			return (string) $previous_status;
		}
		return $status;
	}

	/**
	 * Page restored from the trash.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_untrashed( $post_id ): void {
		$post_id = (int) $post_id;
		self::refresh_map_of( $post_id );
		self::remove_event( 'trash-' . $post_id );
		if ( 'page' !== get_post_type( $post_id ) || ! Translations::is_source( $post_id ) ) {
			return;
		}
		$with = array_filter(
			self::all_translations( $post_id ),
			static fn( int $id ): bool => 'trash' === get_post_status( $id ) && (int) get_post_meta( $id, self::META_TRASHED, true ) === $post_id
		);
		if ( $with ) {
			self::add_event( 'untrash', $post_id, array( 'translations' => $with ) );
		}
	}

	/**
	 * Page deleted permanently.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 */
	public static function on_delete( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		$post    = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'page', Uae_Templates::POST_TYPE ), true ) ) {
			return;
		}
		if ( ! Translations::is_source( $post_id ) ) {
			// Translated page: unlink it and drop its index rows and redirects.
			$source = Translations::source_of( $post_id );
			$lang   = Translations::language_of( $post_id );
			if ( $source ) {
				$map = get_post_meta( $source, Translations::META_MAP, true );
				if ( is_array( $map ) && (int) ( $map[ $lang ] ?? 0 ) === $post_id ) {
					unset( $map[ $lang ] );
					update_post_meta( $source, Translations::META_MAP, $map );
					wp_cache_delete( 'map_' . $source, 'ept' );
				}
			}
			Links::write_index( $post_id, $lang, array() );
			return;
		}

		$translations = self::all_translations( $post_id );
		if ( ! $translations ) {
			return;
		}
		$trashed = array();
		$orphans = array();
		foreach ( $translations as $lang => $tid ) {
			if ( 'trash' === get_post_status( $tid ) ) {
				$trashed[ $lang ] = $tid;
			} else {
				self::unlink( $tid );
				$orphans[ $lang ] = $tid;
			}
		}
		self::remove_event( 'trash-' . $post_id );
		self::remove_event( 'untrash-' . $post_id );
		self::add_event(
			'delete',
			$post_id,
			array(
				'title'   => $post->post_title,
				'trashed' => $trashed,
				'orphans' => $orphans,
			)
		);
	}

	/* --------------------------------------------------------------------
	 * Confirmed actions
	 * ------------------------------------------------------------------ */

	/**
	 * Apply an event with the user's choice.
	 *
	 * @param string               $id     Event ID.
	 * @param string               $choice apply|unlink|dismiss.
	 * @param array<string,string> $values Per-language values (slugs).
	 * @return string Message.
	 */
	public static function apply( string $id, string $choice, array $values = array() ): string {
		$event = self::events()[ $id ] ?? null;
		if ( ! is_array( $event ) ) {
			return __( 'Operazione già eseguita o non più valida.', 'ept' );
		}
		self::remove_event( $id );
		if ( 'dismiss' === $choice ) {
			return __( 'Avviso ignorato: le traduzioni non sono state modificate.', 'ept' );
		}
		$done = 0;
		switch ( $event['type'] ) {
			case 'slug':
				foreach ( $event['langs'] as $lang => $row ) {
					$slug = sanitize_title( (string) ( $values[ $lang ] ?? $row['proposed'] ) );
					$tid  = (int) $row['target'];
					if ( '' !== $slug && get_post( $tid ) && current_user_can( 'edit_post', $tid ) && get_post_field( 'post_name', $tid ) !== $slug ) {
						wp_update_post(
							array(
								'ID'        => $tid,
								'post_name' => $slug,
							)
						);
						++$done;
					}
				}
				/* translators: %d: number of pages. */
				return sprintf( _n( 'Slug aggiornato in %d traduzione.', 'Slug aggiornato in %d traduzioni.', $done, 'ept' ), $done );

			case 'parent':
				foreach ( $event['langs'] as $row ) {
					$tid = (int) $row['target'];
					if ( ! empty( $row['ok'] ) && get_post( $tid ) && current_user_can( 'edit_post', $tid ) && (int) wp_get_post_parent_id( $tid ) !== (int) $row['proposed'] ) {
						wp_update_post(
							array(
								'ID'          => $tid,
								'post_parent' => (int) $row['proposed'],
							)
						);
						++$done;
					}
				}
				/* translators: %d: number of pages. */
				return sprintf( _n( '%d traduzione spostata.', '%d traduzioni spostate.', $done, 'ept' ), $done );

			case 'trash':
				foreach ( $event['translations'] as $tid ) {
					$tid = (int) $tid;
					if ( ! get_post( $tid ) || ! current_user_can( 'delete_post', $tid ) ) {
						continue;
					}
					if ( 'unlink' === $choice ) {
						self::unlink( $tid );
					} elseif ( wp_trash_post( $tid ) ) {
						update_post_meta( $tid, self::META_TRASHED, (int) $event['source'] );
					}
					++$done;
				}
				return 'unlink' === $choice
					/* translators: %d: number of pages. */
					? sprintf( _n( '%d traduzione scollegata: ora è una pagina indipendente.', '%d traduzioni scollegate: ora sono pagine indipendenti.', $done, 'ept' ), $done )
					/* translators: %d: number of pages. */
					: sprintf( _n( '%d traduzione spostata nel cestino.', '%d traduzioni spostate nel cestino.', $done, 'ept' ), $done );

			case 'untrash':
				self::$restoring = true;
				foreach ( $event['translations'] as $tid ) {
					$tid = (int) $tid;
					if ( get_post( $tid ) && current_user_can( 'delete_post', $tid ) && wp_untrash_post( $tid ) ) {
						delete_post_meta( $tid, self::META_TRASHED );
						++$done;
					}
				}
				self::$restoring = false;
				/* translators: %d: number of pages. */
				return sprintf( _n( '%d traduzione ripristinata.', '%d traduzioni ripristinate.', $done, 'ept' ), $done );

			case 'delete':
				foreach ( $event['trashed'] as $tid ) {
					$tid = (int) $tid;
					if ( get_post( $tid ) && current_user_can( 'delete_post', $tid ) && wp_delete_post( $tid, true ) ) {
						++$done;
					}
				}
				/* translators: %d: number of pages. */
				return sprintf( _n( '%d traduzione eliminata definitivamente.', '%d traduzioni eliminate definitivamente.', $done, 'ept' ), $done );
		}
		return '';
	}

	/**
	 * Unlink a translation from its Italian page (it becomes a standalone page in its language).
	 *
	 * @param int $tid Translated page ID.
	 */
	public static function unlink( int $tid ): void {
		$source = Translations::source_of( $tid );
		$lang   = Translations::language_of( $tid );
		if ( $source ) {
			$map = get_post_meta( $source, Translations::META_MAP, true );
			if ( is_array( $map ) && (int) ( $map[ $lang ] ?? 0 ) === $tid ) {
				unset( $map[ $lang ] );
				update_post_meta( $source, Translations::META_MAP, $map );
				wp_cache_delete( 'map_' . $source, 'ept' );
			}
		}
		delete_post_meta( $tid, Translations::META_SOURCE );
		delete_post_meta( $tid, Translations::META_OUTPUT_HASH );
		update_post_meta( $tid, self::META_ORPHAN, 1 );
	}

	/* --------------------------------------------------------------------
	 * Redirects
	 * ------------------------------------------------------------------ */

	/**
	 * Path of a URL relative to the home ("en/servizi").
	 *
	 * @param string $url URL.
	 */
	public static function path_of( string $url ): string {
		$path = Links::internal_path( $url );
		return null === $path ? '' : trim( $path, '/' );
	}

	/**
	 * Remember old paths of a page and of its published sub-pages after an address change.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 */
	public static function remember_old_paths( int $page_id, string $old_url, string $new_url ): void {
		$old = self::path_of( $old_url );
		$new = self::path_of( $new_url );
		if ( '' === $old || $old === $new || str_contains( $old, '?' ) ) {
			return;
		}
		// A path in use again is no longer an old address.
		delete_metadata( 'post', 0, self::META_OLD, $new, true );
		self::add_old_path( $page_id, $old );
		$queue = array( $page_id );
		while ( $queue ) {
			$children = get_children(
				array(
					'post_parent' => array_shift( $queue ),
					'post_type'   => 'page',
					'post_status' => 'publish',
					'fields'      => 'ids',
				)
			);
			foreach ( array_map( 'intval', $children ) as $child ) {
				$child_path = self::path_of( (string) get_permalink( $child ) );
				if ( str_starts_with( $child_path, $new . '/' ) ) {
					self::add_old_path( $child, $old . substr( $child_path, strlen( $new ) ) );
				}
				$queue[] = $child;
			}
		}
	}

	/**
	 * Store one old path.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $path    Path.
	 */
	private static function add_old_path( int $page_id, string $path ): void {
		$existing = (array) get_post_meta( $page_id, self::META_OLD, false );
		if ( ! in_array( $path, $existing, true ) ) {
			add_post_meta( $page_id, self::META_OLD, $path );
		}
	}

	/**
	 * On a 404, redirect (301) an old address of a translated page to its current one.
	 */
	public static function redirect_old_paths(): void {
		if ( ! is_404() || Editor_Request::active() ) {
			return;
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path    = self::path_of( '/' . ltrim( rawurldecode( (string) wp_parse_url( $request, PHP_URL_PATH ) ), '/' ) );
		$target  = self::redirect_target( $path );
		if ( '' !== $target ) {
			wp_safe_redirect( $target, 301, 'Elementor Page Translator' );
			exit;
		}
	}

	/**
	 * Current URL of the page that used to live at a path ('' when none).
	 *
	 * @param string $path Path relative to the home ("en/servizi").
	 */
	public static function redirect_target( string $path ): string {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return '';
		}
		$pages = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
				'meta_key'         => self::META_OLD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $path, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( ! $pages ) {
			return '';
		}
		$target = (string) get_permalink( (int) $pages[0] );
		return '' !== $target && self::path_of( $target ) !== $path ? $target : '';
	}
}

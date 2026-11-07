<?php
/**
 * Internal links (SPECIFICHE.md, "Link interni").
 *
 * - classify(): recognises the kind of link and proposes the URL in the target language;
 * - final_values(): applies the user's per-link choices (translate / keep / manual URL);
 * - index: table ept_link_index (translated page -> Italian target page, by ID, with state);
 * - relink(): updates only the links of an existing translated page (after a slug or
 *   parent change of a linked page, or when a linked page gets translated).
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Links.
 */
final class Links {

	public const MODE_TRANSLATE = 'translate';
	public const MODE_KEEP      = 'keep';
	public const MODE_MANUAL    = 'manual';

	/**
	 * Link memory: choices made for a link (manual URL or "leave in Italian"), per language,
	 * proposed on other pages with the same link. lang => key => {mode, url, time}.
	 */
	public const MEMORY_OPTION = 'ept_link_memory';

	public const STATE_TRANSLATED = 'translated';
	public const STATE_ITALIAN    = 'italian';
	public const STATE_MANUAL     = 'manual';

	/**
	 * Classify a link and propose its URL in a language.
	 *
	 * @param string   $url     Original URL ('' for atomic query links).
	 * @param string   $lang    Target language.
	 * @param int|null $post_id Target post ID for atomic "query" links.
	 * @return array{type:string, target:int, translation:int, proposed:string, proposed_id:int, translatable:bool, message:string}
	 *   type: empty | anchor | mailto | tel | external | file | home | page | page_untranslated | page_unpublished | post | internal_unknown.
	 */
	public static function classify( string $url, string $lang, ?int $post_id = null ): array {
		$out = array(
			'type'         => 'external',
			'target'       => 0,
			'translation'  => 0,
			'proposed'     => $url,
			'proposed_id'  => 0,
			'translatable' => false,
			'message'      => '',
		);
		$url = trim( $url );

		if ( null !== $post_id ) {
			return self::classify_target( $post_id, $lang, '', '', $out );
		}
		if ( '' === $url ) {
			$out['type'] = 'empty';
			return $out;
		}
		if ( str_starts_with( $url, '#' ) ) {
			$out['type'] = 'anchor';
			return $out;
		}
		if ( preg_match( '/^(mailto|tel):/i', $url, $m ) ) {
			$out['type'] = strtolower( $m[1] );
			return $out;
		}

		$path = self::internal_path( $url );
		if ( null === $path ) {
			return $out; // External.
		}
		$fragment = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
		$query    = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$suffix   = ( '' !== $query ? '?' . $query : '' ) . ( '' !== $fragment ? '#' . $fragment : '' );

		if ( preg_match( '#^/?wp-content/uploads/#', $path ) ) {
			$out['type']    = 'file';
			$out['message'] = __( 'File caricato: resta uguale. Se esiste la versione in questa lingua, scrivi il suo indirizzo nel campo URL manuale.', 'ept' );
			return $out;
		}

		$by_id = '' === trim( $path, '/' ) ? self::page_from_path( $path, $url ) : 0;
		if ( '' === trim( $path, '/' ) && ! $by_id ) {
			$root          = (int) ( Languages::get( $lang )['root_page_id'] ?? 0 );
			$out['type']   = 'home';
			$out['target'] = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			if ( ! $root || 'publish' !== get_post_status( $root ) ) {
				$out['type']    = 'page_unpublished';
				$out['message'] = __( 'La home di questa lingua non è ancora pubblicata: per ora il link resta in italiano.', 'ept' );
				return $out;
			}
			$out['translation']  = $root;
			$out['proposed']     = self::pretty_permalink( $root ) . $suffix;
			$out['proposed_id']  = $root;
			$out['translatable'] = true;
			return $out;
		}

		$target = $by_id ? $by_id : self::page_from_path( $path, $url );
		if ( ! $target ) {
			$out['type']    = 'internal_unknown';
			$out['message'] = __( 'Indirizzo del sito che non corrisponde a una pagina: resta uguale.', 'ept' );
			return $out;
		}
		return self::classify_target( (int) $target, $lang, $suffix, $url, $out );
	}

	/**
	 * Post ID behind an internal path. Works with any permalink setting: url_to_postid()
	 * needs pretty permalinks active (staging copies often have them off), so the page is
	 * also looked up by its hierarchical slug path, and ?page_id= / ?p= links are read directly.
	 *
	 * @param string $path Path relative to the home (e.g. "/servizi/web/").
	 * @param string $url  Original URL (for its query string).
	 */
	public static function page_from_path( string $path, string $url ): int {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		foreach ( array( 'page_id', 'p' ) as $param ) {
			if ( isset( $query[ $param ] ) && is_numeric( $query[ $param ] ) && get_post( (int) $query[ $param ] ) ) {
				return (int) $query[ $param ];
			}
		}
		$slug_path = trim( $path, '/' );
		if ( '' === $slug_path ) {
			return 0;
		}
		$page = get_page_by_path( $slug_path, OBJECT, 'page' );
		if ( $page instanceof WP_Post && 'trash' !== $page->post_status ) {
			return (int) $page->ID;
		}
		$absolute = home_url( '/' . $slug_path . '/' );
		$target   = url_to_postid( $absolute );
		return $target ? (int) $target : (int) url_to_postid( rtrim( $absolute, '/' ) );
	}

	/**
	 * Classify a link to a known post.
	 *
	 * @param int                 $target Target post ID (Italian page, or any post).
	 * @param string              $lang   Language.
	 * @param string              $suffix Query and fragment to keep.
	 * @param string              $url    Original URL.
	 * @param array<string,mixed> $out    Defaults.
	 * @return array<string,mixed>
	 */
	private static function classify_target( int $target, string $lang, string $suffix, string $url, array $out ): array {
		$post          = get_post( $target );
		$out['target'] = $target;
		if ( ! $post instanceof WP_Post ) {
			$out['type'] = 'internal_unknown';
			return $out;
		}
		if ( 'page' !== $post->post_type ) {
			$out['type']    = 'post';
			$out['message'] = __( 'Articolo o contenuto non tradotto dal plugin: resta uguale.', 'ept' );
			return $out;
		}
		// A link to a page already in the target language (e.g. a root page) stays.
		if ( ! Translations::is_source( $target ) ) {
			$out['type'] = 'page';
			return $out;
		}
		$home = 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $target;
		$tid  = Translations::get_id( $target, $lang );
		if ( null === $tid && $home ) {
			$tid = (int) ( Languages::get( $lang )['root_page_id'] ?? 0 );
		}
		if ( ! $tid ) {
			$out['type']    = 'page_untranslated';
			$out['message'] = __( 'Pagina non ancora tradotta: per ora il link resta in italiano. Dopo averla tradotta, il plugin propone di aggiornare questo link.', 'ept' );
			return $out;
		}
		$out['translation'] = $tid;
		if ( 'publish' !== get_post_status( $tid ) ) {
			$out['type']    = 'page_unpublished';
			$out['message'] = __( 'La traduzione di questa pagina non è ancora pubblicata: per ora il link resta in italiano.', 'ept' );
			return $out;
		}
		$out['type']         = $home ? 'home' : 'page';
		$out['proposed']     = self::pretty_permalink( $tid ) . $suffix;
		$out['proposed_id']  = $tid;
		$out['translatable'] = true;
		return $out;
	}

	/**
	 * Path of an internal URL relative to the site home, or null for external URLs.
	 * Accepts absolute (any scheme, with or without www) and root-relative URLs.
	 *
	 * @param string $url URL.
	 */
	public static function internal_path( string $url ): ?string {
		$home      = wp_parse_url( home_url( '/' ) );
		$home_host = preg_replace( '/^www\./', '', strtolower( (string) ( $home['host'] ?? '' ) ) );
		$home_port = isset( $home['port'] ) ? ':' . $home['port'] : '';
		$home_path = rtrim( (string) ( $home['path'] ?? '/' ), '/' ) . '/';

		if ( preg_match( '#^(?:https?:)?//([^/?\#]+)(.*)$#i', $url, $m ) ) {
			$host = preg_replace( '/^www\./', '', strtolower( $m[1] ) );
			if ( $host !== $home_host . $home_port && $host !== $home_host ) {
				return null;
			}
			$rest = $m[2];
		} elseif ( str_starts_with( $url, '/' ) ) {
			$rest = $url;
		} else {
			return null; // Relative paths without a leading slash are ambiguous: leave them.
		}
		$path = (string) wp_parse_url( 'http://x' . ( str_starts_with( $rest, '/' ) ? $rest : '/' . $rest ), PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		if ( ! str_starts_with( rtrim( $path, '/' ) . '/', $home_path ) ) {
			return null; // Outside the WordPress install.
		}
		return '/' . ltrim( substr( $path, strlen( $home_path ) ), '/' );
	}

	/**
	 * Permalink with the pretty structure even for drafts.
	 *
	 * @param int $post_id Page ID.
	 */
	public static function pretty_permalink( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		if ( 'publish' === $post->post_status ) {
			return (string) get_permalink( $post );
		}
		require_once ABSPATH . 'wp-admin/includes/post.php';
		list( $template, $name ) = get_sample_permalink( $post );
		return str_replace( array( '%pagename%', '%postname%' ), $name, $template );
	}

	/**
	 * Saved link choices of a language (stored on the Italian page).
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 * @return array<string,array{mode:string,url:string,orig:string}>
	 */
	public static function choices( int $source_id, string $lang ): array {
		$data = get_post_meta( $source_id, '_ept_links_' . $lang, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save link choices posted by the screen.
	 *
	 * @param int                 $source_id Italian page ID.
	 * @param string              $lang      Language.
	 * @param array<string,mixed> $posted    key => {mode, url}.
	 */
	public static function save_choices( int $source_id, string $lang, array $posted ): void {
		$current = array();
		foreach ( Content::extractor()->extract( Content::elements( $source_id ) )['links'] as $link ) {
			$current[ $link['key'] ] = $link;
		}
		$choices = self::choices( $source_id, $lang );
		$memory  = self::memory( $lang );
		$changed = false;
		foreach ( $posted as $key => $row ) {
			$key = (string) $key;
			if ( ! isset( $current[ $key ] ) || ! is_array( $row ) ) {
				continue;
			}
			$mode = in_array( $row['mode'] ?? '', array( self::MODE_TRANSLATE, self::MODE_KEEP, self::MODE_MANUAL ), true ) ? (string) $row['mode'] : self::MODE_TRANSLATE;
			$url  = self::MODE_MANUAL === $mode ? self::sanitize_url( (string) ( $row['url'] ?? '' ) ) : '';
			if ( self::MODE_MANUAL === $mode && '' === $url ) {
				$mode = self::MODE_TRANSLATE;
			}
			// Link memory: remember a manual URL or "leave in Italian"; forget it when this
			// page goes back to the automatic choice.
			$memory_key = self::memory_key( $current[ $key ] );
			$previous   = $choices[ $key ] ?? null;
			if ( self::MODE_TRANSLATE !== $mode ) {
				$entry = array(
					'mode' => $mode,
					'url'  => $url,
				);
				if ( ! isset( $memory[ $memory_key ] ) || array_intersect_key( $memory[ $memory_key ], $entry ) !== $entry ) {
					$memory[ $memory_key ] = $entry + array( 'time' => time() );
					$changed               = true;
				}
			} elseif ( is_array( $previous ) && self::MODE_TRANSLATE !== ( $previous['mode'] ?? '' ) && isset( $memory[ $memory_key ] )
				&& ( $memory[ $memory_key ]['mode'] ?? '' ) === ( $previous['mode'] ?? '' ) && ( $memory[ $memory_key ]['url'] ?? '' ) === ( $previous['url'] ?? '' ) ) {
				unset( $memory[ $memory_key ] );
				$changed = true;
			}
			$choices[ $key ] = array(
				'mode' => $mode,
				'url'  => $url,
				'orig' => self::original_value( $current[ $key ] ),
			);
		}
		update_post_meta( $source_id, '_ept_links_' . $lang, wp_slash( $choices ) );
		if ( $changed ) {
			$all          = get_option( self::MEMORY_OPTION, array() );
			$all          = is_array( $all ) ? $all : array();
			$all[ $lang ] = $memory;
			update_option( self::MEMORY_OPTION, $all, false );
		}
	}

	/**
	 * Link memory of a language: key => {mode, url, time}.
	 *
	 * @param string $lang Language.
	 * @return array<string,array{mode:string,url:string,time:int}>
	 */
	public static function memory( string $lang ): array {
		$all = get_option( self::MEMORY_OPTION, array() );
		return is_array( $all ) && isset( $all[ $lang ] ) && is_array( $all[ $lang ] ) ? $all[ $lang ] : array();
	}

	/**
	 * Key of a link in the memory: the target post for query links; for internal URLs the
	 * path relative to the site (with query and anchor), so it survives a change of domain;
	 * otherwise the URL itself.
	 *
	 * @param array<string,mixed> $link Extracted link.
	 */
	public static function memory_key( array $link ): string {
		if ( 'query' === ( $link['kind'] ?? '' ) ) {
			return 'post:' . (int) $link['post_id'];
		}
		$url  = trim( (string) ( $link['url'] ?? '' ) );
		$path = self::internal_path( $url );
		if ( null === $path || ! preg_match( '#^(?:https?:)?//|^/#i', $url ) ) {
			return 'url:' . strtolower( $url );
		}
		$parts = wp_parse_url( $url );
		$path  = '/' . trim( $path, '/' );
		$path  = '/' === $path ? '/' : $path . '/';
		return 'path:' . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/**
	 * Sanitise a manual URL (links, anchors, mailto, tel).
	 *
	 * @param string $url URL.
	 */
	public static function sanitize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( str_starts_with( $url, '#' ) ) {
			return '#' . sanitize_html_class( substr( $url, 1 ), '' );
		}
		return esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * Comparable original value of an extracted link.
	 *
	 * @param array<string,mixed> $link Extracted link.
	 */
	private static function original_value( array $link ): string {
		return 'query' === $link['kind'] ? 'post:' . (int) $link['post_id'] : (string) $link['url'];
	}

	/**
	 * Rows of the links table of the screen.
	 *
	 * @param int                               $source_id Italian page ID.
	 * @param string                            $lang      Language.
	 * @param array<int,mixed>                  $elements  Italian elements.
	 * @param array<string,array<string,mixed>> $strings   Extracted strings (for the context).
	 * @return list<array<string,mixed>>
	 */
	public static function screen_rows( int $source_id, string $lang, array $elements, array $strings ): array {
		$extracted = Content::extractor()->extract( $elements );
		$choices   = self::choices( $source_id, $lang );
		$context   = array();
		foreach ( $strings as $s ) {
			if ( ! isset( $context[ $s['element_id'] ] ) && '' !== $s['text'] ) {
				$context[ $s['element_id'] ] = mb_substr( trim( Html_Tools::strip_tags( Html_Tools::strip_noise( $s['text'] ) ) ), 0, 60 );
			}
		}
		$labels = Translation_Service::widget_labels();
		$rows   = array();
		foreach ( $extracted['links'] as $link ) {
			$class  = self::classify_link( $link, $lang );
			$choice = $choices[ $link['key'] ] ?? null;
			$stale  = is_array( $choice ) && ( $choice['orig'] ?? '' ) !== self::original_value( $link );
			$mode   = is_array( $choice ) && ! $stale ? (string) $choice['mode'] : self::MODE_TRANSLATE;
			$rows[] = array(
				'key'          => $link['key'],
				'widget'       => $labels[ $link['widget'] ] ?? $link['widget'],
				'context'      => $context[ $link['element_id'] ] ?? '',
				'kind'         => $link['kind'],
				'original'     => 'query' === $link['kind'] ? (string) get_permalink( (int) $link['post_id'] ) : $link['url'],
				'type'         => $class['type'],
				'proposed'     => $class['proposed'],
				'translatable' => $class['translatable'],
				// Links to site pages: the choice also applies once the page gets translated.
				'choosable'    => in_array( $class['type'], array( 'page', 'home', 'page_untranslated', 'page_unpublished' ), true ),
				'message'      => $class['message'],
				'untranslated' => in_array( $class['type'], array( 'page_untranslated', 'page_unpublished' ), true ) ? $class['target'] : 0,
				'mode'         => $mode,
				'manual'       => is_array( $choice ) && ! $stale ? (string) $choice['url'] : '',
				'changed'      => $stale,
				'suggestion'   => self::suggestion( $link, $lang, is_array( $choice ) && ! $stale ? $choice : null ),
			);
		}
		return $rows;
	}

	/**
	 * Choice remembered from other pages for this link, when this page has not decided yet
	 * (no choice, or the automatic one) and the remembered one is different.
	 *
	 * @param array<string,mixed>       $link   Extracted link.
	 * @param string                    $lang   Language.
	 * @param array<string,string>|null $choice Valid choice of this page.
	 * @return array{mode:string,url:string}|null
	 */
	private static function suggestion( array $link, string $lang, ?array $choice ): ?array {
		if ( is_array( $choice ) && self::MODE_TRANSLATE !== ( $choice['mode'] ?? '' ) ) {
			return null;
		}
		$entry = self::memory( $lang )[ self::memory_key( $link ) ] ?? null;
		if ( ! is_array( $entry ) || ! in_array( $entry['mode'] ?? '', array( self::MODE_KEEP, self::MODE_MANUAL ), true ) ) {
			return null;
		}
		return array(
			'mode' => (string) $entry['mode'],
			'url'  => (string) ( $entry['url'] ?? '' ),
		);
	}

	/**
	 * Classify an extracted link.
	 *
	 * @param array<string,mixed> $link Extracted link.
	 * @param string              $lang Language.
	 * @return array<string,mixed>
	 */
	public static function classify_link( array $link, string $lang ): array {
		$class = 'query' === $link['kind']
			? self::classify( '', $lang, (int) $link['post_id'] )
			: self::classify( (string) $link['url'], $lang );
		/**
		 * Change the proposed URL of a link in a language.
		 *
		 * @param array<string,mixed> $class Classification: type, target, translation, proposed, translatable, message.
		 * @param array<string,mixed> $link  Extracted link (key, kind, url, post_id, widget …).
		 * @param string              $lang  Language code.
		 */
		$filtered = apply_filters( 'ept_link_proposal', $class, $link, $lang );
		return is_array( $filtered ) ? array_merge( $class, $filtered ) : $class;
	}

	/**
	 * Final values for the Rebuilder (key => URL, or post ID for atomic query links)
	 * plus the rows of the link index.
	 *
	 * @param int                  $source_id Italian page ID.
	 * @param string               $lang      Language.
	 * @param array<int,mixed>     $elements  Italian elements.
	 * @return array{values: array<string,mixed>, index: list<array{key:string,target:int,state:string}>}
	 */
	public static function final_values( int $source_id, string $lang, array $elements ): array {
		$choices = self::choices( $source_id, $lang );
		$values  = array();
		$index   = array();
		foreach ( Content::extractor()->extract( $elements )['links'] as $link ) {
			$class  = self::classify_link( $link, $lang );
			$choice = $choices[ $link['key'] ] ?? null;
			if ( is_array( $choice ) && ( $choice['orig'] ?? '' ) !== self::original_value( $link ) ) {
				$choice = null; // The Italian link changed: the old choice no longer applies.
			}
			$mode  = is_array( $choice ) ? (string) $choice['mode'] : self::MODE_TRANSLATE;
			$state = null;
			if ( self::MODE_MANUAL === $mode && '' !== (string) $choice['url'] ) {
				$values[ $link['key'] ] = (string) $choice['url'];
				$state                  = self::STATE_MANUAL;
			} elseif ( self::MODE_TRANSLATE === $mode && $class['translatable'] ) {
				$values[ $link['key'] ] = 'query' === $link['kind'] ? (int) $class['proposed_id'] : (string) $class['proposed'];
				$state                  = self::STATE_TRANSLATED;
			} elseif ( $class['target'] ) {
				$state = self::STATE_ITALIAN;
			}
			if ( null !== $state && ( $class['target'] || $class['translation'] ) ) {
				$index[] = array(
					'key'    => (string) $link['key'],
					'target' => (int) ( $class['target'] ? $class['target'] : Translations::source_of( (int) $class['translation'] ) ),
					'state'  => $state,
				);
			}
		}
		return array(
			'values' => $values,
			'index'  => $index,
		);
	}

	/**
	 * Replace the index rows of a translated page.
	 *
	 * @param int                                              $page_id Translated page ID.
	 * @param string                                           $lang    Language.
	 * @param list<array{key:string,target:int,state:string}> $rows    Rows.
	 */
	public static function write_index( int $page_id, string $lang, array $rows ): void {
		global $wpdb;
		$table = Installer::tables()['link_index'];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table.
		$wpdb->delete( $table, array( 'source_post_id' => $page_id ), array( '%d' ) );
		foreach ( $rows as $row ) {
			if ( $row['target'] <= 0 ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'source_post_id' => $page_id,
					'target_post_id' => $row['target'],
					'lang'           => $lang,
					'link_key'       => substr( $row['key'], 0, 191 ),
					'state'          => $row['state'],
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
		}
		// phpcs:enable
	}

	/**
	 * Translated pages of a language linking to Italian pages, optionally filtered by state.
	 *
	 * @param list<int>   $targets Italian target page IDs.
	 * @param string      $lang    Language.
	 * @param string|null $state   State filter.
	 * @return list<int> Translated page IDs.
	 */
	public static function linking_pages( array $targets, string $lang, ?string $state = null ): array {
		global $wpdb;
		$targets = array_values( array_filter( array_map( 'intval', $targets ) ) );
		if ( ! $targets ) {
			return array();
		}
		$table = Installer::tables()['link_index'];
		$in    = implode( ',', array_fill( 0, count( $targets ), '%d' ) );
		$args  = array_merge( $targets, array( $lang ) );
		$where = '';
		if ( null !== $state ) {
			$where  = ' AND state = %s';
			$args[] = $state;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, placeholders built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT source_post_id FROM {$table} WHERE target_post_id IN ($in) AND lang = %s{$where}", $args ) );
		// phpcs:enable
		return array_map( 'intval', $ids );
	}

	/**
	 * Update only the links of an existing translated page, keeping texts and any manual
	 * change made in Elementor. Works without a logged-in user (slug changes may come from
	 * WP-CLI or other plugins); the page CSS and cache are dropped and rebuilt by Elementor.
	 *
	 * @param int $page_id Translated page ID.
	 * @return bool Whether something changed.
	 */
	public static function relink( int $page_id ): bool {
		$source_id = Translations::source_of( $page_id );
		$lang      = Translations::language_of( $page_id );
		if ( ! $source_id || Translations::is_source( $page_id ) ) {
			return false;
		}
		$final   = self::final_values( $source_id, $lang, Content::elements( $source_id ) );
		$current = Content::elements( $page_id );

		// The Rebuilder works on the translated page itself (same keys), so hrefs are matched
		// against the URLs currently in its texts.
		$values = array();
		$found  = array();
		foreach ( Content::extractor()->extract( $current )['links'] as $link ) {
			$found[ $link['key'] ] = $link;
		}
		$italian = array();
		foreach ( Content::extractor()->extract( Content::elements( $source_id ) )['links'] as $link ) {
			$italian[ $link['key'] ] = $link;
		}
		foreach ( $found as $key => $link ) {
			$target = $final['values'][ $key ] ?? ( isset( $italian[ $key ] ) ? self::original_value( $italian[ $key ] ) : null );
			if ( null === $target ) {
				continue;
			}
			if ( is_string( $target ) && str_starts_with( $target, 'post:' ) ) {
				$target = (int) substr( $target, 5 );
			}
			$now = self::original_value( $link );
			if ( ( is_int( $target ) ? 'post:' . $target : $target ) !== $now ) {
				$values[ $key ] = $target;
			}
		}
		self::write_index( $page_id, $lang, $final['index'] );
		if ( ! $values ) {
			return false;
		}
		$result = Content::rebuilder()->apply( $current, array(), $values );

		$was_clean = ! Translations::has_manual_changes( $page_id );
		Content::write_elements( $page_id, $result['elements'] );
		if ( $was_clean ) {
			update_post_meta( $page_id, Translations::META_OUTPUT_HASH, Translations::data_hash( $page_id ) );
		}
		do_action( 'ept_after_sync', $page_id, $source_id, $lang, false );
		Translation_Service::purge_cache( $page_id );
		return true;
	}
}

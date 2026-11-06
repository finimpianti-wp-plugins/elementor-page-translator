<?php
/**
 * Keeps links between translated pages up to date: when a translated page changes slug
 * or parent (its URL changes), every page of the same language linking to it, or to one
 * of its sub-pages, gets its links updated (texts and manual changes untouched).
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Link updater.
 */
final class Link_Updater {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'post_updated', array( self::class, 'on_update' ), 20, 3 );
	}

	/**
	 * After a page is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public static function on_update( $post_id, $after, $before ): void {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post || 'page' !== $after->post_type ) {
			return;
		}
		if ( $after->post_name === $before->post_name && (int) $after->post_parent === (int) $before->post_parent ) {
			return;
		}
		if ( Translations::is_source( (int) $post_id ) ) {
			return; // Italian pages: handled in F8 (slug change proposal).
		}
		self::update_links_to( (int) $post_id );
	}

	/**
	 * Update the links pointing to a translated page and to its sub-pages.
	 *
	 * @param int $page_id Translated page ID whose URL changed.
	 * @return list<int> Pages updated.
	 */
	public static function update_links_to( int $page_id ): array {
		$lang  = Translations::language_of( $page_id );
		$pages = array_merge(
			array( $page_id ),
			array_map(
				'intval',
				get_children(
					array(
						'post_parent' => $page_id,
						'post_type'   => 'page',
						'fields'      => 'ids',
						'post_status' => 'any',
					)
				)
			)
		);
		// Whole subtree (children of children…).
		$queue = $pages;
		while ( $queue ) {
			$children = get_children(
				array(
					'post_parent' => array_shift( $queue ),
					'post_type'   => 'page',
					'fields'      => 'ids',
					'post_status' => 'any',
				)
			);
			foreach ( array_map( 'intval', $children ) as $child ) {
				if ( ! in_array( $child, $pages, true ) ) {
					$pages[] = $child;
					$queue[] = $child;
				}
			}
		}

		$targets = array();
		foreach ( $pages as $id ) {
			$source = Translations::source_of( $id );
			if ( $source ) {
				$targets[] = $source;
			}
		}
		// A root page is the translation of the home page.
		$root_lang = Root_Pages::root_language( $page_id );
		if ( null !== $root_lang && 'page' === get_option( 'show_on_front' ) ) {
			$targets[] = (int) get_option( 'page_on_front' );
		}

		$updated = array();
		foreach ( Links::linking_pages( $targets, $lang ) as $linking ) {
			if ( $linking !== $page_id && Links::relink( $linking ) ) {
				$updated[] = $linking;
			}
		}
		return $updated;
	}
}

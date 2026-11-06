<?php
/**
 * Translation memory (table {prefix}ept_memory): the same normalised Italian text
 * already translated elsewhere becomes a suggestion. One entry per text and language;
 * the latest translation wins.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Memory.
 */
final class Memory {

	/**
	 * Store translations of one page.
	 *
	 * @param string                                       $lang      Language.
	 * @param list<array{text:string,translation:string}> $entries   Original text and translation.
	 * @param int                                          $source_id Italian page ID.
	 */
	public static function store( string $lang, array $entries, int $source_id ): void {
		global $wpdb;
		$table = Installer::tables()['memory'];
		$now   = current_time( 'mysql', true );
		foreach ( $entries as $entry ) {
			$text        = (string) $entry['text'];
			$translation = (string) $entry['translation'];
			if ( '' === trim( $text ) || '' === trim( $translation ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table (name from $wpdb->prefix), upsert.
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (source_hash, lang, source_text, translation, source_post_id, uses, updated_at)
					 VALUES (%s, %s, %s, %s, %d, 1, %s)
					 ON DUPLICATE KEY UPDATE translation = VALUES(translation), source_post_id = VALUES(source_post_id), uses = uses + 1, updated_at = VALUES(updated_at)",
					Extractor::hash( $text ),
					$lang,
					$text,
					$translation,
					$source_id,
					$now
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Suggestions for a set of text hashes.
	 *
	 * @param string       $lang   Language.
	 * @param list<string> $hashes Hashes (Extractor::hash()).
	 * @return array<string,string> hash => translation
	 */
	public static function suggestions( string $lang, array $hashes ): array {
		global $wpdb;
		$hashes = array_values( array_unique( array_filter( $hashes ) ) );
		if ( ! $hashes ) {
			return array();
		}
		$table        = Installer::tables()['memory'];
		$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- custom table, placeholders built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT source_hash, translation FROM {$table} WHERE lang = %s AND source_hash IN ($placeholders)", array_merge( array( $lang ), $hashes ) ),
			ARRAY_A
		);
		// phpcs:enable
		return array_column( (array) $rows, 'translation', 'source_hash' );
	}
}

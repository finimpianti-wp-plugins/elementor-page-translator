<?php
/**
 * Activation, deactivation, database tables and migrations.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	/**
	 * Bump when the schema or stored data change; add a step to migrations().
	 */
	public const DB_VERSION = 2;

	public const DB_VERSION_OPTION = 'ept_db_version';

	/**
	 * Activation hook. Never deletes anything.
	 */
	public static function activate(): void {
		self::maybe_upgrade();
		// Languages that were active before a deactivation get their root pages checked again.
		foreach ( array_keys( Languages::active() ) as $code ) {
			Root_Pages::ensure( (string) $code );
		}
	}

	/**
	 * Deactivation hook. Translated pages, options and tables stay untouched.
	 */
	public static function deactivate(): void {
		wp_cache_delete( 'ept_reserved_prefixes', 'ept' );
	}

	/**
	 * Run pending migrations. Called on every load; cheap when up to date.
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		foreach ( self::migrations() as $version => $callback ) {
			if ( $installed < $version ) {
				$callback();
			}
		}
		if ( false === get_option( Languages::OPTION ) ) {
			add_option(
				Languages::OPTION,
				array(
					'default'   => Languages::initial_default(),
					'languages' => array(),
				),
				'',
				true
			);
		}
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		Languages::flush_cache();
	}

	/**
	 * Data migrations keyed by the DB version that introduces them.
	 *
	 * @return array<int,callable>
	 */
	private static function migrations(): array {
		return array(
			1 => static function (): void {
				// Initial schema: tables are created by create_tables().
			},
			2 => static function (): void {
				// Link index gains "state" (translated | italian | manual): created by dbDelta in create_tables().
			},
		);
	}

	/**
	 * Table names.
	 *
	 * @return array{memory: string, link_index: string}
	 */
	public static function tables(): array {
		global $wpdb;
		return array(
			'memory'     => $wpdb->prefix . 'ept_memory',
			'link_index' => $wpdb->prefix . 'ept_link_index',
		);
	}

	/**
	 * Create or update tables with dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = self::tables();

		// Translation memory: one suggestion per normalised source text and language.
		dbDelta(
			"CREATE TABLE {$t['memory']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_hash char(40) NOT NULL,
  lang varchar(20) NOT NULL,
  source_text longtext NOT NULL,
  translation longtext NOT NULL,
  source_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  uses int(10) unsigned NOT NULL DEFAULT 1,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash_lang (source_hash,lang),
  KEY lang (lang)
) $charset;"
		);

		// Link index: which translated page (source_post_id) links to which Italian page (target_post_id),
		// and whether the link points to its translation, still to the Italian page, or to a manual URL.
		// IDs only, never URLs.
		dbDelta(
			"CREATE TABLE {$t['link_index']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_post_id bigint(20) unsigned NOT NULL,
  target_post_id bigint(20) unsigned NOT NULL,
  lang varchar(20) NOT NULL,
  link_key varchar(191) NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'translated',
  PRIMARY KEY  (id),
  UNIQUE KEY source_link (source_post_id,link_key),
  KEY target (target_post_id,lang)
) $charset;"
		);
	}
}

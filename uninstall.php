<?php
/**
 * Uninstall: removes plugin data only when the owner enabled the option.
 * Translated pages (and root pages) are never deleted.
 *
 * @package EPT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ept_settings = get_option( 'ept_settings', array() );
if ( empty( $ept_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-off cleanup.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ept_memory" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ept_link_index" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_ept_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'ept_' ) . '%', $wpdb->esc_like( '_transient_ept_' ) . '%', $wpdb->esc_like( '_transient_timeout_ept_' ) . '%' ) );
// phpcs:enable

wp_cache_flush();

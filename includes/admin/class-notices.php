<?php
/**
 * Admin notices that survive a redirect (stored per user for one minute).
 *
 * @package EPT
 */

namespace EPT\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Notices.
 */
final class Notices {

	/**
	 * Hook display.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	/**
	 * Queue a notice for the current user.
	 *
	 * @param string $type    success|warning|error|info.
	 * @param string $message Plain text message.
	 */
	public static function add( string $type, string $message ): void {
		$user = get_current_user_id();
		if ( ! $user ) {
			return;
		}
		$key     = 'ept_notices_' . $user;
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();
		$entry   = array(
			'type'    => $type,
			'message' => $message,
		);
		if ( ! in_array( $entry, $notices, true ) ) {
			$notices[] = $entry;
		}
		set_transient( $key, $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Print and clear queued notices.
	 */
	public static function render(): void {
		$key     = 'ept_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			$type = in_array( $notice['type'], array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( $notice['message'] )
			);
		}
	}
}

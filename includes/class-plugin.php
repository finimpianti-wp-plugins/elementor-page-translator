<?php
/**
 * Plugin bootstrap: wires the modules.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin.
 */
final class Plugin {

	/**
	 * Boot on plugins_loaded.
	 */
	public static function boot(): void {
		load_plugin_textdomain( 'ept', false, dirname( plugin_basename( EPT_FILE ) ) . '/languages' );

		Installer::maybe_upgrade();
		Root_Pages::init();

		if ( is_admin() ) {
			Admin\Notices::init();
			Admin\Settings_Page::init();
		}
	}
}

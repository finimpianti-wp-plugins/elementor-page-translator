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
		Rest\Translation_Controller::init();

		if ( is_admin() ) {
			Admin\Notices::init();
			Admin\Settings_Page::init();
			Admin\Census_Page::init();
			Admin\Pages_List::init();
			Admin\Translation_Screen::init();
			Admin\Editor_Notice::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ept census', Cli\Census_Command::class );
		}
	}
}

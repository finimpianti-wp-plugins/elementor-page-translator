<?php
/**
 * Plugin Name:       Elementor Page Translator
 * Description:       Traduce le pagine Elementor e i template UAE in copie per lingua (/en/, /de/…) con SEO multilingua.
 * Version:           0.16.1
 * Update URI:        https://github.com/finimpianti-wp-plugins/elementor-page-translator
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Finimpianti
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ept
 * Domain Path:       /languages
 *
 * @package EPT
 */

defined( 'ABSPATH' ) || exit;

define( 'EPT_VERSION', '0.16.1' );
define( 'EPT_FILE', __FILE__ );
define( 'EPT_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPT_URL', plugin_dir_url( __FILE__ ) );

require_once EPT_DIR . 'includes/class-autoloader.php';
EPT\Autoloader::register( 'EPT\\', EPT_DIR . 'includes/' );
require_once EPT_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( EPT\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EPT\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( EPT\Plugin::class, 'boot' ) );

// Aggiornamenti da GitHub: ogni release del repository (con lo zip del plugin) compare in
// Bacheca -> Aggiornamenti e si installa con un clic. Il repository si può cambiare definendo
// EPT_UPDATE_REPO in wp-config.php; APGR_GITHUB_TOKEN (facoltativo) serve solo per repository
// privati o per superare i limiti di richieste di GitHub.
if ( ! defined( 'EPT_UPDATE_REPO' ) ) {
	define( 'EPT_UPDATE_REPO', 'https://github.com/finimpianti-wp-plugins/elementor-page-translator/' );
}
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
$ept_aggiornamenti = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( EPT_UPDATE_REPO, __FILE__, 'elementor-page-translator' );
$ept_aggiornamenti->getVcsApi()->enableReleaseAssets( '/^elementor\-page\-translator-[0-9][0-9.]*\.zip$/' );
if ( defined( 'APGR_GITHUB_TOKEN' ) && APGR_GITHUB_TOKEN ) {
	$ept_aggiornamenti->setAuthentication( APGR_GITHUB_TOKEN );
}

<?php
/**
 * Plugin Name:       Elementor Page Translator
 * Description:       Traduce le pagine Elementor e i template UAE in copie per lingua (/en/, /de/…) con SEO multilingua.
 * Version:           0.13.1
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

define( 'EPT_VERSION', '0.13.1' );
define( 'EPT_FILE', __FILE__ );
define( 'EPT_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPT_URL', plugin_dir_url( __FILE__ ) );

require_once EPT_DIR . 'includes/class-autoloader.php';
EPT\Autoloader::register( 'EPT\\', EPT_DIR . 'includes/' );
require_once EPT_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( EPT\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( EPT\Installer::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( EPT\Plugin::class, 'boot' ) );

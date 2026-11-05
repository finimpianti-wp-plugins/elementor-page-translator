<?php
/**
 * Minimal autoloader following WordPress file naming.
 *
 * EPT\Foo_Bar        => includes/class-foo-bar.php
 * EPT\Admin\Foo_Bar  => includes/admin/class-foo-bar.php
 *
 * Kept free of WordPress calls so pure classes can be loaded in unit tests.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * Autoloader.
 */
final class Autoloader {

	/**
	 * Register an autoloader for a namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix with trailing backslash.
	 * @param string $base_dir Directory with trailing slash.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				$parts    = explode( '\\', $relative );
				$class    = array_pop( $parts );
				$dir      = '';
				foreach ( $parts as $part ) {
					$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
				}
				$file = $base_dir . $dir . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}

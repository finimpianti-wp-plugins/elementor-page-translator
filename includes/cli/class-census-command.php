<?php
/**
 * WP-CLI: wp ept census
 *
 * @package EPT
 */

namespace EPT\Cli;

use EPT\Census\Service;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Census command.
 */
final class Census_Command {

	/**
	 * Writes the Elementor census report (widgets, atomic elements, fields) as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Output file. Default: print to standard output.
	 *
	 * [--include-content]
	 * : Also include the full Elementor JSON of every document.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ept census --file=censimento.json --include-content
	 *
	 * @param list<string>          $args       Positional arguments.
	 * @param array<string,string>  $assoc_args Options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		$report = Service::build( ! empty( $assoc_args['include-content'] ) );
		$json   = (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( empty( $assoc_args['file'] ) ) {
			WP_CLI::line( $json );
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI output file chosen by the user.
		if ( false === file_put_contents( $assoc_args['file'], $json ) ) {
			WP_CLI::error( 'Cannot write ' . $assoc_args['file'] );
		}
		WP_CLI::success( sprintf( '%d documents, %d element types written to %s', count( $report['census']['documents'] ), count( $report['census']['types'] ), $assoc_args['file'] ) );
	}
}

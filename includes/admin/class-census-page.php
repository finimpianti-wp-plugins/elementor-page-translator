<?php
/**
 * Tools → Censimento Elementor: read-only report of widgets, atomic elements and fields.
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Census\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Census page.
 */
final class Census_Page {

	public const SLUG = 'ept-census';
	public const CAP  = 'manage_options';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_ept_census_download', array( self::class, 'handle_download' ) );
	}

	/**
	 * Menu entry under Tools.
	 */
	public static function menu(): void {
		add_management_page(
			__( 'Censimento Elementor', 'ept' ),
			__( 'Censimento Elementor', 'ept' ),
			self::CAP,
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the page with a summary and the download form.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 'ept' ) );
		}
		$report  = Service::build( false );
		$summary = $report['summary'];
		$types   = $report['census']['types'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Censimento Elementor', 'ept' ); ?></h1>
			<p><?php esc_html_e( 'Legge il contenuto Elementor di tutte le pagine, dei template UAE e degli altri contenuti costruiti con Elementor, e produce l\'elenco dei widget e degli elementi atomici usati con i loro campi. Non modifica nulla.', 'ept' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ept_census_download">
				<?php wp_nonce_field( 'ept_census_download' ); ?>
				<p>
					<label>
						<input type="checkbox" name="include_content" value="1" checked>
						<?php esc_html_e( 'Includi il contenuto completo delle pagine (serve a usarle come casi di test; il file sarà più grande)', 'ept' ); ?>
					</label>
				</p>
				<?php submit_button( __( 'Scarica il report (JSON)', 'ept' ), 'primary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Riepilogo', 'ept' ); ?></h2>
			<table class="widefat striped" style="max-width:40em">
				<thead><tr><th scope="col"><?php esc_html_e( 'Tipo di contenuto', 'ept' ); ?></th><th scope="col"><?php esc_html_e( 'Documenti Elementor', 'ept' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $summary['documents_by_post_type'] as $post_type => $count ) : ?>
					<tr><td><code><?php echo esc_html( $post_type ); ?></code></td><td><?php echo esc_html( (string) $count ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Elementi e widget usati', 'ept' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Tipo', 'ept' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Usi', 'ept' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Documenti', 'ept' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Elemento atomico', 'ept' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Campi che sembrano testo', 'ept' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $types as $key => $type ) : ?>
					<?php
					$text_fields = array();
					foreach ( (array) $type['settings'] as $path => $setting ) {
						if ( $setting['fallback_text'] > 0 ) {
							$text_fields[] = $path;
						}
					}
					$atomic = ! empty( $report['registry'][ $key ]['atomic'] );
					?>
					<tr>
						<td><code><?php echo esc_html( $key ); ?></code></td>
						<td><?php echo esc_html( (string) $type['count'] ); ?></td>
						<td><?php echo esc_html( (string) count( $type['documents'] ) ); ?></td>
						<td><?php echo $atomic ? esc_html__( 'sì', 'ept' ) : '—'; ?></td>
						<td><?php echo esc_html( implode( ', ', $text_fields ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Stream the JSON report as a download.
	 */
	public static function handle_download(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per eseguire questa operazione.', 'ept' ), 403 );
		}
		check_admin_referer( 'ept_census_download' );
		$include = ! empty( $_POST['include_content'] );

		$report   = Service::build( $include );
		$host     = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$filename = 'censimento-elementor-' . $host . '-' . gmdate( 'Ymd-Hi' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file download, not HTML.
		exit;
	}
}

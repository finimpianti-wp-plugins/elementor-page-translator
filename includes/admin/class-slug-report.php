<?php
/**
 * Duplicate slugs: notice after the automatic fix done by the update, and a check in the
 * settings page (Traduzioni) with a button to fix the translations still sharing a slug.
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Languages;
use EPT\Slugs;

defined( 'ABSPATH' ) || exit;

/**
 * Slug report.
 */
final class Slug_Report {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_post_ept_fix_slugs', array( self::class, 'handle_fix' ) );
		add_action( 'admin_post_ept_dismiss_slug_report', array( self::class, 'handle_dismiss' ) );
	}

	/**
	 * Human name of a post type.
	 *
	 * @param string $type Post type.
	 */
	private static function type_name( string $type ): string {
		return 'page' === $type ? __( 'pagina', 'ept' ) : __( 'template UAE', 'ept' );
	}

	/**
	 * Language label ("EN"), or "IT" for the default language.
	 *
	 * @param string $lang Code.
	 */
	private static function lang_label( string $lang ): string {
		return strtoupper( '' !== $lang ? $lang : Languages::default_code() );
	}

	/**
	 * Title with link to edit it (escaped HTML).
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Title.
	 */
	private static function link( int $id, string $title ): string {
		$label = ( '' !== $title ? $title : '#' . $id ) . ' (#' . $id . ')';
		$edit  = get_edit_post_link( $id );
		return $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
	}

	/**
	 * List of changes (escaped HTML).
	 *
	 * @param array<int,array<string,mixed>> $changes Changes.
	 */
	private static function changes_list( array $changes ): string {
		$items = array();
		foreach ( $changes as $c ) {
			$items[] = '<li>' . self::link( (int) $c['id'], (string) $c['title'] ) . ' — ' . esc_html( self::type_name( (string) $c['type'] ) . ', ' . self::lang_label( (string) $c['lang'] ) ) . ': <code>' . esc_html( (string) $c['old'] ) . '</code> → <code>' . esc_html( (string) $c['new'] ) . '</code></li>';
		}
		return '<ul class="ul-disc">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Notice after the automatic fix (until dismissed).
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$report = get_option( Slugs::REPORT_OPTION );
		if ( ! is_array( $report ) || empty( $report['changes'] ) ) {
			return;
		}
		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=ept_dismiss_slug_report' ), 'ept_dismiss_slug_report' );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Traduzioni', 'ept' ) . ':</strong> ';
		echo esc_html(
			sprintf(
				/* translators: %d: number of translations. */
				_n( '%d copia tradotta aveva lo stesso slug di un altro contenuto (per esempio l\'originale italiano): ha ricevuto uno slug unico con il suffisso della lingua.', '%d copie tradotte avevano lo stesso slug di un altro contenuto (per esempio l\'originale italiano): hanno ricevuto uno slug unico con il suffisso della lingua.', count( $report['changes'] ), 'ept' ),
				count( $report['changes'] )
			)
		);
		echo ' ' . esc_html__( 'Per i template UAE non cambia nulla per i visitatori; per le pagine il vecchio indirizzo porta a quello nuovo.', 'ept' ) . '</p>';
		echo self::changes_list( $report['changes'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in changes_list().
		echo '<p><a class="button" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Ho capito', 'ept' ) . '</a></p></div>';
	}

	/**
	 * Section of the settings page: current duplicates and fix button.
	 */
	public static function render_section(): void {
		$groups = Slugs::duplicates();
		echo '<h2>' . esc_html__( 'Slug duplicati', 'ept' ) . '</h2>';
		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'Nessun contenuto (pagine con lo stesso genitore, template UAE) condivide lo slug con un altro: tutto in ordine.', 'ept' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Questi contenuti hanno lo stesso slug: WordPress può aprire quello sbagliato (per esempio l\'anteprima di Elementor di un template carica la sua copia tradotta e resta su "Caricamento").', 'ept' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Slug', 'ept' ) . '</th><th>' . esc_html__( 'Tipo', 'ept' ) . '</th><th>' . esc_html__( 'Contenuti', 'ept' ) . '</th></tr></thead><tbody>';
		$fixable = 0;
		foreach ( $groups as $g ) {
			$items = array();
			foreach ( $g['posts'] as $p ) {
				$items[]  = self::link( $p['id'], $p['title'] ) . ' — ' . esc_html( self::lang_label( $p['lang'] ) . ', ' . $p['status'] ) . ( $p['translation'] ? ' <em>' . esc_html__( '(copia tradotta: si può sistemare)', 'ept' ) . '</em>' : '' );
				$fixable += $p['translation'] ? 1 : 0;
			}
			echo '<tr><td><code>' . esc_html( $g['slug'] ) . '</code></td><td>' . esc_html( self::type_name( $g['type'] ) ) . '</td><td>' . implode( '<br>', $items ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</tbody></table>';
		if ( $fixable ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ept_fix_slugs">';
			wp_nonce_field( 'ept_fix_slugs' );
			echo '<p>';
			submit_button( __( 'Dai uno slug unico alle copie tradotte', 'ept' ), 'primary', 'submit', false );
			echo ' <span class="description">' . esc_html__( 'Gli originali italiani restano come sono; le copie tradotte ricevono il suffisso della lingua (es. "-en").', 'ept' ) . '</span></p></form>';
		} else {
			echo '<p class="description">' . esc_html__( 'Nessuna di queste è una copia tradotta: cambia a mano lo slug di uno dei contenuti (Modifica rapida nell\'elenco).', 'ept' ) . '</p>';
		}
	}

	/**
	 * Fix button.
	 */
	public static function handle_fix(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per questa operazione.', 'ept' ), 403 );
		}
		check_admin_referer( 'ept_fix_slugs' );
		$changes = Slugs::fix_duplicates();
		if ( $changes ) {
			update_option(
				Slugs::REPORT_OPTION,
				array(
					'time'    => time(),
					'changes' => $changes,
				),
				false
			);
		}
		Notices::add(
			'success',
			sprintf(
				/* translators: %d: number of translations. */
				_n( 'Slug sistemato per %d copia tradotta.', 'Slug sistemato per %d copie tradotte.', count( $changes ), 'ept' ),
				count( $changes )
			)
		);
		wp_safe_redirect( Settings_Page::url() );
		exit;
	}

	/**
	 * "Ho capito" on the notice.
	 */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per questa operazione.', 'ept' ), 403 );
		}
		check_admin_referer( 'ept_dismiss_slug_report' );
		delete_option( Slugs::REPORT_OPTION );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}
}

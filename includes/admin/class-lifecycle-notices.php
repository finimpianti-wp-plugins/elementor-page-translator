<?php
/**
 * Admin notices of the lifecycle events (slug / parent changes, trash, restore, delete of
 * Italian pages): each one proposes an action applied only after confirmation.
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Languages;
use EPT\Lifecycle;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle notices.
 */
final class Lifecycle_Notices {

	public const ACTION = 'ept_lifecycle';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Print the pending events.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		foreach ( Lifecycle::events() as $event ) {
			if ( is_array( $event ) && isset( $event['id'], $event['type'] ) ) {
				self::render_event( $event );
			}
		}
	}

	/**
	 * Language name.
	 *
	 * @param string $lang Code.
	 */
	private static function lang_name( string $lang ): string {
		return (string) ( Languages::get( $lang )['name'] ?? strtoupper( $lang ) );
	}

	/**
	 * Title of a page with a link to edit it (escaped HTML).
	 *
	 * @param int $id Page ID.
	 */
	private static function page_link( int $id ): string {
		$title = get_the_title( $id );
		$title = '' !== $title ? $title : '#' . $id;
		$edit  = get_edit_post_link( $id );
		return $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
	}

	/**
	 * List of translations "Language: title" (escaped HTML).
	 *
	 * @param array<string,int> $ids lang => ID.
	 */
	private static function translations_list( array $ids ): string {
		$items = array();
		foreach ( $ids as $lang => $id ) {
			$items[] = '<li>' . esc_html( self::lang_name( (string) $lang ) ) . ': ' . self::page_link( (int) $id ) . '</li>';
		}
		return '<ul class="ul-disc">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * One notice.
	 *
	 * @param array<string,mixed> $event Event.
	 */
	private static function render_event( array $event ): void {
		$id     = (string) $event['id'];
		$source = (int) ( $event['source'] ?? 0 );
		$title  = 'delete' === $event['type'] ? (string) ( $event['title'] ?? '' ) : get_the_title( $source );
		$body   = '';
		$fields = '';
		$apply  = '';
		$other  = array();

		switch ( $event['type'] ) {
			case 'slug':
				/* translators: 1: page title, 2: old slug, 3: new slug. */
				$body = sprintf( esc_html__( 'Hai cambiato lo slug della pagina "%1$s" da "%2$s" a "%3$s". Vuoi aggiornare anche lo slug delle traduzioni? Puoi modificare gli slug proposti.', 'ept' ), esc_html( $title ), esc_html( (string) $event['old'] ), esc_html( (string) $event['new'] ) );
				$rows = '';
				foreach ( (array) $event['langs'] as $lang => $row ) {
					$input = 'ept-slug-' . sanitize_key( $id . '-' . $lang );
					$rows .= sprintf(
						'<tr><td><label for="%1$s">%2$s</label></td><td><code>%3$s</code></td><td><input type="text" class="regular-text" id="%1$s" name="slug[%4$s]" value="%5$s"></td></tr>',
						esc_attr( $input ),
						esc_html( self::lang_name( (string) $lang ) ),
						esc_html( (string) $row['current'] ),
						esc_attr( (string) $lang ),
						esc_attr( (string) $row['proposed'] )
					);
				}
				$fields = '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Lingua', 'ept' ) . '</th><th>' . esc_html__( 'Slug attuale', 'ept' ) . '</th><th>' . esc_html__( 'Nuovo slug', 'ept' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
				$apply  = __( 'Aggiorna gli slug', 'ept' );
				$other  = array( 'dismiss' => __( 'Lascia invariati', 'ept' ) );
				break;

			case 'parent':
				/* translators: %s: page title. */
				$body  = sprintf( esc_html__( 'Hai spostato la pagina "%s" sotto un\'altra pagina. Vuoi spostare anche le traduzioni?', 'ept' ), esc_html( $title ) );
				$items = '';
				$can   = false;
				foreach ( (array) $event['langs'] as $lang => $row ) {
					if ( ! empty( $row['ok'] ) ) {
						$can    = true;
						$target = (int) $row['proposed'];
						$items .= '<li>' . esc_html( self::lang_name( (string) $lang ) ) . ': ' . self::page_link( (int) $row['target'] ) . ' &rarr; '
							/* translators: %s: title of the new parent page. */
							. ( $target ? sprintf( esc_html__( 'sotto "%s"', 'ept' ), self::page_link( $target ) ) : esc_html__( 'primo livello', 'ept' ) ) . '</li>';
					} else {
						$items .= '<li>' . esc_html( self::lang_name( (string) $lang ) ) . ': ' . self::page_link( (int) $row['target'] ) . ' &mdash; ' . esc_html( (string) $row['message'] ) . '</li>';
					}
				}
				$fields = '<ul class="ul-disc">' . $items . '</ul>';
				$apply  = $can ? __( 'Sposta le traduzioni', 'ept' ) : '';
				$other  = array( 'dismiss' => $can ? __( 'Lasciale dove sono', 'ept' ) : __( 'Ho capito', 'ept' ) );
				break;

			case 'trash':
				/* translators: %s: page title. */
				$body   = sprintf( esc_html__( 'Hai spostato nel cestino la pagina "%s". Vuoi spostare nel cestino anche le sue traduzioni?', 'ept' ), esc_html( $title ) );
				$fields = self::translations_list( (array) $event['translations'] );
				$apply  = __( 'Sì, sposta nel cestino anche le traduzioni', 'ept' );
				$other  = array( 'unlink' => __( 'No, scollegale (diventano pagine indipendenti)', 'ept' ) );
				break;

			case 'untrash':
				/* translators: %s: page title. */
				$body   = sprintf( esc_html__( 'Hai ripristinato la pagina "%s". Vuoi ripristinare anche le traduzioni spostate nel cestino insieme a lei?', 'ept' ), esc_html( $title ) );
				$fields = self::translations_list( (array) $event['translations'] );
				$apply  = __( 'Ripristina le traduzioni', 'ept' );
				$other  = array( 'dismiss' => __( 'Lasciale nel cestino', 'ept' ) );
				break;

			case 'delete':
				/* translators: %s: page title. */
				$body = sprintf( esc_html__( 'Hai eliminato definitivamente la pagina "%s".', 'ept' ), esc_html( $title ) );
				if ( ! empty( $event['trashed'] ) ) {
					$fields .= '<p>' . esc_html__( 'Queste traduzioni sono nel cestino. Vuoi eliminarle definitivamente? L\'operazione non si può annullare.', 'ept' ) . '</p>' . self::translations_list( (array) $event['trashed'] );
					$apply   = __( 'Elimina definitivamente le traduzioni', 'ept' );
					$other   = array( 'dismiss' => __( 'Lasciale nel cestino', 'ept' ) );
				} else {
					$other = array( 'dismiss' => __( 'Ho capito', 'ept' ) );
				}
				if ( ! empty( $event['orphans'] ) ) {
					$fields .= '<p>' . esc_html__( 'Queste traduzioni restano pubblicate come erano e sono segnate come "orfane" (non sono più collegate a una pagina italiana):', 'ept' ) . '</p>' . self::translations_list( (array) $event['orphans'] );
				}
				break;

			default:
				return;
		}

		echo '<div class="notice notice-warning ept-lifecycle-notice">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<p><strong>' . esc_html__( 'Traduzioni', 'ept' ) . ':</strong> ' . $body . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="event" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( self::ACTION . '_' . $id );
		echo '<p>';
		if ( '' !== $apply ) {
			echo '<button type="submit" class="button button-primary" name="choice" value="apply">' . esc_html( $apply ) . '</button> ';
		}
		foreach ( $other as $value => $label ) {
			echo '<button type="submit" class="button" name="choice" value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</button> ';
		}
		echo '</p></form></div>';
	}

	/**
	 * Handle a choice.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per questa operazione.', 'ept' ), 403 );
		}
		$id = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $id );
		$choice = isset( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
		if ( ! in_array( $choice, array( 'apply', 'unlink', 'dismiss' ), true ) ) {
			$choice = 'dismiss';
		}
		$values = array();
		if ( isset( $_POST['slug'] ) && is_array( $_POST['slug'] ) ) {
			foreach ( wp_unslash( $_POST['slug'] ) as $lang => $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
				if ( is_scalar( $slug ) ) {
					$values[ sanitize_key( (string) $lang ) ] = sanitize_title( (string) $slug );
				}
			}
		}
		$message = Lifecycle::apply( $id, $choice, $values );
		if ( '' !== $message ) {
			Notices::add( 'success', $message );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'edit.php?post_type=page' ) );
		exit;
	}
}

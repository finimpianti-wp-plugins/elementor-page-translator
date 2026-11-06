<?php
/**
 * Notice inside the Elementor editor when a translated page is opened: changes made
 * there are overwritten by the next update from the translation screen.
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Languages;
use EPT\Translations;

defined( 'ABSPATH' ) || exit;

/**
 * Editor notice.
 */
final class Editor_Notice {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'elementor/editor/after_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Data of the notice for a post, or null when the post is not a translation.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>|null
	 */
	public static function data( int $post_id ): ?array {
		$source = Translations::source_of( $post_id );
		if ( ! $source || Translations::is_source( $post_id ) ) {
			return null;
		}
		$lang = Translations::language_of( $post_id );
		$name = (string) ( Languages::get( $lang )['name'] ?? strtoupper( $lang ) );
		return array(
			'message'     => sprintf(
				/* translators: 1: language name, 2: title of the Italian page. */
				__( 'Questa è la traduzione %1$s di "%2$s". Le modifiche fatte qui vengono sovrascritte al prossimo aggiornamento: i testi si cambiano nella schermata di traduzione, struttura e stile nella pagina italiana.', 'ept' ),
				$name,
				get_the_title( $source )
			),
			'screenLabel' => __( 'Apri la schermata di traduzione', 'ept' ),
			'screenUrl'   => Translation_Screen::url( $source, $lang ),
			'sourceLabel' => __( 'Modifica la pagina italiana', 'ept' ),
			'sourceUrl'   => admin_url( 'post.php?post=' . $source . '&action=elementor' ),
			'closeLabel'  => __( 'Chiudi', 'ept' ),
		);
	}

	/**
	 * Add the notice script to the editor.
	 */
	public static function enqueue(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$data    = $post_id ? self::data( $post_id ) : null;
		if ( null === $data ) {
			return;
		}
		$script = <<<'JS'
( function ( d ) {
	function show() {
		if ( document.getElementById( 'ept-editor-notice' ) ) { return; }
		var box = document.createElement( 'div' );
		box.id = 'ept-editor-notice';
		box.setAttribute( 'role', 'status' );
		box.style.cssText = 'position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:99999;max-width:720px;background:#fcf0d4;color:#3c2a00;border:1px solid #dba617;border-radius:4px;padding:10px 40px 10px 14px;font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.15)';
		var p = document.createElement( 'p' );
		p.style.margin = '0 0 6px';
		p.textContent = d.message;
		box.appendChild( p );
		[ [ d.screenUrl, d.screenLabel ], [ d.sourceUrl, d.sourceLabel ] ].forEach( function ( l, i ) {
			var a = document.createElement( 'a' );
			a.href = l[0];
			a.textContent = l[1];
			a.style.cssText = 'color:#0a4b78;font-weight:600;margin-right:16px';
			box.appendChild( a );
		} );
		var close = document.createElement( 'button' );
		close.type = 'button';
		close.textContent = '×';
		close.setAttribute( 'aria-label', d.closeLabel );
		close.style.cssText = 'position:absolute;top:4px;right:8px;border:0;background:none;font-size:20px;cursor:pointer;color:#3c2a00';
		close.addEventListener( 'click', function () { box.remove(); } );
		box.appendChild( close );
		document.body.appendChild( box );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', show ); } else { show(); }
}( window.eptEditorNotice ) );
JS;
		wp_add_inline_script( 'elementor-editor', 'window.eptEditorNotice = ' . wp_json_encode( $data ) . ';' . $script );
	}
}

<?php
/**
 * Requests of the Elementor editor: the editor page itself, the preview loaded in its
 * iframe (?elementor-preview=ID) and the preview of a template (?elementor_library=…).
 *
 * The editor reads the preview iframe directly: if that request were redirected or replaced
 * by another page, the editor could not load it. On these requests the plugin never
 * redirects, never answers 404 and never replaces the requested content.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor editor / preview requests.
 */
final class Editor_Request {

	/**
	 * Whether the current request is the Elementor editor or one of its previews.
	 */
	public static function active(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only check of the request type.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
			return true;
		}
		if ( isset( $_GET['action'] ) && 'elementor' === $_GET['action'] && is_admin() ) {
			return true;
		}
		// phpcs:enable
		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance ) ) {
			$elementor = \Elementor\Plugin::$instance;
			if ( isset( $elementor->preview ) && is_object( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
				return true;
			}
			if ( isset( $elementor->editor ) && is_object( $elementor->editor ) && method_exists( $elementor->editor, 'is_edit_mode' ) && $elementor->editor->is_edit_mode() ) {
				return true;
			}
		}
		return false;
	}
}

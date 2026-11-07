<?php
/**
 * "Meta description" box on Italian pages when no SEO plugin is active
 * (with Yoast or Rank Math their own fields are used).
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Seo;
use EPT\Translations;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * SEO metabox.
 */
final class Seo_Metabox {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes_page', array( self::class, 'add' ) );
		add_action( 'save_post_page', array( self::class, 'save' ), 10, 2 );
	}

	/**
	 * Add the box.
	 *
	 * @param WP_Post $post Page.
	 */
	public static function add( $post ): void {
		if ( '' !== Seo::installed_plugin() || ! $post instanceof WP_Post || ! Translations::is_source( $post->ID ) ) {
			return;
		}
		add_meta_box( 'ept-seo', __( 'Meta description', 'ept' ), array( self::class, 'render' ), 'page', 'normal', 'default' );
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Page.
	 */
	public static function render( $post ): void {
		wp_nonce_field( 'ept_seo_meta', 'ept_seo_nonce' );
		$value = (string) get_post_meta( $post->ID, Seo::META_DESCRIPTION, true );
		echo '<label class="screen-reader-text" for="ept-meta-description">' . esc_html__( 'Meta description', 'ept' ) . '</label>';
		echo '<textarea id="ept-meta-description" name="ept_meta_description" class="large-text" rows="3" maxlength="320">' . esc_textarea( $value ) . '</textarea>';
		/* translators: %d: recommended number of characters. */
		echo '<p class="description">' . esc_html( sprintf( __( 'Descrizione mostrata nei risultati dei motori di ricerca, circa %d caratteri. Le traduzioni si scrivono nella schermata di traduzione.', 'ept' ), Seo::DESC_LENGTH ) ) . '</p>';
	}

	/**
	 * Save the box.
	 *
	 * @param int     $post_id Page ID.
	 * @param WP_Post $post    Page.
	 */
	public static function save( $post_id, $post ): void {
		unset( $post );
		if ( ! isset( $_POST['ept_seo_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ept_seo_nonce'] ) ), 'ept_seo_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
		$value = isset( $_POST['ept_meta_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ept_meta_description'] ) ) : '';
		if ( '' === $value ) {
			delete_post_meta( $post_id, Seo::META_DESCRIPTION );
		} else {
			update_post_meta( $post_id, Seo::META_DESCRIPTION, wp_slash( $value ) );
		}
	}
}

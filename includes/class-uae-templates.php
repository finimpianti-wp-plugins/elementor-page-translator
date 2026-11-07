<?php
/**
 * UAE 2.9.6 header / footer / before-footer templates per language.
 *
 * Verified on the installed plugin (docs/verifiche.md): Header_Footer_Elementor::get_settings()
 * applies "hfe_get_settings_type_header|type_footer|type_before_footer" to the ID chosen by
 * the display rules; hfe_header_enabled() and get_hfe_header_id() both go through it.
 * The [hfe_template id] shortcode applies "hfe_render_template_id".
 *
 * On a page in another language the Italian template ID is replaced by its translation
 * (published, or any status for users who can edit it). Translated templates have no
 * display rules, so UAE never selects them by itself.
 *
 * @package EPT
 */

namespace EPT;

defined( 'ABSPATH' ) || exit;

/**
 * UAE templates.
 */
final class Uae_Templates {

	public const POST_TYPE = 'elementor-hf';

	/**
	 * Display-rule meta of UAE templates (cleared on translations).
	 */
	public const RULE_META = array( 'ehf_target_include_locations', 'ehf_target_exclude_locations', 'ehf_target_user_roles' );

	/**
	 * Hooks.
	 */
	public static function init(): void {
		foreach ( array( 'type_header', 'type_footer', 'type_before_footer' ) as $type ) {
			add_filter( 'hfe_get_settings_' . $type, array( self::class, 'swap' ), 20 );
		}
		add_filter( 'hfe_render_template_id', array( self::class, 'swap' ), 20 );
	}

	/**
	 * Template ID for the current language.
	 *
	 * @param mixed $template_id Template ID chosen by UAE ('' or false when none).
	 * @return mixed
	 */
	public static function swap( $template_id ) {
		$id = (int) $template_id;
		if ( ! $id || is_admin() || Languages::default_code() === Current_Language::code() ) {
			return $template_id;
		}
		$source = Translations::is_source( $id ) ? $id : Translations::source_of( $id );
		$tid    = $source ? Translations::get_id( $source, Current_Language::code() ) : null;
		if ( ! $tid ) {
			return $template_id;
		}
		$status = get_post_status( $tid );
		if ( 'publish' === $status || ( $status && current_user_can( 'edit_post', $tid ) ) ) {
			return is_string( $template_id ) ? (string) $tid : $tid;
		}
		return $template_id;
	}

	/**
	 * Remove display rules from a translated template.
	 *
	 * @param int $template_id Translated template ID.
	 */
	public static function clear_rules( int $template_id ): void {
		foreach ( self::RULE_META as $key ) {
			delete_post_meta( $template_id, $key );
		}
	}
}

<?php
/**
 * Pages list: "Traduci" action, "Traduzioni" column with one badge per language,
 * language label on translated pages and a language filter.
 *
 * Badges in F2: ✓ translation exists, + missing. "Da aggiornare" arrives in F3.
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Languages;
use EPT\Root_Pages;
use EPT\Translations;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Pages list.
 */
final class Pages_List {

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'page_row_actions', array( self::class, 'row_actions' ), 20, 2 );
		add_filter( 'manage_pages_columns', array( self::class, 'columns' ) );
		add_action( 'manage_pages_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 20, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'language_filter' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_language_filter' ) );
		add_action( 'admin_head-edit.php', array( self::class, 'styles' ) );
	}

	/**
	 * Whether a page can be offered for translation.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function is_translatable( WP_Post $post ): bool {
		return 'page' === $post->post_type
			&& 'trash' !== $post->post_status
			&& Translations::is_source( $post->ID )
			&& null === Root_Pages::root_language( $post->ID )
			&& 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
	}

	/**
	 * Row action "Traduci".
	 *
	 * @param array<string,string> $actions Actions.
	 * @param WP_Post              $post    Post.
	 * @return array<string,string>
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || ! self::is_translatable( $post ) || ! current_user_can( 'edit_page', $post->ID ) || ! Languages::active() ) {
			return $actions;
		}
		$first                    = (string) array_key_first( Languages::active() );
		$actions['ept_translate'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( Translation_Screen::url( $post->ID, $first ) ),
			esc_html__( 'Traduci', 'ept' )
		);
		return $actions;
	}

	/**
	 * Add the "Traduzioni" column.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		if ( ! Languages::active() ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['ept_translations'] = __( 'Traduzioni', 'ept' );
			}
		}
		return $out;
	}

	/**
	 * Render the "Traduzioni" column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $column, $post_id ): void {
		if ( 'ept_translations' !== $column ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! Translations::is_source( $post_id ) ) {
			$lang = Languages::get( Translations::language_of( $post_id ) );
			echo '<span class="ept-badge ept-badge--lang">' . esc_html( strtoupper( (string) ( $lang['code'] ?? Translations::language_of( $post_id ) ) ) ) . '</span>';
			return;
		}
		if ( ! self::is_translatable( $post ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		$map = Translations::map( $post_id );
		foreach ( Languages::active() as $code => $lang ) {
			$exists = isset( $map[ $code ] );
			$label  = $exists
				/* translators: %s: language name. */
				? sprintf( __( '%s: tradotta', 'ept' ), $lang['name'] )
				/* translators: %s: language name. */
				: sprintf( __( '%s: mancante', 'ept' ), $lang['name'] );
			printf(
				'<a class="ept-badge %1$s" href="%2$s" title="%3$s" aria-label="%3$s">%4$s %5$s</a> ',
				esc_attr( $exists ? 'ept-badge--ok' : 'ept-badge--missing' ),
				esc_url( Translation_Screen::url( $post_id, (string) $code ) ),
				esc_attr( $label ),
				esc_html( strtoupper( (string) $code ) ),
				$exists ? '&#10003;' : '+'
			);
		}
	}

	/**
	 * Label translated pages: "EN · traduzione di ‹title›".
	 *
	 * @param array<string,string> $states States.
	 * @param WP_Post              $post   Post.
	 * @return array<string,string>
	 */
	public static function post_states( $states, $post ) {
		if ( ! $post instanceof WP_Post || Translations::is_source( $post->ID ) ) {
			return $states;
		}
		$source = Translations::source_of( $post->ID );
		$code   = strtoupper( Translations::language_of( $post->ID ) );
		if ( $source ) {
			/* translators: 1: language code, 2: title of the Italian page. */
			$states['ept_translation'] = sprintf( __( '%1$s · traduzione di "%2$s"', 'ept' ), $code, get_the_title( $source ) );
		}
		if ( (int) get_post_meta( $post->ID, Translations::META_INCOMPLETE, true ) > 0 ) {
			$states['ept_incomplete'] = __( 'incompleta', 'ept' );
		}
		return $states;
	}

	/**
	 * Language dropdown above the list.
	 *
	 * @param string $post_type Post type.
	 */
	public static function language_filter( $post_type ): void {
		if ( 'page' !== $post_type || ! Languages::all() ) {
			return;
		}
		$current = isset( $_GET['ept_lang'] ) ? sanitize_key( wp_unslash( $_GET['ept_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		echo '<label for="ept-lang-filter" class="screen-reader-text">' . esc_html__( 'Filtra per lingua', 'ept' ) . '</label>';
		echo '<select name="ept_lang" id="ept-lang-filter">';
		echo '<option value="">' . esc_html__( 'Tutte le lingue', 'ept' ) . '</option>';
		$default = Languages::default_language();
		printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $default['code'] ), selected( $current, $default['code'], false ), esc_html( $default['name'] ) );
		foreach ( Languages::all() as $code => $lang ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( (string) $code ), selected( $current, (string) $code, false ), esc_html( $lang['name'] ) );
		}
		echo '</select>';
	}

	/**
	 * Apply the language filter to the admin query.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function apply_language_filter( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'page' !== $query->get( 'post_type' ) || empty( $_GET['ept_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
			return;
		}
		$lang = sanitize_key( wp_unslash( $_GET['ept_lang'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$meta = (array) $query->get( 'meta_query' );
		if ( Languages::default_code() === $lang ) {
			$meta[] = array(
				'key'     => Translations::META_LANG,
				'compare' => 'NOT EXISTS',
			);
		} else {
			$meta[] = array(
				'key'   => Translations::META_LANG,
				'value' => $lang,
			);
		}
		$query->set( 'meta_query', $meta );
	}

	/**
	 * Badge styles for the list.
	 */
	public static function styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-page' !== $screen->id ) {
			return;
		}
		echo '<style>
			.column-ept_translations{width:11em}
			.ept-badge{display:inline-block;padding:1px 6px;margin:0 2px 2px 0;border-radius:3px;font-size:11px;line-height:18px;text-decoration:none;border:1px solid #c3c4c7;color:#1d2327;background:#f6f7f7}
			.ept-badge--ok{background:#edfaef;border-color:#68de7c;color:#00450c}
			.ept-badge--missing{background:#fff;color:#50575e}
			.ept-badge--lang{background:#f0f6fc;border-color:#72aee6;color:#0a4b78;font-weight:600}
		</style>';
	}
}

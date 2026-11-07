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
		// UAE header / footer templates: same tools.
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 20, 2 );
		add_filter( 'manage_elementor-hf_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_elementor-hf_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'bulk_actions-edit-elementor-hf', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-elementor-hf', array( self::class, 'handle_bulk_sync' ), 10, 3 );
		add_filter( 'display_post_states', array( self::class, 'post_states' ), 20, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'language_filter' ) );
		add_action( 'pre_get_posts', array( self::class, 'apply_language_filter' ) );
		add_action( 'admin_head-edit.php', array( self::class, 'styles' ) );
		add_filter( 'bulk_actions-edit-page', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-page', array( self::class, 'handle_bulk_sync' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'bulk_sync_notice' ) );
	}

	/**
	 * Whether a page can be offered for translation.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function is_translatable( WP_Post $post ): bool {
		return in_array( $post->post_type, array( 'page', \EPT\Uae_Templates::POST_TYPE ), true )
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
		if ( ! $post instanceof WP_Post || ! self::is_translatable( $post ) || ! current_user_can( 'edit_post', $post->ID ) || ! Languages::active() ) {
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
		foreach ( Languages::active() as $code => $lang ) {
			$state = Translations::status( $post_id, (string) $code );
			$view  = array(
				/* translators: %s: language name. */
				Translations::STATE_OK       => array( 'ept-badge--ok', '&#10003;', __( '%s: aggiornata', 'ept' ) ),
				/* translators: %s: language name. */
				Translations::STATE_OUTDATED => array( 'ept-badge--outdated', '!', __( '%s: da aggiornare', 'ept' ) ),
				/* translators: %s: language name. */
				Translations::STATE_MISSING  => array( 'ept-badge--missing', '+', __( '%s: mancante', 'ept' ) ),
			)[ $state ];
			$label = sprintf( $view[2], $lang['name'] );
			printf(
				'<a class="ept-badge %1$s" href="%2$s" title="%3$s" aria-label="%3$s">%4$s %5$s</a> ',
				esc_attr( $view[0] ),
				esc_url( Translation_Screen::url( $post_id, (string) $code ) ),
				esc_attr( $label ),
				esc_html( strtoupper( (string) $code ) ),
				$view[1] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed entity from the array above.
			);
		}
	}

	/**
	 * Bulk action "Sincronizza traduzioni".
	 *
	 * @param array<string,string> $actions Actions.
	 * @return array<string,string>
	 */
	public static function bulk_actions( $actions ) {
		if ( Languages::active() ) {
			$actions['ept_sync'] = __( 'Sincronizza traduzioni', 'ept' );
		}
		return $actions;
	}

	/**
	 * Quick update of the translations of the selected Italian pages.
	 *
	 * @param string    $redirect Redirect URL.
	 * @param string    $action   Action.
	 * @param list<int> $post_ids Selected pages.
	 */
	public static function handle_bulk_sync( $redirect, $action, $post_ids ): string {
		if ( 'ept_sync' !== $action ) {
			return $redirect;
		}
		$report = array(
			'synced'         => array(),
			'needs_screen'   => array(),
			'manual_changes' => array(),
			'error'          => array(),
			'up_to_date'     => 0,
		);
		foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || ! self::is_translatable( $post ) || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			foreach ( array_keys( Languages::active() ) as $code ) {
				$target = Translations::get_id( $post_id, (string) $code );
				if ( null === $target || ! current_user_can( 'edit_post', $target ) ) {
					continue;
				}
				$result = \EPT\Translation_Service::quick_sync( $post, (string) $code );
				if ( 'up_to_date' === $result['result'] ) {
					++$report['up_to_date'];
					continue;
				}
				$report[ $result['result'] ][] = array(
					'post'    => $post_id,
					'lang'    => (string) $code,
					'new'     => $result['new'],
					'changed' => $result['modified'],
					'message' => $result['message'],
				);
			}
		}
		set_transient( 'ept_bulk_sync_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );
		return add_query_arg( 'ept_synced', '1', $redirect );
	}

	/**
	 * Report of the bulk synchronisation.
	 */
	public static function bulk_sync_notice(): void {
		if ( empty( $_GET['ept_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			return;
		}
		$key    = 'ept_bulk_sync_' . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );

		$item = static function ( array $row ): string {
			$link = '<a href="' . esc_url( Translation_Screen::url( (int) $row['post'], (string) $row['lang'] ) ) . '">' . esc_html( get_the_title( (int) $row['post'] ) . ' — ' . strtoupper( (string) $row['lang'] ) ) . '</a>';
			if ( $row['new'] || $row['changed'] ) {
				/* translators: 1: new texts, 2: modified texts. */
				$link .= ' ' . esc_html( sprintf( __( '(%1$d nuovi, %2$d modificati)', 'ept' ), $row['new'], $row['changed'] ) );
			}
			return '<li>' . $link . '</li>';
		};

		$type = $report['needs_screen'] || $report['manual_changes'] || $report['error'] ? 'warning' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible">';
		/* translators: 1: synced translations, 2: translations already up to date. */
		echo '<p>' . esc_html( sprintf( __( 'Traduzioni aggiornate: %1$d. Già aggiornate: %2$d.', 'ept' ), count( $report['synced'] ), $report['up_to_date'] ) ) . '</p>';
		$sections = array(
			'needs_screen'   => __( 'Ci sono testi nuovi o modificati: apri la schermata di traduzione.', 'ept' ),
			'manual_changes' => __( 'Modificate a mano in Elementor: aggiornale dalla schermata di traduzione, che chiede conferma.', 'ept' ),
			'error'          => __( 'Errore durante l\'aggiornamento:', 'ept' ),
		);
		foreach ( $sections as $section => $title ) {
			if ( empty( $report[ $section ] ) ) {
				continue;
			}
			echo '<p><strong>' . esc_html( $title ) . '</strong></p><ul style="list-style:disc;margin-left:2em">';
			foreach ( $report[ $section ] as $row ) {
				echo $item( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $item.
			}
			echo '</ul>';
		}
		echo '</div>';
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
		if ( ! in_array( $post_type, array( 'page', \EPT\Uae_Templates::POST_TYPE ), true ) || ! Languages::all() ) {
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
		if ( ! is_admin() || ! $query->is_main_query() || ! in_array( $query->get( 'post_type' ), array( 'page', \EPT\Uae_Templates::POST_TYPE ), true ) || empty( $_GET['ept_lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
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
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-page', 'edit-elementor-hf' ), true ) ) {
			return;
		}
		echo '<style>
			.column-ept_translations{width:11em}
			.ept-badge{display:inline-block;padding:1px 6px;margin:0 2px 2px 0;border-radius:3px;font-size:11px;line-height:18px;text-decoration:none;border:1px solid #c3c4c7;color:#1d2327;background:#f6f7f7}
			.ept-badge--ok{background:#edfaef;border-color:#68de7c;color:#00450c}
			.ept-badge--missing{background:#fff;color:#50575e}
			.ept-badge--outdated{background:#fcf0d4;border-color:#dba617;color:#6e4c00;font-weight:600}
			.ept-badge--lang{background:#f0f6fc;border-color:#72aee6;color:#0a4b78;font-weight:600}
		</style>';
	}
}

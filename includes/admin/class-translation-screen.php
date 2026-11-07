<?php
/**
 * Translation screen host page: admin.php?page=ept-translate&post={id}&lang={code}.
 * The screen itself is rendered by assets/translation-screen.js through the REST API.
 *
 * @package EPT
 */

namespace EPT\Admin;

use EPT\Languages;
use EPT\Translation_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Translation screen.
 */
final class Translation_Screen {

	public const SLUG = 'ept-translate';

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * URL of the screen.
	 *
	 * @param int    $post_id Italian page ID.
	 * @param string $lang    Language code.
	 */
	public static function url( int $post_id, string $lang ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'post' => $post_id,
				'lang' => $lang,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Hidden admin page (no menu entry: reached from the pages list).
	 */
	public static function menu(): void {
		self::$hook = (string) add_submenu_page(
			'', // Hidden.
			__( 'Traduzione', 'ept' ),
			__( 'Traduzione', 'ept' ),
			'edit_pages',
			self::SLUG,
			array( self::class, 'render' )
		);
		// Hidden pages have no menu title: give the admin header a title (avoids a PHP notice).
		add_action(
			'load-' . self::$hook,
			static function (): void {
				$GLOBALS['title'] = __( 'Traduzione', 'ept' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- admin page title.
			}
		);
	}

	/**
	 * Current request parameters.
	 *
	 * @return array{0:int,1:string}
	 */
	private static function params(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen; writes go through REST with nonce.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$lang    = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : '';
		// phpcs:enable
		return array( $post_id, $lang );
	}

	/**
	 * Enqueue screen assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ): void {
		if ( $hook !== self::$hook || '' === self::$hook ) {
			return;
		}
		list( $post_id, $lang ) = self::params();
		wp_enqueue_style( 'ept-translation-screen', EPT_URL . 'assets/translation-screen.css', array(), EPT_VERSION );
		wp_enqueue_script( 'ept-translation-screen', EPT_URL . 'assets/translation-screen.js', array( 'wp-api-fetch', 'wp-i18n' ), EPT_VERSION, true );
		wp_set_script_translations( 'ept-translation-screen', 'ept', EPT_DIR . 'languages' );
		wp_add_inline_script(
			'ept-translation-screen',
			'window.eptScreen = ' . wp_json_encode(
				array(
					'postId'    => $post_id,
					'lang'      => $lang,
					'restPath'  => '/ept/v1/translations/',
					'screenUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
					'pagesUrl'  => admin_url( 'edit.php?post_type=page' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render the host markup.
	 */
	public static function render(): void {
		list( $post_id, $lang ) = self::params();
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Non hai i permessi per tradurre questa pagina.', 'ept' ), 403 );
		}
		$check = Translation_Service::validate( $post_id, $lang );
		echo '<div class="wrap ept-screen">';
		echo '<h1>' . esc_html__( 'Traduzione', 'ept' ) . '</h1>';
		if ( is_wp_error( $check ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $check->get_error_message() ) . '</p></div>';
			if ( ! Languages::active() ) {
				echo '<p><a href="' . esc_url( Settings_Page::url() ) . '">' . esc_html__( 'Configura le lingue', 'ept' ) . '</a></p>';
			}
			echo '</div>';
			return;
		}
		echo '<div id="ept-app" aria-live="polite"><p>' . esc_html__( 'Caricamento…', 'ept' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'La schermata di traduzione richiede JavaScript.', 'ept' ) . '</p></noscript>';
		echo '</div>';
	}
}

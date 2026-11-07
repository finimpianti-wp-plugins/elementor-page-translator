<?php
/**
 * Language switcher inside a WordPress menu (also the hamburger menu of UAE on mobile).
 *
 * In Appearance -> Menus a box "Selettore lingua" adds a special item to the menu (a custom
 * link "#ept-languages" or "#ept-languages-flat"). On the front end that item is replaced by:
 * - "#ept-languages" (dropdown): the flag and code of the current language, with the other
 *   languages as its sub-menu (flag + name) — the menu's own sub-menu style and, on mobile,
 *   its expandable entry inside the hamburger;
 * - "#ept-languages-flat" (in a row): one item per language (flag + name).
 * Links follow the same rules as the shortcode switcher (version of the current page, else
 * home of the language; languages that are not public only for editors).
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Menu switcher.
 */
final class Menu_Switcher {

	public const DROPDOWN = '#ept-languages';
	public const FLAT     = '#ept-languages-flat';

	/**
	 * What is shown next to the flags: flag (only the flags), code (IT, EN), name (Italiano, English).
	 */
	public const TEXT_META = '_ept_switcher_text';

	/**
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_nav_menu_objects', array( self::class, 'expand' ), 20, 2 );
		add_filter( 'wp_setup_nav_menu_item', array( self::class, 'label_in_admin' ) );
		add_action( 'admin_head-nav-menus.php', array( self::class, 'meta_box' ) );
		add_action( 'wp_nav_menu_item_custom_fields', array( self::class, 'item_fields' ), 10, 2 );
		add_action( 'wp_update_nav_menu_item', array( self::class, 'save_item_fields' ), 10, 2 );
	}

	/**
	 * Text shown next to the flags for a switcher item.
	 *
	 * @param object $item Menu item.
	 */
	public static function text_of( $item ): string {
		$id   = (int) ( $item->db_id ?? $item->ID ?? 0 );
		$text = $id > 0 ? (string) get_post_meta( $id, self::TEXT_META, true ) : '';
		return in_array( $text, array( 'flag', 'code', 'name' ), true ) ? $text : 'flag';
	}

	/**
	 * Options in the panel of the item (Appearance -> Menus, click on the item).
	 *
	 * @param int    $item_id Item ID.
	 * @param object $item    Item.
	 */
	public static function item_fields( $item_id, $item ): void {
		$mode = self::mode_of( $item );
		if ( '' === $mode ) {
			return;
		}
		$item_id = (int) $item_id;
		$text    = self::text_of( $item );
		// The URL of this item is technical: hidden, the choices below replace it.
		echo '<style>#menu-item-settings-' . (int) $item_id . ' .field-url{display:none}</style>';
		?>
		<p class="description description-wide ept-switcher-fields">
			<label for="ept-switcher-mode-<?php echo (int) $item_id; ?>"><?php esc_html_e( 'Selettore lingua: aspetto', 'ept' ); ?><br>
				<select id="ept-switcher-mode-<?php echo (int) $item_id; ?>" name="ept-switcher-mode[<?php echo (int) $item_id; ?>]" class="widefat">
					<option value="dropdown" <?php selected( $mode, 'dropdown' ); ?>><?php esc_html_e( 'Tendina: lingua corrente, le altre si aprono sotto', 'ept' ); ?></option>
					<option value="flat" <?php selected( $mode, 'flat' ); ?>><?php esc_html_e( 'In fila: tutte le lingue una accanto all\'altra', 'ept' ); ?></option>
				</select>
			</label>
		</p>
		<p class="description description-wide ept-switcher-fields">
			<label for="ept-switcher-text-<?php echo (int) $item_id; ?>"><?php esc_html_e( 'Accanto alle bandiere', 'ept' ); ?><br>
				<select id="ept-switcher-text-<?php echo (int) $item_id; ?>" name="ept-switcher-text[<?php echo (int) $item_id; ?>]" class="widefat">
					<option value="flag" <?php selected( $text, 'flag' ); ?>><?php esc_html_e( 'Niente: solo le bandiere', 'ept' ); ?></option>
					<option value="code" <?php selected( $text, 'code' ); ?>><?php esc_html_e( 'La sigla (IT, EN)', 'ept' ); ?></option>
					<option value="name" <?php selected( $text, 'name' ); ?>><?php esc_html_e( 'Il nome (Italiano, English)', 'ept' ); ?></option>
				</select>
			</label>
		</p>
		<?php
	}

	/**
	 * Save the options of a switcher item (the menu form is already checked by WordPress:
	 * nonce "update-nav_menu" and the edit_theme_options capability).
	 *
	 * @param int $menu_id Menu ID.
	 * @param int $item_id Item ID.
	 */
	public static function save_item_fields( $menu_id, $item_id ): void {
		unset( $menu_id );
		$item_id = (int) $item_id;
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by wp-admin/nav-menus.php (update-nav_menu).
		if ( isset( $_POST['ept-switcher-text'][ $item_id ] ) ) {
			$text = sanitize_key( wp_unslash( $_POST['ept-switcher-text'][ $item_id ] ) );
			update_post_meta( $item_id, self::TEXT_META, in_array( $text, array( 'flag', 'code', 'name' ), true ) ? $text : 'flag' );
		}
		if ( isset( $_POST['ept-switcher-mode'][ $item_id ] ) ) {
			$mode = sanitize_key( wp_unslash( $_POST['ept-switcher-mode'][ $item_id ] ) );
			update_post_meta( $item_id, '_menu_item_url', 'flat' === $mode ? self::FLAT : self::DROPDOWN );
		}
		// phpcs:enable
	}

	/**
	 * Whether a menu item is the language switcher; returns its mode or ''.
	 *
	 * @param object $item Menu item.
	 */
	public static function mode_of( $item ): string {
		$url = is_object( $item ) ? trim( (string) ( $item->url ?? '' ) ) : '';
		if ( self::FLAT === $url ) {
			return 'flat';
		}
		return self::DROPDOWN === $url ? 'dropdown' : '';
	}

	/* --------------------------------------------------------------------
	 * Admin: Appearance -> Menus
	 * ------------------------------------------------------------------ */

	/**
	 * Box in the left column of Appearance -> Menus.
	 */
	public static function meta_box(): void {
		add_meta_box( 'ept-language-switcher', __( 'Selettore lingua', 'ept' ), array( self::class, 'render_meta_box' ), 'nav-menus', 'side', 'low' );
	}

	/**
	 * Box content: the two variants, added with the standard "Aggiungi al menu" button.
	 */
	public static function render_meta_box(): void {
		$variants = array(
			-1 => array( self::DROPDOWN, __( 'Tendina: bandiera della lingua corrente, le altre si aprono sotto', 'ept' ) ),
			-2 => array( self::FLAT, __( 'In fila: tutte le bandiere una accanto all\'altra', 'ept' ) ),
		);
		?>
		<div id="ept-language-switcher-items" class="posttypediv">
			<p class="description"><?php esc_html_e( 'Aggiunge le lingue a questo menu: nel menu del computer e, su mobile, dentro il menu hamburger. Aggiungilo a ogni menu usato nell\'header, anche a quelli delle altre lingue (es. "Menu EN").', 'ept' ); ?></p>
			<?php self::render_menus_status(); ?>
			<p class="description"><?php esc_html_e( 'Di base mostra solo le bandiere. Dopo averlo aggiunto, apri la voce "Lingue" nel menu per mostrare anche la sigla (IT, EN) o il nome (Italiano, English).', 'ept' ); ?></p>
			<div class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<?php foreach ( $variants as $index => $variant ) : ?>
						<li>
							<label class="menu-item-title">
								<input type="checkbox" class="menu-item-checkbox" name="menu-item[<?php echo (int) $index; ?>][menu-item-object-id]" value="<?php echo (int) $index; ?>">
								<?php echo esc_html( $variant[1] ); ?>
							</label>
							<input type="hidden" class="menu-item-type" name="menu-item[<?php echo (int) $index; ?>][menu-item-type]" value="custom">
							<input type="hidden" class="menu-item-title" name="menu-item[<?php echo (int) $index; ?>][menu-item-title]" value="<?php esc_attr_e( 'Lingue', 'ept' ); ?>">
							<input type="hidden" class="menu-item-url" name="menu-item[<?php echo (int) $index; ?>][menu-item-url]" value="<?php echo esc_attr( $variant[0] ); ?>">
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="add-to-menu">
					<input type="submit" class="button submit-add-to-menu right" value="<?php esc_attr_e( 'Aggiungi al menu', 'ept' ); ?>" name="add-ept-language-switcher" id="submit-ept-language-switcher-items">
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Which menus already contain the switcher (to add it to the menus of every language).
	 */
	private static function render_menus_status(): void {
		$menus = wp_get_nav_menus();
		if ( ! $menus ) {
			return;
		}
		echo '<ul class="ept-menus-status" style="margin:8px 0 10px">';
		foreach ( $menus as $menu ) {
			$has = false;
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( '' !== self::mode_of( $item ) ) {
					$has = true;
					break;
				}
			}
			printf(
				'<li><span class="dashicons %1$s" style="color:%2$s" aria-hidden="true"></span> %3$s <span class="screen-reader-text">%4$s</span><span aria-hidden="true" style="color:#646970">— %4$s</span></li>',
				$has ? 'dashicons-yes' : 'dashicons-minus',
				$has ? '#00a32a' : '#a7aaad',
				esc_html( $menu->name ),
				esc_html( $has ? __( 'selettore presente', 'ept' ) : __( 'senza selettore', 'ept' ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * In the menu editor the item is shown as "Selettore lingua", not as a custom link.
	 *
	 * @param object $item Menu item.
	 * @return object
	 */
	public static function label_in_admin( $item ) {
		$mode = self::mode_of( $item );
		if ( '' !== $mode && is_admin() ) {
			$item->type_label = 'flat' === $mode ? __( 'Selettore lingua (in fila)', 'ept' ) : __( 'Selettore lingua (tendina)', 'ept' );
		}
		return $item;
	}

	/* --------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Replace the switcher items with the languages.
	 *
	 * @param array<int,object> $items Sorted menu items.
	 * @param object|array      $args  wp_nav_menu() arguments.
	 * @return array<int,object>
	 */
	public static function expand( $items, $args = null ) {
		unset( $args );
		// Not in the menu editor; yes in the Elementor editor preview (rendered through admin-ajax).
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! is_array( $items ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $item ) {
			$mode = self::mode_of( $item );
			if ( '' === $mode ) {
				$out[] = $item;
				continue;
			}
			$languages = Switcher::items( 'home' );
			if ( count( $languages ) < 2 ) {
				continue; // Nothing to switch to: the item disappears.
			}
			Switcher::register_assets();
			wp_enqueue_style( 'ept-switcher' );
			// Keeps a UAE "last item as button" on the entry before the languages.
			wp_enqueue_script( 'ept-menu-switcher', EPT_URL . 'assets/menu-switcher.js', array(), EPT_VERSION, true );
			foreach ( 'flat' === $mode ? self::flat( $item, $languages ) : self::dropdown( $item, $languages ) as $new ) {
				$out[] = $new;
			}
		}
		// Keep the order WordPress expects (menu_order) for walkers that sort.
		foreach ( array_values( $out ) as $i => $item ) {
			$item->menu_order = $i + 1;
		}
		return $out;
	}

	/**
	 * Dropdown: current language with the others as sub-menu.
	 *
	 * @param WP_Post|object            $marker    Switcher item.
	 * @param list<array<string,mixed>> $languages Switcher items.
	 * @return list<object>
	 */
	private static function dropdown( $marker, array $languages ): array {
		$current = null;
		foreach ( $languages as $language ) {
			if ( $language['current'] ) {
				$current = $language;
			}
		}
		$current = $current ?? $languages[0];
		$text    = self::text_of( $marker );
		$parent  = self::item(
			$marker,
			(int) $marker->ID,
			(int) $marker->menu_item_parent,
			self::label( $current, $text, true ),
			'#',
			array( 'ept-menu-lang', 'ept-menu-lang--dropdown', 'ept-menu-lang--text-' . $text, 'menu-item-has-children' )
		);
		$out     = array( $parent );
		$i       = 0;
		foreach ( $languages as $language ) {
			if ( $language['current'] ) {
				continue;
			}
			$out[] = self::item(
				$marker,
				-1 * ( (int) $marker->ID * 100 + ( ++$i ) ),
				(int) $marker->ID,
				self::label( $language, $text ),
				(string) $language['url'],
				array( 'ept-menu-lang__item', 'ept-menu-lang--text-' . $text, 'ept-menu-lang__item--' . sanitize_html_class( (string) $language['code'] ) )
			);
		}
		return $out;
	}

	/**
	 * In a row: one item per language.
	 *
	 * @param WP_Post|object            $marker    Switcher item.
	 * @param list<array<string,mixed>> $languages Switcher items.
	 * @return list<object>
	 */
	private static function flat( $marker, array $languages ): array {
		$out  = array();
		$text = self::text_of( $marker );
		foreach ( $languages as $i => $language ) {
			$classes = array( 'ept-menu-lang', 'ept-menu-lang--flat', 'ept-menu-lang--text-' . $text, 'ept-menu-lang__item--' . sanitize_html_class( (string) $language['code'] ) );
			if ( $language['current'] ) {
				$classes[] = 'ept-menu-lang--current';
			}
			$out[] = self::item(
				$marker,
				0 === $i ? (int) $marker->ID : -1 * ( (int) $marker->ID * 100 + $i ),
				(int) $marker->menu_item_parent,
				self::label( $language, $text ),
				(string) $language['url'],
				$classes
			);
		}
		return $out;
	}

	/**
	 * Flag and, depending on the option, code or name of a language. With the flag only, the
	 * name stays for screen readers.
	 *
	 * @param array<string,mixed> $language Switcher item.
	 * @param string              $text     flag|code|name.
	 * @param bool                $current  Label of the current language (dropdown toggle).
	 */
	private static function label( array $language, string $text, bool $current = false ): string {
		$lang = esc_attr( Current_Language::bcp47( (string) $language['locale'] ) );
		$name = (string) $language['name'];
		$html = self::flag( $language );
		if ( 'code' === $text ) {
			$html .= '<span class="ept-menu-lang__code" lang="' . $lang . '">' . esc_html( strtoupper( (string) $language['code'] ) ) . '</span>'
				. '<span class="ept-sr">' . esc_html( $name ) . '</span>';
		} elseif ( 'name' === $text ) {
			$html .= '<span class="ept-menu-lang__name" lang="' . $lang . '">' . esc_html( $name ) . '</span>';
		} else {
			/* translators: %s: language name. */
			$html .= '<span class="ept-sr">' . esc_html( $current ? sprintf( __( 'Lingua: %s', 'ept' ), $name ) : $name ) . '</span>';
		}
		if ( empty( $language['public'] ) ) {
			$html .= '<span class="ept-menu-lang__private" title="' . esc_attr__( 'Lingua non ancora pubblica: la vedono solo gli utenti che possono modificare il sito.', 'ept' ) . '">*</span>';
		}
		// One wrapper: menus that spread the children of a link (flex, space-between) keep flag and text together.
		return '<span class="ept-menu-lang__label">' . $html . '</span>';
	}

	/**
	 * Flag image (decorative: the name or code is always next to it).
	 *
	 * @param array<string,mixed> $language Switcher item.
	 */
	private static function flag( array $language ): string {
		$url = Switcher::flag_url( (string) $language['locale'] );
		return '' === $url ? '' : '<img class="ept-menu-flag" src="' . esc_url( $url ) . '" alt="" width="22" height="16" decoding="async">';
	}

	/**
	 * A menu item built from the switcher item (keeps its classes, target and so on).
	 *
	 * @param WP_Post|object $marker  Switcher item.
	 * @param int            $id      ID.
	 * @param int            $parent_id Parent item ID.
	 * @param string         $title   Title HTML.
	 * @param string         $url     URL.
	 * @param list<string>   $classes Extra classes.
	 * @return object
	 */
	private static function item( $marker, int $id, int $parent_id, string $title, string $url, array $classes ) {
		$item                   = clone $marker;
		$item->ID               = $id;
		$item->db_id            = $id;
		$item->menu_item_parent = (string) $parent_id;
		$item->title            = $title;
		$item->url              = $url;
		$item->attr_title       = '';
		$item->target           = '';
		$item->xfn              = '';
		$item->object           = 'custom';
		$item->type             = 'custom';
		$item->current          = false;
		$own                    = array_filter( (array) $marker->classes, static fn( $c ): bool => ! in_array( $c, array( 'current-menu-item', 'menu-item-has-children', 'current_page_item' ), true ) );
		$item->classes          = array_values( array_unique( array_merge( $own, array( 'menu-item', 'menu-item-type-custom' ), $classes ) ) );
		return $item;
	}
}

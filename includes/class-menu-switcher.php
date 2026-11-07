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
	 * Hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_nav_menu_objects', array( self::class, 'expand' ), 20, 2 );
		add_filter( 'wp_setup_nav_menu_item', array( self::class, 'label_in_admin' ) );
		add_action( 'admin_head-nav-menus.php', array( self::class, 'meta_box' ) );
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
			-1 => array( self::DROPDOWN, __( 'Tendina: bandiera della lingua corrente, le altre lingue nel sottomenu', 'ept' ) ),
			-2 => array( self::FLAT, __( 'In fila: una voce per ogni lingua (bandiera e nome)', 'ept' ) ),
		);
		?>
		<div id="ept-language-switcher-items" class="posttypediv">
			<p class="description"><?php esc_html_e( 'Aggiunge le lingue a questo menu: nel menu del computer e, su mobile, dentro il menu hamburger. Aggiungilo a ogni menu usato nell\'header, anche a quelli delle altre lingue (es. "Menu EN").', 'ept' ); ?></p>
			<?php self::render_menus_status(); ?>
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
		if ( is_admin() || ! is_array( $items ) ) {
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
		$parent  = self::item(
			$marker,
			(int) $marker->ID,
			(int) $marker->menu_item_parent,
			'<span class="ept-menu-lang__label">' . self::flag( $current ) . '<span class="ept-menu-lang__code">' . esc_html( strtoupper( (string) $current['code'] ) ) . '</span></span>',
			'#',
			array( 'ept-menu-lang', 'ept-menu-lang--dropdown', 'menu-item-has-children' )
		);
		/* translators: %s: current language name. */
		$parent->attr_title = sprintf( __( 'Lingua: %s', 'ept' ), $current['name'] );
		$out                = array( $parent );
		$i                  = 0;
		foreach ( $languages as $language ) {
			if ( $language['current'] ) {
				continue;
			}
			$out[] = self::item(
				$marker,
				-1 * ( (int) $marker->ID * 100 + ( ++$i ) ),
				(int) $marker->ID,
				self::label( $language ),
				(string) $language['url'],
				array( 'ept-menu-lang__item', 'ept-menu-lang__item--' . sanitize_html_class( (string) $language['code'] ) )
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
		$out = array();
		foreach ( $languages as $i => $language ) {
			$classes = array( 'ept-menu-lang', 'ept-menu-lang--flat', 'ept-menu-lang__item--' . sanitize_html_class( (string) $language['code'] ) );
			if ( $language['current'] ) {
				$classes[] = 'ept-menu-lang--current';
			}
			$out[] = self::item(
				$marker,
				0 === $i ? (int) $marker->ID : -1 * ( (int) $marker->ID * 100 + $i ),
				(int) $marker->menu_item_parent,
				self::label( $language ),
				(string) $language['url'],
				$classes
			);
		}
		return $out;
	}

	/**
	 * Flag + name of a language.
	 *
	 * @param array<string,mixed> $language Switcher item.
	 */
	private static function label( array $language ): string {
		// One wrapper: menus that spread the children of a link (flex, space-between) keep flag and name together.
		$html = self::flag( $language ) . '<span class="ept-menu-lang__name" lang="' . esc_attr( Current_Language::bcp47( (string) $language['locale'] ) ) . '">' . esc_html( (string) $language['name'] ) . '</span>';
		if ( empty( $language['public'] ) ) {
			$html .= '<span class="ept-menu-lang__private">' . esc_html__( '(lingua non pubblica)', 'ept' ) . '</span>';
		}
		return '<span class="ept-menu-lang__label">' . $html . '</span>';
	}

	/**
	 * Flag image (decorative: the name or code is always next to it).
	 *
	 * @param array<string,mixed> $language Switcher item.
	 */
	private static function flag( array $language ): string {
		$url = Switcher::flag_url( (string) $language['locale'] );
		return '' === $url ? '' : '<img class="ept-menu-flag" src="' . esc_url( $url ) . '" alt="" width="20" height="14" decoding="async">';
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

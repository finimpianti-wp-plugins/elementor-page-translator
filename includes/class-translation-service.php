<?php
/**
 * Translation workflow for one Italian page and one language:
 * screen data, draft save, and creation/update of the translated page.
 *
 * Links and references (menus, shortcodes) are copied unchanged until F4/F7;
 * SEO fields arrive in F6.
 *
 * @package EPT
 */

namespace EPT;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Translation service.
 */
final class Translation_Service {

	/**
	 * Labels of widget types shown in the screen.
	 *
	 * @return array<string,string>
	 */
	public static function widget_labels(): array {
		return array(
			'heading'                => __( 'Titolo', 'ept' ),
			'text-editor'            => __( 'Editor di testo', 'ept' ),
			'button'                 => __( 'Pulsante', 'ept' ),
			'counter'                => __( 'Contatore', 'ept' ),
			'image'                  => __( 'Immagine', 'ept' ),
			'icon-box'               => __( 'Box con icona', 'ept' ),
			'image-box'              => __( 'Box con immagine', 'ept' ),
			'icon-list'              => __( 'Elenco icone', 'ept' ),
			'accordion'              => __( 'Fisarmonica', 'ept' ),
			'toggle'                 => __( 'Interruttore', 'ept' ),
			'tabs'                   => __( 'Schede', 'ept' ),
			'nested-accordion'       => __( 'Fisarmonica', 'ept' ),
			'nested-tabs'            => __( 'Schede', 'ept' ),
			'testimonial'            => __( 'Testimonianza', 'ept' ),
			'alert'                  => __( 'Avviso', 'ept' ),
			'html'                   => __( 'HTML', 'ept' ),
			'e-heading'              => __( 'Titolo (atomico)', 'ept' ),
			'e-paragraph'            => __( 'Paragrafo (atomico)', 'ept' ),
			'e-button'               => __( 'Pulsante (atomico)', 'ept' ),
			'e-image'                => __( 'Immagine (atomico)', 'ept' ),
			'hfe-breadcrumbs-widget' => __( 'Breadcrumb (UAE)', 'ept' ),
			'copyright'              => __( 'Copyright (UAE)', 'ept' ),
			'site-logo'              => __( 'Logo (UAE)', 'ept' ),
			'hfe-search-button'      => __( 'Ricerca (UAE)', 'ept' ),
			'hfe-infocard'           => __( 'Infocard (UAE)', 'ept' ),
		);
	}

	/**
	 * Check that a page can be translated into a language.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language code.
	 * @return WP_Post|WP_Error
	 */
	public static function validate( int $source_id, string $lang ) {
		$post = get_post( $source_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'page', Uae_Templates::POST_TYPE ), true ) || 'trash' === $post->post_status ) {
			return new WP_Error( 'ept_not_found', __( 'Pagina non trovata.', 'ept' ), array( 'status' => 404 ) );
		}
		if ( ! Translations::is_source( $source_id ) ) {
			return new WP_Error( 'ept_not_source', __( 'Questa pagina è già una traduzione: si traduce solo la pagina in lingua predefinita.', 'ept' ), array( 'status' => 400 ) );
		}
		if ( 'builder' !== get_post_meta( $source_id, '_elementor_edit_mode', true ) ) {
			return new WP_Error( 'ept_not_elementor', __( 'La pagina non è costruita con Elementor.', 'ept' ), array( 'status' => 400 ) );
		}
		$language = Languages::get( $lang );
		if ( null === $language || empty( $language['active'] ) ) {
			return new WP_Error( 'ept_bad_language', __( 'Lingua non attiva.', 'ept' ), array( 'status' => 400 ) );
		}
		return $post;
	}

	/**
	 * Whether the page is the static front page (its translation is the root page).
	 *
	 * @param int $source_id Italian page ID.
	 */
	public static function is_home( int $source_id ): bool {
		return 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $source_id;
	}

	/**
	 * Target parent of the translation.
	 *
	 * @param WP_Post $source      Italian page.
	 * @param string  $lang        Language.
	 * @param string  $parent_mode "" or "root" (accept the root page when the parent is not translated).
	 * @return array{ok: bool, id: int, title: string, url: string, message: string, needs_choice: bool}
	 */
	public static function target_parent( WP_Post $source, string $lang, string $parent_mode = '' ): array {
		if ( Uae_Templates::POST_TYPE === $source->post_type ) {
			return array(
				'ok'           => true,
				'id'           => 0,
				'title'        => '',
				'url'          => '',
				'message'      => '',
				'needs_choice' => false,
			);
		}
		$root_id = (int) ( Languages::get( $lang )['root_page_id'] ?? 0 );
		$root    = array(
			'ok'           => $root_id > 0,
			'id'           => $root_id,
			'title'        => $root_id ? get_the_title( $root_id ) : '',
			'url'          => $root_id ? self::page_url( $root_id ) : '',
			'message'      => $root_id ? '' : __( 'La pagina radice della lingua non esiste: riattiva la lingua nelle impostazioni.', 'ept' ),
			'needs_choice' => false,
		);
		if ( self::is_home( $source->ID ) || 0 === (int) $source->post_parent ) {
			return $root;
		}
		$parent_translation = Translations::get_id( (int) $source->post_parent, $lang );
		if ( null !== $parent_translation ) {
			return array(
				'ok'           => true,
				'id'           => $parent_translation,
				'title'        => get_the_title( $parent_translation ),
				'url'          => self::page_url( $parent_translation ),
				'message'      => '',
				'needs_choice' => false,
			);
		}
		$message = sprintf(
			/* translators: %s: title of the parent page. */
			__( 'La pagina genitore "%s" non è ancora tradotta in questa lingua. Traducila prima, oppure appoggia questa pagina alla pagina radice.', 'ept' ),
			get_the_title( (int) $source->post_parent )
		);
		if ( 'root' === $parent_mode ) {
			$root['message'] = $message;
			return $root;
		}
		return array(
			'ok'           => false,
			'id'           => 0,
			'title'        => '',
			'url'          => '',
			'message'      => $message,
			'needs_choice' => true,
		);
	}

	/**
	 * Permalink of a page as it will be once published (drafts included).
	 *
	 * @param int $post_id Page ID.
	 */
	private static function page_url( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		if ( 'publish' === $post->post_status ) {
			return (string) get_permalink( $post );
		}
		require_once ABSPATH . 'wp-admin/includes/post.php';
		list( $template, $name ) = get_sample_permalink( $post );
		return str_replace( array( '%pagename%', '%postname%' ), $name, $template );
	}

	/**
	 * Data for the translation screen.
	 *
	 * @param WP_Post $source Italian page.
	 * @param string  $lang   Language.
	 * @return array<string,mixed>
	 */
	public static function screen_data( WP_Post $source, string $lang ): array {
		$language  = Languages::get( $lang );
		$extracted = Content::extractor()->extract( Content::elements( $source->ID ) );
		$saved     = Translations::strings( $source->ID, $lang );
		$fields    = Translations::page_fields( $source->ID, $lang );
		$target_id = self::target_id( $source->ID, $lang );
		$target    = $target_id ? get_post( $target_id ) : null;
		$labels    = self::widget_labels();

		$rows   = array();
		$groups = array();
		$done   = 0;
		$total  = 0;
		// Suggestions from the translation memory for texts never translated on this page.
		$suggest = Memory::suggestions(
			$lang,
			array_map(
				static fn( array $item ): string => $item['hash'],
				array_filter( $extracted['strings'], static fn( array $item ): bool => ! $item['dynamic'] && ! isset( $saved[ $item['key'] ] ) )
			)
		);
		foreach ( $extracted['strings'] as $item ) {
			if ( ! in_array( $item['group'], $groups, true ) ) {
				$groups[] = $item['group'];
			}
			$entry = $saved[ $item['key'] ] ?? null;
			$state = 'new';
			$old   = '';
			if ( is_array( $entry ) && ( '' !== (string) ( $entry['t'] ?? '' ) || in_array( $entry['s'] ?? '', array( Translations::STATUS_SAME, Translations::STATUS_EXCLUDED ), true ) ) ) {
				$state = Translations::same_original( $entry, $item ) ? 'translated' : 'modified';
				$old   = 'modified' === $state ? Html_Tools::strip_noise( (string) ( $entry['o'] ?? '' ) ) : '';
			}
			// Simplified view: wrapper hidden, <br> as line breaks (only when original and translation share the wrapper).
			$original_html = Html_Tools::strip_noise( $item['text'] );
			$translation   = is_array( $entry ) ? (string) ( $entry['t'] ?? '' ) : '';
			$space         = 'text-editor' !== $item['widget'];
			$simple        = Widget_Map::CODE === $item['format'] || $item['dynamic'] ? null : Simple_Text::analyze( $original_html, $space );
			$translation_s = '';
			if ( null !== $simple && '' !== $translation ) {
				$plain = Simple_Text::plain_translation( $translation, $simple, $space );
				if ( null === $plain ) {
					$simple = null;
				} else {
					$translation_s = $plain;
				}
			}
			$row    = array(
				'key'          => $item['key'],
				'simple'       => null === $simple ? null : array(
					'prefix'      => $simple['prefix'],
					'suffix'      => $simple['suffix'],
					'original'    => $simple['text'],
					'translation' => $translation_s,
				),
				'group'        => array_search( $item['group'], $groups, true ) + 1,
				'widget'       => $item['widget'],
				'widget_label' => $labels[ $item['widget'] ] ?? $item['widget'],
				'field'        => (string) substr( $item['key'], strpos( $item['key'], '|' ) + 1 ),
				'format'       => $item['format'],
				'source'       => $item['source'],
				'dynamic'      => $item['dynamic'],
				'original'     => $original_html,
				'translation'  => $translation,
				'status'       => is_array( $entry ) ? (string) ( $entry['s'] ?? '' ) : '',
				'state'        => $state,
				'old_original' => $old,
				'suggestion'   => self::suggestion_for( $item, $state, $suggest, $simple, $space ),
				'proposal'     => is_array( $entry ) ? (string) ( $entry['p'] ?? '' ) : '',
			);
			$rows[] = $row;
			if ( ! $item['dynamic'] ) {
				++$total;
				if ( 'new' !== $state ) {
					++$done;
				}
			}
		}

		$parent_mode = (string) ( $fields['parent_mode'] ?? '' );
		$parent      = self::target_parent( $source, $lang, $parent_mode );
		$is_home     = self::is_home( $source->ID );
		$title       = (string) ( $fields['title'] ?? ( $target ? $target->post_title : '' ) );
		$slug        = $is_home ? (string) $language['prefix'] : (string) ( $fields['slug'] ?? ( $target ? $target->post_name : '' ) );

		$languages = array();
		foreach ( Languages::active() as $code => $l ) {
			$languages[] = array(
				'code'   => $code,
				'name'   => $l['name'],
				'exists' => null !== self::target_id( $source->ID, $code ),
			);
		}

		return array(
			'source'         => array(
				'id'        => $source->ID,
				'title'     => $source->post_title,
				'slug'      => $source->post_name,
				'edit_link' => (string) get_edit_post_link( $source->ID, 'raw' ),
				'view_link' => (string) get_permalink( $source ),
				'is_home'   => $is_home,
			),
			'lang'           => array(
				'code'   => $lang,
				'name'   => $language['name'],
				'locale' => $language['locale'],
				'prefix' => $language['prefix'],
				'public' => ! empty( $language['public'] ),
			),
			'languages'      => $languages,
			'target'         => $target ? self::target_info( $target ) : null,
			'page'           => array(
				'title'       => $title,
				'slug'        => $slug,
				'status'      => $target ? ( 'publish' === $target->post_status ? 'publish' : 'draft' ) : (string) ( $fields['status'] ?? 'draft' ),
				'parent_mode' => $parent_mode,
			),
			'parent'         => $parent,
			'strings'        => $rows,
			'links'          => Links::screen_rows( $source->ID, $lang, Content::elements( $source->ID ), $extracted['strings'] ),
			'relink'         => self::relink_candidates( $source->ID, $lang ),
			'seo'            => 'page' === $source->post_type ? Seo::screen_data( $source, $lang ) : null,
			'kind'           => 'page' === $source->post_type ? 'page' : 'template',
			'refs'           => Refs::screen_rows( $source->ID, $lang, Content::elements( $source->ID ) ),
			'menus'          => Refs::menus(),
			'forms'          => Refs::form_suggestions(),
			'progress'       => array(
				'done'  => $done,
				'total' => $total,
			),
			'can_unfiltered' => current_user_can( 'unfiltered_html' ),
			'deepl'          => Proposals::usage_summary(),
			'source_locale'  => (string) Languages::default_language()['locale'],
			'can_publish'    => current_user_can( 'publish_pages' ),
			'pretty_links'   => '' !== (string) get_option( 'permalink_structure' ),
		);
	}

	/**
	 * Memory suggestion for a new row (HTML and, when it shares the wrapper, plain text).
	 *
	 * @param array<string,mixed>       $item    Extracted string.
	 * @param string                    $state   Row state.
	 * @param array<string,string>      $suggest hash => translation.
	 * @param array<string,string>|null $simple  Simplified original, if any.
	 * @param bool                      $space   Raw newline is a space.
	 * @return array{html:string, plain:?string}|null
	 */
	private static function suggestion_for( array $item, string $state, array $suggest, ?array $simple, bool $space ): ?array {
		if ( 'new' !== $state || $item['dynamic'] || ! isset( $suggest[ $item['hash'] ] ) ) {
			return null;
		}
		$html  = $suggest[ $item['hash'] ];
		$plain = null !== $simple ? Simple_Text::plain_translation( $html, $simple, $space ) : null;
		return array(
			'html'  => $html,
			'plain' => $plain,
		);
	}

	/**
	 * Pages of the language that still link to the Italian version of this page although
	 * its translation is now published (candidates for "update links").
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 * @return list<array{id:int,title:string}>
	 */
	public static function relink_candidates( int $source_id, string $lang ): array {
		$target = Translations::get_id( $source_id, $lang );
		if ( null === $target || 'publish' !== get_post_status( $target ) ) {
			return array();
		}
		$out = array();
		foreach ( Links::linking_pages( array( $source_id ), $lang, Links::STATE_ITALIAN ) as $page ) {
			if ( $page !== $target && current_user_can( 'edit_page', $page ) ) {
				$out[] = array(
					'id'    => $page,
					'title' => get_the_title( $page ),
				);
			}
		}
		return $out;
	}

	/**
	 * Existing translation of a page: linked page, or the root page for the front page.
	 *
	 * @param int    $source_id Italian page ID.
	 * @param string $lang      Language.
	 */
	public static function target_id( int $source_id, string $lang ): ?int {
		return Translations::get_id( $source_id, $lang );
	}

	/**
	 * Public info about a translated page.
	 *
	 * @param WP_Post $target Translated page.
	 * @return array<string,mixed>
	 */
	private static function target_info( WP_Post $target ): array {
		return array(
			'id'             => $target->ID,
			'status'         => $target->post_status,
			'title'          => $target->post_title,
			'edit_link'      => (string) get_edit_post_link( $target->ID, 'raw' ),
			'elementor_link' => admin_url( 'post.php?post=' . $target->ID . '&action=elementor' ),
			'view_link'      => 'publish' === $target->post_status ? (string) get_permalink( $target ) : (string) get_preview_post_link( $target ),
			'incomplete'     => (int) get_post_meta( $target->ID, Translations::META_INCOMPLETE, true ),
			'manual_changes' => Translations::has_manual_changes( $target->ID ),
			'state'          => Translations::status( Translations::source_of( $target->ID ), Translations::language_of( $target->ID ) ),
		);
	}

	/**
	 * Save the screen input (strings and page fields) without touching the translated page.
	 *
	 * @param WP_Post             $source Italian page.
	 * @param string              $lang   Language.
	 * @param array<string,mixed> $input  Request body: page{title,slug,status,parent_mode}, strings{key: {t, s, ok}}.
	 * @return array{sanitized: list<string>} Keys whose markup was changed by sanitisation.
	 */
	public static function save_draft( WP_Post $source, string $lang, array $input ): array {
		$extracted = Content::extractor()->extract( Content::elements( $source->ID ) );
		$items     = array_column( $extracted['strings'], null, 'key' );
		$saved     = Translations::strings( $source->ID, $lang );
		$posted    = isset( $input['strings'] ) && is_array( $input['strings'] ) ? $input['strings'] : array();
		$sanitized = array();

		foreach ( $posted as $key => $row ) {
			$key = (string) $key;
			if ( ! isset( $items[ $key ] ) || $items[ $key ]['dynamic'] || ! is_array( $row ) ) {
				continue;
			}
			$item   = $items[ $key ];
			$status = in_array( $row['s'] ?? '', array( Translations::STATUS_SAME, Translations::STATUS_EXCLUDED ), true ) ? (string) $row['s'] : '';
			if ( Translations::STATUS_EXCLUDED === $status && 'fallback' !== $item['source'] ) {
				$status = '';
			}
			$raw  = is_string( $row['t'] ?? null ) ? (string) $row['t'] : '';
			$text = self::sanitize_text( $raw, $item['format'] );
			if ( $text !== $raw && self::normalize_markup( $text ) !== self::normalize_markup( $raw ) ) {
				$sanitized[] = $key;
			}
			if ( '' !== $status ) {
				$text = '';
			} elseif ( '' !== $text ) {
				$status = Translations::STATUS_TRANSLATED;
			}

			// A "modified" row the user did not review keeps the old hash and original.
			$reviewed = ! empty( $row['ok'] ) || ! isset( $saved[ $key ] );
			$previous = $saved[ $key ] ?? null;

			if ( '' === $status && '' === $text ) {
				unset( $saved[ $key ] );
				continue;
			}
			$saved[ $key ] = array(
				'h' => $reviewed || ! is_array( $previous ) ? $item['hash'] : (string) $previous['h'],
				'o' => $reviewed || ! is_array( $previous ) ? $item['text'] : (string) $previous['o'],
				't' => $text,
				's' => $status,
			);
			// Automatic proposal not yet confirmed by the user (memory, filter or DeepL).
			$proposal = (string) ( $row['p'] ?? '' );
			if ( '' !== $text && in_array( $proposal, array( 'memory', 'filter', 'deepl', 'paste' ), true ) ) {
				$saved[ $key ]['p'] = $proposal;
			}
		}
		Translations::save_strings( $source->ID, $lang, $saved );

		if ( isset( $input['links'] ) && is_array( $input['links'] ) ) {
			Links::save_choices( $source->ID, $lang, $input['links'] );
		}
		if ( isset( $input['refs'] ) && is_array( $input['refs'] ) ) {
			Refs::save_choices( $source->ID, $lang, $input['refs'] );
		}
		if ( isset( $input['seo'] ) && is_array( $input['seo'] ) ) {
			Seo::save_fields( $source->ID, $lang, $input['seo'] );
		}

		$page   = isset( $input['page'] ) && is_array( $input['page'] ) ? $input['page'] : array();
		$fields = Translations::page_fields( $source->ID, $lang );
		if ( array_key_exists( 'title', $page ) ) {
			$fields['title'] = sanitize_text_field( (string) $page['title'] );
		}
		if ( array_key_exists( 'slug', $page ) ) {
			$fields['slug'] = sanitize_title( (string) $page['slug'] );
		}
		if ( array_key_exists( 'status', $page ) ) {
			$fields['status'] = 'publish' === $page['status'] ? 'publish' : 'draft';
		}
		if ( array_key_exists( 'parent_mode', $page ) ) {
			$fields['parent_mode'] = 'root' === $page['parent_mode'] ? 'root' : '';
		}
		Translations::save_page_fields( $source->ID, $lang, $fields );

		return array( 'sanitized' => $sanitized );
	}

	/**
	 * Sanitise a translation like Elementor does: raw HTML only with unfiltered_html.
	 *
	 * @param string $text   Text.
	 * @param string $format text|html|code.
	 */
	public static function sanitize_text( string $text, string $format ): string {
		unset( $format );
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $text;
		}
		return wp_kses_post( $text );
	}

	/**
	 * Markup normalised for comparison (kses may only re-quote attributes).
	 *
	 * @param string $html HTML.
	 */
	private static function normalize_markup( string $html ): string {
		return (string) preg_replace( '/\s+/', ' ', str_replace( array( "'", '&#039;' ), '"', $html ) );
	}

	/**
	 * Create or update the translated page.
	 *
	 * @param WP_Post             $source  Italian page.
	 * @param string              $lang    Language.
	 * @param array<string,mixed> $input   Request body (saved first, as a draft).
	 * @param bool                $confirm   Confirm generation with missing translations.
	 * @param bool                $overwrite Confirm overwriting manual changes made in Elementor.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function generate( WP_Post $source, string $lang, array $input, bool $confirm, bool $overwrite = false ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'ept_no_elementor', __( 'Elementor non è attivo.', 'ept' ), array( 'status' => 500 ) );
		}
		$draft = self::save_draft( $source, $lang, $input );

		$root = Root_Pages::ensure( $lang );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$fields  = Translations::page_fields( $source->ID, $lang );
		$parent  = self::target_parent( $source, $lang, (string) ( $fields['parent_mode'] ?? '' ) );
		$is_home = self::is_home( $source->ID );
		if ( ! $parent['ok'] ) {
			return new WP_Error( 'ept_parent', $parent['message'], array( 'status' => 409 ) );
		}

		// Texts for the Rebuilder: translation, else the original; noise attributes removed.
		$elements  = Content::elements( $source->ID );
		$extracted = Content::extractor()->extract( $elements );
		$saved     = Translations::strings( $source->ID, $lang );
		$texts     = array();
		$missing   = 0;
		$missing_k = array();
		$memory    = array();
		foreach ( $extracted['strings'] as $item ) {
			if ( $item['dynamic'] ) {
				continue;
			}
			$entry = $saved[ $item['key'] ] ?? null;
			$final = $item['text'];
			if ( is_array( $entry ) && Translations::STATUS_TRANSLATED === ( $entry['s'] ?? '' ) && '' !== (string) $entry['t'] ) {
				$final    = (string) $entry['t'];
				$memory[] = array(
					'text'        => $item['text'],
					'translation' => $final,
				);
			} elseif ( ! is_array( $entry ) ) {
				++$missing;
				$missing_k[] = $item['key'];
			}
			$final = Widget_Map::CODE === $item['format'] ? $final : Html_Tools::strip_noise( $final );
			if ( $final !== $item['text'] ) {
				$texts[ $item['key'] ] = $final;
			}
		}
		if ( $missing > 0 && ! $confirm ) {
			return new WP_Error(
				'ept_incomplete',
				sprintf(
					/* translators: %d: number of texts. */
					_n( 'Manca %d traduzione: il testo resterà in italiano e la pagina sarà marcata "incompleta".', 'Mancano %d traduzioni: i testi resteranno in italiano e la pagina sarà marcata "incompleta".', $missing, 'ept' ),
					$missing
				),
				array(
					'status'  => 409,
					'missing' => $missing,
				)
			);
		}

		$existing = self::target_id( $source->ID, $lang );
		if ( null !== $existing && ! $overwrite && Translations::has_manual_changes( $existing ) ) {
			return new WP_Error(
				'ept_manual_changes',
				__( 'La pagina tradotta è stata modificata a mano in Elementor dopo l\'ultima generazione: aggiornandola, quelle modifiche andranno perse (restano recuperabili dalle revisioni).', 'ept' ),
				array( 'status' => 409 )
			);
		}

		$links   = Links::final_values( $source->ID, $lang, $elements );
		$rebuilt = Content::rebuilder()->apply( $elements, $texts, $links['values'], Refs::final_values( $source->ID, $lang, $elements ) );

		// Create or update the WordPress page.
		$target_id   = self::target_id( $source->ID, $lang );
		$first_build = null === $target_id;
		if ( null === $target_id && $is_home ) {
			$target_id = (int) $root;
		}
		$title = '' !== (string) ( $fields['title'] ?? '' ) ? (string) $fields['title'] : $source->post_title;
		$slug  = $is_home ? (string) Languages::get( $lang )['prefix'] : sanitize_title( '' !== (string) ( $fields['slug'] ?? '' ) ? (string) $fields['slug'] : $title );

		$postarr = array(
			'post_type'      => $source->post_type,
			'post_title'     => $title,
			'post_name'      => $slug,
			'post_parent'    => $is_home || Uae_Templates::POST_TYPE === $source->post_type ? 0 : (int) $parent['id'],
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
		);
		$created = false;
		if ( null === $target_id ) {
			$wants_publish          = 'publish' === ( $fields['status'] ?? 'draft' ) && current_user_can( 'publish_pages' );
			$postarr['post_status'] = $wants_publish ? 'publish' : 'draft';
			$postarr['post_author'] = get_current_user_id();
			$target_id              = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $target_id ) ) {
				return $target_id;
			}
			$created = true;
		} else {
			$current   = get_post( $target_id );
			$requested = isset( $input['page']['status'] ) ? ( 'publish' === $input['page']['status'] ? 'publish' : 'draft' ) : null;
			if ( null === $requested && $is_home && $current && 'publish' !== $current->post_status && 'publish' === ( $fields['status'] ?? 'draft' ) ) {
				$requested = 'publish'; // A root page created as draft before the home translation was published.
			}
			$now = $current && 'publish' === $current->post_status ? 'publish' : 'draft';
			if ( null !== $requested && $now !== $requested && ( 'draft' === $requested || current_user_can( 'publish_pages' ) ) ) {
				$postarr['post_status'] = $requested;
			}
			self::save_revision( (int) $target_id );
			$postarr['ID'] = $target_id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		$target_id = (int) $target_id;
		$created   = $created || $first_build;

		Translations::link( $source->ID, $lang, $target_id );
		self::copy_page_meta( $source->ID, $target_id );
		if ( Uae_Templates::POST_TYPE === $source->post_type ) {
			update_post_meta( $target_id, 'ehf_template_type', (string) get_post_meta( $source->ID, 'ehf_template_type', true ) );
			Uae_Templates::clear_rules( $target_id ); // UAE never picks a translation by itself.
		}

		// Save through Elementor's document API: same sanitisation as the editor, CSS regenerated.
		$settings = get_post_meta( $source->ID, '_elementor_page_settings', true );
		$document = \Elementor\Plugin::$instance->documents->get( $target_id, false );
		if ( ! $document ) {
			return new WP_Error( 'ept_document', __( 'Elementor non riesce ad aprire la pagina tradotta.', 'ept' ), array( 'status' => 500 ) );
		}
		$ok = $document->save(
			array(
				'elements' => $rebuilt['elements'],
				'settings' => is_array( $settings ) ? $settings : array(),
			)
		);
		if ( false === $ok ) {
			return new WP_Error( 'ept_save', __( 'Elementor non ha salvato la pagina tradotta (permessi insufficienti?).', 'ept' ), array( 'status' => 403 ) );
		}

		update_post_meta( $target_id, Translations::META_SOURCE_HASH, Translations::source_fingerprint( $source->ID ) );
		update_post_meta( $target_id, Translations::META_OUTPUT_HASH, Translations::data_hash( $target_id ) );
		update_post_meta( $target_id, Translations::META_STRINGS_HASH, Translations::strings_hash( $source->ID, $lang ) );
		update_post_meta( $target_id, Translations::META_MISSING_KEYS, $missing_k );
		$seo = Seo::fields( $source->ID, $lang );
		foreach ( $seo['alts'] as $attachment_id => $alt ) {
			$memory[] = array(
				'text'        => (string) get_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', true ),
				'translation' => $alt,
			);
		}
		Memory::store( $lang, $memory, $source->ID );
		if ( 'page' === $source->post_type ) {
			Seo::write_plugin_fields( $source->ID, $target_id, $lang );
		}
		Links::write_index( $target_id, $lang, $links['index'] );
		if ( $missing > 0 ) {
			update_post_meta( $target_id, Translations::META_INCOMPLETE, $missing );
		} else {
			delete_post_meta( $target_id, Translations::META_INCOMPLETE );
		}

		/**
		 * After a translated page has been created or updated.
		 *
		 * @param int    $target_id Translated page ID.
		 * @param int    $source_id Italian page ID.
		 * @param string $lang      Language code.
		 * @param bool   $created   Whether the page was just created.
		 */
		do_action( 'ept_after_sync', $target_id, $source->ID, $lang, $created );
		self::purge_cache( $target_id );

		$target = get_post( $target_id );
		return array(
			'relink'    => self::relink_candidates( $source->ID, $lang ),
			'created'   => $created,
			'missing'   => $missing,
			'sanitized' => $draft['sanitized'],
			'failed'    => $rebuilt['failed'],
			'target'    => $target ? self::target_info( $target ) : null,
		);
	}

	/**
	 * Quick update from the pages list: regenerate a translation when the Italian page
	 * changed only in structure or style. Stops when there are new or modified texts
	 * (texts already missing at the last generation do not count) or manual changes.
	 *
	 * @param WP_Post $source Italian page.
	 * @param string  $lang   Language.
	 * @return array{result: string, new: int, modified: int, message: string}
	 *         result: synced | up_to_date | needs_screen | manual_changes | error.
	 */
	public static function quick_sync( WP_Post $source, string $lang ): array {
		$out    = array(
			'result'   => 'error',
			'new'      => 0,
			'modified' => 0,
			'message'  => '',
		);
		$target = Translations::get_id( $source->ID, $lang );
		if ( null === $target ) {
			$out['message'] = __( 'Traduzione inesistente.', 'ept' );
			return $out;
		}
		if ( Translations::STATE_OK === Translations::status( $source->ID, $lang ) ) {
			$out['result'] = 'up_to_date';
			return $out;
		}
		$saved     = Translations::strings( $source->ID, $lang );
		$was_empty = (array) get_post_meta( $target, Translations::META_MISSING_KEYS, true );
		foreach ( Content::extractor()->extract( Content::elements( $source->ID ) )['strings'] as $item ) {
			if ( $item['dynamic'] ) {
				continue;
			}
			$entry = $saved[ $item['key'] ] ?? null;
			if ( ! is_array( $entry ) ) {
				if ( ! in_array( $item['key'], $was_empty, true ) ) {
					++$out['new'];
				}
			} elseif ( ! Translations::same_original( $entry, $item ) ) {
				++$out['modified'];
			}
		}
		if ( $out['new'] > 0 || $out['modified'] > 0 ) {
			$out['result'] = 'needs_screen';
			return $out;
		}
		$result = self::generate( $source, $lang, array(), true, false );
		if ( is_wp_error( $result ) ) {
			$out['result']  = 'ept_manual_changes' === $result->get_error_code() ? 'manual_changes' : 'error';
			$out['message'] = $result->get_error_message();
			return $out;
		}
		$out['result'] = 'synced';
		return $out;
	}

	/**
	 * Copy page-level meta that is not part of Elementor's data.
	 *
	 * @param int $source_id Italian page ID.
	 * @param int $target_id Translated page ID.
	 */
	private static function copy_page_meta( int $source_id, int $target_id ): void {
		update_post_meta( $target_id, '_elementor_edit_mode', 'builder' );
		foreach ( array( '_wp_page_template', '_elementor_template_type', '_thumbnail_id' ) as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' !== $value && false !== $value ) {
				update_post_meta( $target_id, $key, $value );
			} else {
				delete_post_meta( $target_id, $key );
			}
		}
	}

	/**
	 * Store a revision of the translated page before it is overwritten.
	 *
	 * @param int $post_id Translated page ID.
	 */
	private static function save_revision( int $post_id ): void {
		if ( ! wp_revisions_enabled( get_post( $post_id ) ) ) {
			return;
		}
		add_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
		wp_save_post_revision( $post_id );
		remove_filter( 'wp_save_post_revision_check_for_changes', '__return_false' );
	}

	/**
	 * Purge SiteGround's dynamic cache for the page (verified: sg_cachepress_purge_cache( $url )).
	 *
	 * @param int $post_id Page ID.
	 */
	public static function purge_cache( int $post_id ): void {
		if ( function_exists( 'sg_cachepress_purge_cache' ) && 'publish' === get_post_status( $post_id ) ) {
			sg_cachepress_purge_cache( (string) get_permalink( $post_id ) );
		}
	}
}

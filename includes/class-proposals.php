<?php
/**
 * Automatic translation proposals, always to be reviewed (SPECIFICHE.md, "Proposte di
 * traduzione automatica"). Sources, in order:
 *   1. translation memory (same text already translated on another page);
 *   2. other services through the "ept_pre_translation" filter;
 *   3. DeepL API (Free or Pro), when a key is configured.
 * Never sent: texts marked "same as original" or excluded, dynamic content,
 * code of HTML widgets.
 *
 * @package EPT
 */

namespace EPT;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Proposals.
 */
final class Proposals {

	public const USAGE_OPTION = 'ept_deepl_usage';
	public const USAGE_CACHE  = 'ept_deepl_api_usage';

	/**
	 * DeepL client, or null without a key.
	 */
	public static function client(): ?Deepl {
		$key = (string) Settings::get( 'deepl_key' );
		if ( empty( Settings::get( 'deepl_enabled' ) ) || '' === trim( $key ) ) {
			return null;
		}
		return new Deepl( $key, (string) Settings::get( 'deepl_endpoint' ) );
	}

	/**
	 * Proposals for rows of the translation screen.
	 *
	 * @param WP_Post      $source        Italian page.
	 * @param string       $lang          Language code.
	 * @param list<string> $keys          Row keys to propose; empty = every row without a saved translation.
	 * @param bool         $skip_memory   Skip the memory ("Ripeti proposta" asks the service again).
	 * @param bool         $include_title Also propose the page title.
	 * @return array{proposals: array<string,array{html:string,plain:?string,source:string}>, title: ?string, error: ?string, sent: int}
	 */
	public static function propose( WP_Post $source, string $lang, array $keys = array(), bool $skip_memory = false, bool $include_title = false ): array {
		$language = Languages::get( $lang );
		$saved    = Translations::strings( $source->ID, $lang );
		$items    = Content::extractor()->extract( Content::elements( $source->ID ) )['strings'];
		$wanted   = array_flip( $keys );
		$texts    = array();
		$meta     = array();
		foreach ( $items as $item ) {
			if ( $item['dynamic'] || Widget_Map::CODE === $item['format'] ) {
				continue;
			}
			if ( $keys ? ! isset( $wanted[ $item['key'] ] ) : isset( $saved[ $item['key'] ] ) ) {
				continue;
			}
			$status = $saved[ $item['key'] ]['s'] ?? '';
			if ( in_array( $status, array( Translations::STATUS_SAME, Translations::STATUS_EXCLUDED ), true ) ) {
				continue;
			}
			$texts[ $item['key'] ] = Html_Tools::strip_noise( $item['text'] );
			$meta[ $item['key'] ]  = $item;
		}

		$out = array(
			'proposals' => array(),
			'title'     => null,
			'error'     => null,
			'sent'      => 0,
		);

		// 1. Memory.
		if ( ! $skip_memory ) {
			$memory = Memory::suggestions( $lang, array_map( static fn( array $i ): string => $i['hash'], $meta ) );
			foreach ( $meta as $key => $item ) {
				if ( isset( $memory[ $item['hash'] ] ) ) {
					$out['proposals'][ $key ] = self::proposal( $item, $memory[ $item['hash'] ], 'memory' );
					unset( $texts[ $key ] );
				}
			}
		}

		$context = $source->post_title;

		// 2. Other services.
		if ( $texts ) {
			/**
			 * Proposals from other translation services, used before DeepL.
			 *
			 * @param array<string,string> $proposals Proposals found so far (key => translated text).
			 * @param array<string,string> $texts     Texts to translate (key => Italian text, may contain HTML).
			 * @param string               $lang      Target language code.
			 * @param array<string,mixed>  $language  Target language (code, locale, name, prefix …).
			 * @param string               $context   Page title, as context.
			 */
			$filtered = apply_filters( 'ept_pre_translation', array(), $texts, $lang, $language, $context );
			foreach ( is_array( $filtered ) ? $filtered : array() as $key => $translation ) {
				if ( isset( $texts[ $key ] ) && is_string( $translation ) && '' !== trim( $translation ) ) {
					$out['proposals'][ $key ] = self::proposal( $meta[ $key ], $translation, 'filter' );
					unset( $texts[ $key ] );
				}
			}
		}

		// 3. DeepL.
		$client = self::client();
		$titles = array();
		if ( $include_title && '' !== trim( $source->post_title ) ) {
			$titles['__title'] = $source->post_title;
		}
		if ( ( $texts || $titles ) && null === $client ) {
			if ( $texts ) {
				$out['error'] = __( 'Proposte DeepL non attive (facoltative, Impostazioni → Traduzioni). Puoi tradurre a mano o usare "Copia testi da tradurre" con Google Traduttore.', 'ept' );
			}
			return $out;
		}
		if ( null === $client ) {
			return $out;
		}
		$target = Deepl::target_lang( (string) $language['locale'] );
		$from   = Deepl::source_lang( (string) Languages::default_language()['locale'] );

		$html  = array_filter( $texts, array( Text_Heuristics::class, 'has_html' ) );
		$plain = array_diff_key( $texts, $html ) + $titles;
		foreach ( array(
			true  => $html,
			false => $plain,
		) as $is_html => $group ) {
			if ( ! $group ) {
				continue;
			}
			$result = $client->translate( $group, $target, $from, (bool) $is_html, $context );
			if ( is_wp_error( $result ) ) {
				$out['error'] = $result->get_error_message();
				break;
			}
			$out['sent'] += self::count_chars( $group );
			foreach ( $result as $key => $translation ) {
				if ( '__title' === $key ) {
					$out['title'] = $translation;
				} else {
					$out['proposals'][ $key ] = self::proposal( $meta[ $key ], $translation, 'deepl' );
				}
			}
		}
		self::add_usage( $out['sent'] );
		return $out;
	}

	/**
	 * Proposal entry, with the plain-text form when it keeps the original wrapper.
	 *
	 * @param array<string,mixed> $item        Extracted string.
	 * @param string              $translation Proposed HTML/text.
	 * @param string              $source      memory|filter|deepl.
	 * @return array{html:string,plain:?string,source:string}
	 */
	private static function proposal( array $item, string $translation, string $source ): array {
		$space    = 'text-editor' !== $item['widget'];
		$original = Simple_Text::analyze( Html_Tools::strip_noise( $item['text'] ), $space );
		$plain    = null !== $original ? Simple_Text::plain_translation( $translation, $original, $space ) : null;
		return array(
			'html'   => $translation,
			'plain'  => $plain,
			'source' => $source,
		);
	}

	/**
	 * Characters sent (Unicode code points, as DeepL counts them).
	 *
	 * @param array<string,string> $texts Texts.
	 */
	private static function count_chars( array $texts ): int {
		return array_sum( array_map( static fn( string $t ): int => mb_strlen( $t ), $texts ) );
	}

	/**
	 * Add to the monthly counter of characters sent by this site.
	 *
	 * @param int $chars Characters.
	 */
	private static function add_usage( int $chars ): void {
		if ( $chars <= 0 ) {
			return;
		}
		$usage = self::month_usage();
		update_option(
			self::USAGE_OPTION,
			array(
				'month' => gmdate( 'Y-m' ),
				'chars' => $usage + $chars,
			),
			false
		);
		delete_transient( self::USAGE_CACHE );
	}

	/**
	 * Characters sent by this site in the current month.
	 */
	public static function month_usage(): int {
		$usage = get_option( self::USAGE_OPTION, array() );
		return is_array( $usage ) && ( $usage['month'] ?? '' ) === gmdate( 'Y-m' ) ? (int) $usage['chars'] : 0;
	}

	/**
	 * Account usage from DeepL (cached for 10 minutes), or null.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array{count:int,limit:int}|\WP_Error|null
	 */
	public static function api_usage( bool $refresh = false ) {
		$client = self::client();
		if ( null === $client ) {
			return null;
		}
		$cached = $refresh ? false : get_transient( self::USAGE_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$usage = $client->usage();
		if ( ! is_wp_error( $usage ) ) {
			set_transient( self::USAGE_CACHE, $usage, 10 * MINUTE_IN_SECONDS );
		}
		return $usage;
	}

	/**
	 * Usage summary for the screen and settings: counter, account usage, near-limit warning.
	 *
	 * @return array<string,mixed>
	 */
	public static function usage_summary(): array {
		$api = self::api_usage();
		$out = array(
			'configured' => null !== self::client(),
			'site_month' => self::month_usage(),
			'count'      => null,
			'limit'      => null,
			'warning'    => '',
		);
		if ( is_array( $api ) ) {
			$out['count'] = $api['count'];
			$out['limit'] = $api['limit'];
			if ( $api['limit'] > 0 && $api['count'] >= 0.8 * $api['limit'] ) {
				$out['warning'] = sprintf(
					/* translators: 1: characters used, 2: monthly limit. */
					__( 'Attenzione: hai usato %1$s caratteri DeepL su %2$s disponibili questo mese.', 'ept' ),
					number_format_i18n( $api['count'] ),
					number_format_i18n( $api['limit'] )
				);
			}
		}
		return $out;
	}
}

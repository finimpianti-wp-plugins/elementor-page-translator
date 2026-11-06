<?php
/**
 * DeepL API client (text translation and usage).
 *
 * Verified on the DeepL documentation (developers.deepl.com, 2026-11-06):
 * - Free keys end with ":fx" and use https://api-free.deepl.com, others https://api.deepl.com;
 * - authentication only with the header "Authorization: DeepL-Auth-Key <key>"
 *   (auth_key in body/query was removed in 2025);
 * - POST /v2/translate with JSON {text: [...], target_lang, source_lang, tag_handling: "html",
 *   tag_handling_version: "v2", context}; "context" is not billed; request size max 128 KiB;
 * - GET /v2/usage returns character_count and character_limit;
 * - errors: 403 bad key, 456 quota exhausted (do not retry), 429/5xx retry with backoff.
 *
 * @package EPT
 */

namespace EPT;

use WP_Error;

defined( 'ABSPATH' ) || defined( 'EPT_UNIT_TESTS' ) || exit;

/**
 * DeepL.
 */
final class Deepl {

	/**
	 * Keep requests well under the 128 KiB limit.
	 */
	public const MAX_BATCH_BYTES = 100000;
	public const MAX_BATCH_TEXTS = 50;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string $key API key.
	 */
	public function __construct( string $key ) {
		$this->key = trim( $key );
	}

	/**
	 * DeepL target language from a WordPress locale (en_GB => EN-GB, es_ES => ES).
	 *
	 * @param string $locale Locale.
	 */
	public static function target_lang( string $locale ): string {
		$parts  = explode( '_', $locale );
		$lang   = strtolower( $parts[0] );
		$region = strtoupper( $parts[1] ?? '' );
		switch ( $lang ) {
			case 'en':
				return 'US' === $region ? 'EN-US' : 'EN-GB';
			case 'pt':
				return 'BR' === $region ? 'PT-BR' : 'PT-PT';
			case 'zh':
				return in_array( $region, array( 'TW', 'HK' ), true ) ? 'ZH-HANT' : 'ZH-HANS';
			default:
				return strtoupper( $lang );
		}
	}

	/**
	 * DeepL source language from a WordPress locale (it_IT => IT).
	 *
	 * @param string $locale Locale.
	 */
	public static function source_lang( string $locale ): string {
		return strtoupper( explode( '_', $locale )[0] );
	}

	/**
	 * API base URL for a key.
	 *
	 * @param string $key Key.
	 */
	public static function base_url( string $key ): string {
		return str_ends_with( trim( $key ), ':fx' ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
	}

	/**
	 * Masked key for display: only the last 4 characters (and the ":fx" suffix) are shown.
	 *
	 * @param string $key Key.
	 */
	public static function mask( string $key ): string {
		$key = trim( $key );
		if ( '' === $key ) {
			return '';
		}
		$suffix = str_ends_with( $key, ':fx' ) ? ':fx' : '';
		$core   = '' !== $suffix ? substr( $key, 0, -3 ) : $key;
		return str_repeat( '•', 8 ) . substr( $core, -4 ) . $suffix;
	}

	/**
	 * Split texts (keyed) into batches that respect the request limits.
	 *
	 * @param array<string,string> $texts key => text.
	 * @return list<array<string,string>>
	 */
	public static function batches( array $texts ): array {
		$batches = array();
		$current = array();
		$bytes   = 0;
		foreach ( $texts as $key => $text ) {
			$size = strlen( (string) json_encode( $text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) + 1; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure size estimate.
			if ( $current && ( count( $current ) >= self::MAX_BATCH_TEXTS || $bytes + $size > self::MAX_BATCH_BYTES ) ) {
				$batches[] = $current;
				$current   = array();
				$bytes     = 0;
			}
			$current[ $key ] = $text;
			$bytes          += $size;
		}
		if ( $current ) {
			$batches[] = $current;
		}
		return $batches;
	}

	/**
	 * Translate texts.
	 *
	 * @param array<string,string> $texts   key => text.
	 * @param string               $target  DeepL target language.
	 * @param string               $source  DeepL source language.
	 * @param bool                 $html    Use HTML tag handling.
	 * @param string               $context Context (not billed).
	 * @return array<string,string>|WP_Error key => translation
	 */
	public function translate( array $texts, string $target, string $source, bool $html, string $context = '' ) {
		$out = array();
		foreach ( self::batches( $texts ) as $batch ) {
			$body = array(
				'text'        => array_values( $batch ),
				'target_lang' => $target,
				'source_lang' => $source,
			);
			if ( $html ) {
				$body['tag_handling']         = 'html';
				$body['tag_handling_version'] = 'v2';
			}
			if ( '' !== $context ) {
				$body['context'] = $context;
			}
			$response = $this->request( 'POST', '/v2/translate', $body );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$translations = $response['translations'] ?? null;
			if ( ! is_array( $translations ) || count( $translations ) !== count( $batch ) ) {
				return new WP_Error( 'ept_deepl_response', __( 'Risposta di DeepL non valida.', 'ept' ) );
			}
			foreach ( array_keys( $batch ) as $i => $key ) {
				$out[ $key ] = (string) ( $translations[ $i ]['text'] ?? '' );
			}
		}
		return $out;
	}

	/**
	 * Usage of the current billing period.
	 *
	 * @return array{count:int,limit:int}|WP_Error
	 */
	public function usage() {
		$response = $this->request( 'GET', '/v2/usage' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return array(
			'count' => (int) ( $response['character_count'] ?? 0 ),
			'limit' => (int) ( $response['character_limit'] ?? 0 ),
		);
	}

	/**
	 * HTTP request with one retry on 429/5xx.
	 *
	 * @param string                   $method Method.
	 * @param string                   $path   Path.
	 * @param array<string,mixed>|null $body   JSON body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		if ( '' === $this->key ) {
			return new WP_Error( 'ept_deepl_no_key', __( 'Chiave API di DeepL non impostata (Impostazioni → Traduzioni).', 'ept' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'DeepL-Auth-Key ' . $this->key,
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'ElementorPageTranslator/' . ( defined( 'EPT_VERSION' ) ? EPT_VERSION : 'dev' ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$url = self::base_url( $this->key ) . $path;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'ept_deepl_network', __( 'DeepL non risponde: controlla la connessione del server e riprova.', 'ept' ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( ( 429 === $code || $code >= 500 ) && 0 === $attempt ) {
				sleep( 1 );
				continue;
			}
			break;
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'ept_deepl_http_' . $code, self::error_message( $code ), array( 'status' => $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'ept_deepl_response', __( 'Risposta di DeepL non valida.', 'ept' ) );
	}

	/**
	 * Message for an HTTP status code.
	 *
	 * @param int $code Status code.
	 */
	public static function error_message( int $code ): string {
		switch ( $code ) {
			case 403:
				return __( 'Chiave API di DeepL non valida o non autorizzata.', 'ept' );
			case 456:
				return __( 'Hai esaurito i caratteri del piano DeepL per questo mese.', 'ept' );
			case 429:
				return __( 'Troppe richieste a DeepL: riprova tra poco.', 'ept' );
			case 400:
				return __( 'DeepL ha rifiutato la richiesta (lingua non supportata o testo non valido).', 'ept' );
			default:
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'Errore di DeepL (codice %d): riprova più tardi.', 'ept' ), $code );
		}
	}
}

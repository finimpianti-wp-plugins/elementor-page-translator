<?php
/**
 * Public API for other plugins and themes (SPECIFICHE.md, "API per i plugin personalizzati").
 *
 * Stable functions: call them behind function_exists(), so the caller keeps working when
 * this plugin is not active:
 *
 *     $lang = function_exists( 'ept_current_language' ) ? ept_current_language() : 'it';
 *
 * Filters and actions: ept_translatable_fields, ept_link_proposal, ept_pre_translation,
 * ept_after_sync (see docs/guida.md).
 *
 * @package EPT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Code of the current language ("it", "en"…). On the front end it is known from the 'wp'
 * hook on (priority 1); before that, and in the admin, it is the default language.
 */
function ept_current_language(): string {
	return EPT\Current_Language::code();
}

/**
 * Code of the default language (the language of the original pages).
 */
function ept_default_language(): string {
	return EPT\Languages::default_code();
}

/**
 * Active languages, the default one first.
 *
 * @return array<string,array{code:string,locale:string,name:string,prefix:string,default:bool,public:bool}>
 *         Keyed by code. "prefix" is the first URL segment ("en"), empty for the default
 *         language; "public" is false for languages still hidden from visitors.
 */
function ept_languages(): array {
	$default = EPT\Languages::default_language();
	$out     = array(
		(string) $default['code'] => array(
			'code'    => (string) $default['code'],
			'locale'  => (string) $default['locale'],
			'name'    => (string) $default['name'],
			'prefix'  => '',
			'default' => true,
			'public'  => true,
		),
	);
	foreach ( EPT\Languages::active() as $code => $lang ) {
		$out[ (string) $code ] = array(
			'code'    => (string) $code,
			'locale'  => (string) $lang['locale'],
			'name'    => (string) $lang['name'],
			'prefix'  => (string) $lang['prefix'],
			'default' => false,
			'public'  => ! empty( $lang['public'] ),
		);
	}
	return $out;
}

/**
 * ID of the version of a page in a language, or null.
 * Accepts the original page or any of its translations.
 *
 * @param int    $post_id Page ID.
 * @param string $lang    Language code.
 */
function ept_get_translation_id( int $post_id, string $lang ): ?int {
	$source = EPT\Translations::is_source( $post_id ) ? $post_id : EPT\Translations::source_of( $post_id );
	if ( ! $source || ! get_post( $source ) ) {
		return null;
	}
	if ( EPT\Languages::default_code() === $lang ) {
		return $source;
	}
	return EPT\Translations::get_id( $source, $lang );
}

/**
 * URL of the version of a page in a language; when it is missing (or not visible to the
 * current visitor) the home of that language; '' for unknown or hidden languages.
 *
 * @param int    $post_id Page ID (original or translation).
 * @param string $lang    Language code.
 */
function ept_get_permalink( int $post_id, string $lang ): string {
	if ( EPT\Languages::default_code() !== $lang && null === EPT\Languages::get( $lang ) ) {
		return '';
	}
	$source = EPT\Translations::is_source( $post_id ) ? $post_id : EPT\Translations::source_of( $post_id );
	$url    = $source ? EPT\Switcher::page_url( $source, $lang ) : '';
	return '' !== $url ? $url : EPT\Switcher::home_url( $lang );
}

/**
 * Language for complex third-party components with only two versions (cookie banner and
 * similar): the default language on its pages, English on all the others.
 */
function ept_fallback_language(): string {
	$default = EPT\Languages::default_code();
	return ept_current_language() === $default ? $default : 'en';
}

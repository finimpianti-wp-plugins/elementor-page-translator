<?php
/**
 * REST routes of the translation screen (namespace ept/v1).
 *
 * GET  /translations/{id}/{lang}            screen data
 * POST /translations/{id}/{lang}            save draft
 * POST /translations/{id}/{lang}/generate   create or update the translated page
 *
 * Permissions: edit_page on the Italian page (and edit_page on the existing translation).
 * The REST cookie nonce (X-WP-Nonce) is required by WordPress for logged-in requests.
 *
 * @package EPT
 */

namespace EPT\Rest;

use EPT\Proposals;
use EPT\Translation_Service;
use EPT\Translations;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Translation controller.
 */
final class Translation_Controller {

	public const NAMESPACE = 'ept/v1';

	/**
	 * Hook route registration.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register(): void {
		$args = array(
			'id'   => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
			'lang' => array(
				'type'     => 'string',
				'required' => true,
				'pattern'  => '^[a-z]{2,3}(-[a-z0-9]{2,8})?$',
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>\d+)/(?P<lang>[a-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get' ),
					'permission_callback' => array( self::class, 'can_translate' ),
					'args'                => $args,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => array( self::class, 'can_translate' ),
					'args'                => $args,
				),
			)
		);
		self::register_propose( $args );
		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>\d+)/(?P<lang>[a-z0-9-]+)/relink',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'relink' ),
				'permission_callback' => array( self::class, 'can_translate' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>\d+)/(?P<lang>[a-z0-9-]+)/generate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'generate' ),
				'permission_callback' => array( self::class, 'can_translate' ),
				'args'                => array_merge(
					$args,
					array(
						'confirm' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
			)
		);
	}

	/**
	 * Register the proposals route (called from register()).
	 *
	 * @param array<string,mixed> $args Common route arguments.
	 */
	private static function register_propose( array $args ): void {
		register_rest_route(
			self::NAMESPACE,
			'/translations/(?P<id>\d+)/(?P<lang>[a-z0-9-]+)/propose',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'propose' ),
				'permission_callback' => array( self::class, 'can_translate' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * POST automatic proposals (memory, filter, DeepL). Body: keys[], skip_memory, include_title.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function propose( WP_REST_Request $request ) {
		$source = Translation_Service::validate( (int) $request['id'], (string) $request['lang'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$body   = (array) $request->get_json_params();
		$keys   = array_values( array_filter( array_map( 'strval', (array) ( $body['keys'] ?? array() ) ) ) );
		$lang   = (string) $request['lang'];
		$result = Proposals::propose( $source, $lang, $keys, ! empty( $body['skip_memory'] ), ! empty( $body['include_title'] ) );

		// Proposals are saved right away as unconfirmed drafts: reopening the screen does not send them again.
		$strings = array();
		foreach ( $result['proposals'] as $key => $proposal ) {
			$strings[ $key ] = array(
				't'  => $proposal['html'],
				's'  => '',
				'ok' => true,
				'p'  => $proposal['source'],
			);
		}
		$draft = array( 'strings' => $strings );
		if ( null !== $result['title'] && '' === (string) ( \EPT\Translations::page_fields( $source->ID, $lang )['title'] ?? '' ) ) {
			$draft['page'] = array( 'title' => $result['title'] );
		}
		if ( ! empty( $result['seo'] ) ) {
			$seo = array( 'alts' => array() );
			foreach ( $result['seo'] as $key => $value ) {
				if ( '__seo_title' === $key ) {
					$seo['title'] = $value;
				} elseif ( '__seo_description' === $key ) {
					$seo['description'] = $value;
				} elseif ( str_starts_with( (string) $key, '__alt_' ) ) {
					$seo['alts'][ (int) substr( (string) $key, 6 ) ] = $value;
				}
			}
			$draft['seo'] = $seo;
		}
		if ( $strings || isset( $draft['page'] ) || isset( $draft['seo'] ) ) {
			Translation_Service::save_draft( $source, $lang, $draft );
		}

		return rest_ensure_response(
			array(
				'count'  => count( $result['proposals'] ) + count( $result['seo'] ?? array() ),
				'title'  => $result['title'],
				'error'  => $result['error'],
				'sent'   => $result['sent'],
				'usage'  => Proposals::usage_summary(),
				'screen' => Translation_Service::screen_data( $source, $lang ),
			)
		);
	}

	/**
	 * POST update the links of the pages that still point to the Italian version of this page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function relink( WP_REST_Request $request ) {
		$source = Translation_Service::validate( (int) $request['id'], (string) $request['lang'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$lang    = (string) $request['lang'];
		$updated = array();
		foreach ( Translation_Service::relink_candidates( $source->ID, $lang ) as $page ) {
			if ( \EPT\Links::relink( $page['id'] ) ) {
				$updated[] = $page;
			}
		}
		return rest_ensure_response(
			array(
				'updated' => $updated,
				'screen'  => Translation_Service::screen_data( $source, $lang ),
			)
		);
	}

	/**
	 * Permission check: edit the Italian page and its translation, if any.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function can_translate( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Non hai i permessi per tradurre questa pagina.', 'ept' ), array( 'status' => rest_authorization_required_code() ) );
		}
		$existing = Translations::get_id( $id, (string) $request['lang'] );
		if ( null !== $existing && ! current_user_can( 'edit_post', $existing ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Non hai i permessi per modificare la pagina tradotta.', 'ept' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * GET screen data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get( WP_REST_Request $request ) {
		$source = Translation_Service::validate( (int) $request['id'], (string) $request['lang'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		return rest_ensure_response( Translation_Service::screen_data( $source, (string) $request['lang'] ) );
	}

	/**
	 * POST save draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save( WP_REST_Request $request ) {
		$source = Translation_Service::validate( (int) $request['id'], (string) $request['lang'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$body   = (array) $request->get_json_params();
		$result = Translation_Service::save_draft( $source, (string) $request['lang'], $body );
		return rest_ensure_response(
			array_merge(
				$result,
				array( 'screen' => Translation_Service::screen_data( $source, (string) $request['lang'] ) )
			)
		);
	}

	/**
	 * POST generate the translated page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function generate( WP_REST_Request $request ) {
		$source = Translation_Service::validate( (int) $request['id'], (string) $request['lang'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$body      = (array) $request->get_json_params();
		$confirm   = ! empty( $body['confirm'] ) || (bool) $request['confirm'];
		$overwrite = ! empty( $body['overwrite'] );
		$result    = Translation_Service::generate( $source, (string) $request['lang'], $body, $confirm, $overwrite );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['screen'] = Translation_Service::screen_data( $source, (string) $request['lang'] );
		return rest_ensure_response( $result );
	}
}

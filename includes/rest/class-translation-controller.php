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
	 * Permission check: edit the Italian page and its translation, if any.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function can_translate( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! current_user_can( 'edit_pages' ) || ! current_user_can( 'edit_page', $id ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Non hai i permessi per tradurre questa pagina.', 'ept' ), array( 'status' => rest_authorization_required_code() ) );
		}
		$existing = Translations::get_id( $id, (string) $request['lang'] );
		if ( null !== $existing && ! current_user_can( 'edit_page', $existing ) ) {
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
		$body    = (array) $request->get_json_params();
		$confirm = ! empty( $body['confirm'] ) || (bool) $request['confirm'];
		$result  = Translation_Service::generate( $source, (string) $request['lang'], $body, $confirm );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['screen'] = Translation_Service::screen_data( $source, (string) $request['lang'] );
		return rest_ensure_response( $result );
	}
}

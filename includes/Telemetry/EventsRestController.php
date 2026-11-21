<?php
/**
 * REST route for usage events that originate in the admin screens.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST /plume/v1/telemetry/event.
 *
 * Accepts only the events in EventSchema::CLIENT_EVENTS, each with its own capability.
 * The event is only queued (never sent from this request), and nothing happens unless
 * the site owner opted in. The answer is always 202, so the browser learns nothing
 * about the consent state.
 *
 * @since NEXT_VERSION
 */
final class EventsRestController {

	/**
	 * Register the route.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'plume/v1',
			'/telemetry/event',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => [ self::class, 'check_permission' ],
				'args'                => [
					'event' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => array_keys( EventSchema::CLIENT_EVENTS ),
					],
					'props' => [
						'type'                 => 'object',
						'required'             => false,
						'default'              => [],
						'additionalProperties' => [
							'type' => [ 'string', 'integer' ],
						],
						'maxProperties'        => 5,
					],
				],
			]
		);
	}

	/**
	 * Check the capability required for the requested event.
	 *
	 * @since NEXT_VERSION
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|\WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ): bool|\WP_Error {
		$event = $request->get_param( 'event' );
		$cap   = is_string( $event ) ? ( EventSchema::CLIENT_EVENTS[ $event ] ?? '' ) : '';
		if ( '' === $cap || ! current_user_can( $cap ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do that.', 'plume' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Queue the event and answer 202.
	 *
	 * @since NEXT_VERSION
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$event = (string) $request->get_param( 'event' );
		$props = $request->get_param( 'props' );
		if ( isset( EventSchema::CLIENT_EVENTS[ $event ] ) ) {
			Telemetry::record( $event, is_array( $props ) ? $props : [] );
		}
		return new WP_REST_Response( null, 202 );
	}
}

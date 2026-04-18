<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Payments;

use WP_REST_Request;
use WP_REST_Response;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NJ_LemonSqueezy_Webhook {

	public static function register_routes(): void {
		register_rest_route(
			'wp-ai-mind/v1',
			'/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => '__return_true', // Signature verified inside handle().
			]
		);
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body      = $request->get_body();
		$signature = $request->get_header( 'X-Signature' );
		$secret    = defined( 'WP_AI_MIND_LS_SECRET' ) ? WP_AI_MIND_LS_SECRET : '';

		if ( ! NJ_Webhook_Verifier::verify( $body, $signature, $secret ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
		}

		$event = json_decode( $body, true );
		$email = $event['data']['attributes']['user_email'] ?? '';

		if ( empty( $email ) ) {
			return new WP_REST_Response( [ 'error' => 'No email in payload' ], 400 );
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new WP_REST_Response(
				[
					'received' => true,
					'action'   => 'skipped',
					'reason'   => 'user_not_found',
				],
				200
			);
		}

		self::apply_event( $event['meta']['event_name'] ?? '', $user->ID );

		return new WP_REST_Response( [ 'received' => true ] );
	}

	private static function apply_event( string $event_name, int $user_id ): void {
		switch ( $event_name ) {
			case 'subscription_created':
			case 'subscription_resumed':
				NJ_Tier_Manager::set_user_tier( 'pro_managed', $user_id );
				break;

			case 'subscription_cancelled':
			case 'subscription_expired':
				NJ_Tier_Manager::set_user_tier( 'free', $user_id );
				break;

			case 'order_created':
				// One-time purchase for Pro BYOK.
				NJ_Tier_Manager::set_user_tier( 'pro_byok', $user_id );
				break;
		}
	}
}

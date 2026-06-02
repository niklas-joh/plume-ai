<?php
/**
 * REST controller for developer tools: tier switching and usage manipulation.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST endpoints for the developer tools page.
 *
 * All routes live under /wp-ai-mind/v1/dev/ and require both the manage_options
 * capability and a valid WP_AI_MIND_DEV_KEY constant. Routes are only registered
 * when the constant is defined, so they return 404 on sites without the key.
 *
 * @since 1.11.0
 */
class DevToolsRestController {

	/**
	 * REST API namespace shared by all developer tools routes.
	 *
	 * @since 1.11.0
	 */
	private const REST_NAMESPACE = 'wp-ai-mind/v1';

	/**
	 * Register all developer tools REST routes.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/dev/status',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'handle_status' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/dev/set-tier',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_set_tier' ],
				'permission_callback' => [ self::class, 'check_permission' ],
				'args'                => [
					'tier' => [
						'type'     => 'string',
						'required' => true,
						'enum'     => NJ_Tier_Config::TIERS,
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/dev/reset-usage',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_reset_usage' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/dev/set-ceiling',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'handle_set_ceiling' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			]
		);
	}

	/**
	 * Return the current tier and usage state for the logged-in user.
	 *
	 * @since 1.11.0
	 * @return WP_REST_Response
	 */
	public static function handle_status(): WP_REST_Response {
		$user_id     = get_current_user_id();
		$usage       = NJ_Usage_Tracker::get_usage( $user_id );
		$tier_labels = NJ_Tier_Config::get_tier_labels();

		if ( null === $usage['limit'] ) {
			$display = __( 'Unlimited', 'wp-ai-mind' );
		} else {
			$display = number_format_i18n( $usage['used'] ) . ' / ' . number_format_i18n( $usage['limit'] ) . ' ' . __( 'tokens', 'wp-ai-mind' );
		}

		return new WP_REST_Response(
			[
				'tier'          => $usage['tier'],
				'tier_label'    => $tier_labels[ $usage['tier'] ] ?? $usage['tier'],
				'used'          => $usage['used'],
				'limit'         => $usage['limit'],
				'remaining'     => $usage['remaining'],
				'can_use'       => $usage['can_use'],
				'usage_display' => $display,
			],
			200
		);
	}

	/**
	 * Switch the site-wide tier to the requested slug.
	 *
	 * Calls NJ_Tier_Manager::set_site_tier() which handles HMAC signing so the
	 * new value passes signature verification on all subsequent requests.
	 *
	 * @since 1.11.0
	 * @param WP_REST_Request $request REST request containing 'tier'.
	 * @return WP_REST_Response
	 */
	public static function handle_set_tier( WP_REST_Request $request ): WP_REST_Response {
		$tier = $request->get_param( 'tier' );
		$ok   = NJ_Tier_Manager::set_site_tier( $tier );

		if ( ! $ok ) {
			return new WP_REST_Response(
				[
					'success' => false,
					'message' => __( 'Failed to update tier.', 'wp-ai-mind' ),
				],
				500
			);
		}

		$labels = NJ_Tier_Config::get_tier_labels();
		return new WP_REST_Response(
			[
				'success' => true,
				/* translators: %s: human-readable tier name e.g. "Pro Managed" */
				'message' => sprintf( __( 'Tier switched to %s.', 'wp-ai-mind' ), $labels[ $tier ] ?? $tier ),
			],
			200
		);
	}

	/**
	 * Reset this month's token counter to zero for the current user.
	 *
	 * @since 1.11.0
	 * @return WP_REST_Response
	 */
	public static function handle_reset_usage(): WP_REST_Response {
		$user_id = get_current_user_id();
		$key     = NJ_Usage_Tracker::get_current_month_key();
		update_user_meta( $user_id, $key, 0 );

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Usage reset to zero.', 'wp-ai-mind' ),
			],
			200
		);
	}

	/**
	 * Set this month's token counter to the current tier's monthly ceiling.
	 *
	 * For unlimited tiers (pro_byok) there is no ceiling to set; a descriptive
	 * message is returned and success is still true.
	 *
	 * @since 1.11.0
	 * @return WP_REST_Response
	 */
	public static function handle_set_ceiling(): WP_REST_Response {
		$user_id = get_current_user_id();
		$tier    = NJ_Tier_Manager::get_user_tier( $user_id );
		$limit   = NJ_Tier_Manager::get_monthly_limit( $tier );

		if ( null === $limit ) {
			return new WP_REST_Response(
				[
					'success' => true,
					'message' => __( 'Pro BYOK has no ceiling — usage is unlimited.', 'wp-ai-mind' ),
				],
				200
			);
		}

		$key = NJ_Usage_Tracker::get_current_month_key();
		update_user_meta( $user_id, $key, $limit );

		return new WP_REST_Response(
			[
				'success' => true,
				/* translators: %s: formatted token count e.g. "50,000" */
				'message' => sprintf( __( 'Usage set to ceiling: %s tokens.', 'wp-ai-mind' ), number_format_i18n( $limit ) ),
			],
			200
		);
	}

	/**
	 * Verify the request comes from an admin with a valid dev key.
	 *
	 * Delegates to DevToolsPage::is_active() which checks both the constant and
	 * the manage_options capability.
	 *
	 * @since 1.11.0
	 * @return bool|\WP_Error True when authorised, WP_Error with 403 otherwise.
	 */
	public static function check_permission(): bool|\WP_Error {
		if ( ! DevToolsPage::is_active() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Developer tools are not enabled on this site.', 'wp-ai-mind' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}
}

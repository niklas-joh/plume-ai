<?php
/**
 * REST controller for executing or dismissing pending AI-proposed plans.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Modules\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Tools\ToolExecutor;

/**
 * REST controller for pending plan execution.
 *
 * Route:
 *   POST /stilus/v1/plans/{id}/execute — execute an AI-proposed plan (create or update post).
 *
 * Plans are stored as WordPress transients keyed by user ID + plan ID, ensuring
 * only the owning user can execute them. Transients expire after one hour.
 */
class PlansRestController {

	private const NAMESPACE = 'stilus/v1';

	/**
	 * Inject dependencies for plan execution.
	 *
	 * @since 1.0.0
	 * @param ToolExecutor $executor Tool executor to delegate create/update calls.
	 */
	public function __construct(
		private ToolExecutor $executor,
	) {}

	/**
	 * Register the /plans REST route.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/plans/(?P<id>[a-f0-9]+)/execute',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'execute_plan' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Execute a pending plan by creating or updating a post.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request with plan ID in path.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute_plan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = \get_current_user_id();
		$plan_id = $request->get_param( 'id' );

		$plan = \get_transient( "stilus_plan_{$user_id}_{$plan_id}" );
		if ( false === $plan ) {
			return new \WP_Error(
				'plan_not_found',
				\__( 'This plan has expired or does not exist. Please ask the assistant again.', 'stilus' ),
				[ 'status' => 404 ]
			);
		}

		$tool_name = 'update' === ( $plan['plan_type'] ?? 'create' ) ? 'update_post' : 'create_post';
		$args      = $this->plan_to_tool_args( $plan );
		$result    = $this->executor->execute( $tool_name, $args, $user_id );

		if ( isset( $result['error'] ) ) {
			return new \WP_Error(
				'plan_execution_failed',
				$result['error'],
				[ 'status' => 422 ]
			);
		}

		\delete_transient( "stilus_plan_{$user_id}_{$plan_id}" );

		return new \WP_REST_Response(
			[
				'post_id'  => $result['post_id'],
				'edit_url' => \get_edit_post_link( $result['post_id'], 'raw' ),
			],
			200
		);
	}

	/**
	 * Require edit_posts capability to execute plans.
	 *
	 * @since 1.0.0
	 * @return bool|\WP_Error
	 */
	public function check_permission(): bool|\WP_Error {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'Insufficient permissions.', 'stilus' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a stored plan array into tool-executor arguments.
	 *
	 * @param array $plan Stored plan data from transient.
	 * @return array
	 */
	private function plan_to_tool_args( array $plan ): array {
		if ( 'update' === ( $plan['plan_type'] ?? 'create' ) ) {
			$args = [
				'post_id' => $plan['post_id'],
			];
			if ( ! empty( $plan['post_status'] ) ) {
				$args['status'] = $plan['post_status'];
			}
			// changes become the content to update; the AI will fill in full content
			// on the actual update — for now we pass it as a note in the post.
			if ( ! empty( $plan['changes'] ) ) {
				$args['content'] = $plan['changes'];
			}
			return $args;
		}

		return [
			'title'     => $plan['title'],
			'content'   => $plan['outline'] ?? '',
			'status'    => $plan['post_status'] ?? 'draft',
			'post_type' => $plan['post_type'] ?? 'post',
		];
	}
}

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

use Stilus\Core\RestApi;
use Stilus\Tools\PostWriter;
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

	/**
	 * Inject dependencies for plan execution.
	 *
	 * @since 1.8.0
	 * @since 1.9.0 Replaced the ToolExecutor dependency with PostWriter.
	 * @param PostWriter $post_writer PostWriter service for create/update operations.
	 */
	public function __construct(
		private PostWriter $post_writer,
	) {}

	/**
	 * Register the /plans REST route.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public function register_routes(): void {
		\register_rest_route(
			RestApi::API_NAMESPACE,
			'/plans/(?P<id>[a-f0-9]+)/execute',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'execute_plan' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'id'          => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'title'       => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'outline'     => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'content'     => [
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					],
					'changes'     => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'new_content' => [
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
					],
					'new_title'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'status'      => [
						'type' => 'string',
						'enum' => [ 'draft', 'publish', 'pending' ],
					],
				],
			]
		);
	}

	/**
	 * Execute a pending plan by creating or updating a post.
	 *
	 * @since 1.8.0
	 * @param \WP_REST_Request $request Incoming REST request with plan ID in path.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute_plan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user_id = \get_current_user_id();
		$plan_id = $request->get_param( 'id' );

		$plan = \get_transient( ToolExecutor::plan_transient_key( $user_id, $plan_id ) );
		if ( false === $plan ) {
			return new \WP_Error(
				'plan_not_found',
				\__( 'This plan has expired or does not exist. Please ask the assistant again.', 'stilus' ),
				[ 'status' => 404 ]
			);
		}

		// Merge request-body overrides so users can edit the plan before confirming.
		// 'outline'/'content' are only meaningful for create plans; they are harmlessly ignored by plan_to_tool_args for update plans.
		foreach ( [ 'title', 'outline', 'content', 'changes', 'new_content', 'new_title' ] as $field ) {
			$val = $request->get_param( $field );
			if ( null !== $val ) {
				$plan[ $field ] = $val;
			}
		}
		$status_override = $request->get_param( 'status' );
		if ( null !== $status_override ) {
			$plan['post_status'] = $status_override;
		}

		$args   = $this->plan_to_tool_args( $plan );
		$result = 'update' === ( $plan['plan_type'] ?? 'create' )
			? $this->post_writer->update( $args, $user_id )
			: $this->post_writer->create( $args, $user_id );

		if ( isset( $result['error'] ) ) {
			return new \WP_Error(
				'plan_execution_failed',
				$result['error'],
				[ 'status' => 422 ]
			);
		}

		\delete_transient( ToolExecutor::plan_transient_key( $user_id, $plan_id ) );

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
	 * @since 1.8.0
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
	 * @since 1.8.0
	 * @param array $plan Stored plan data from transient.
	 * @return array
	 */
	private function plan_to_tool_args( array $plan ): array {
		if ( 'update' === ( $plan['plan_type'] ?? 'create' ) ) {
			$args = [
				'post_id' => $plan['post_id'],
				'content' => $plan['new_content'] ?? '',
			];
			if ( ! empty( $plan['new_title'] ) ) {
				$args['title'] = $plan['new_title'];
			}
			if ( ! empty( $plan['post_status'] ) ) {
				$args['status'] = $plan['post_status'];
			}
			if ( ! empty( $plan['meta_fields'] ) ) {
				$args['meta_fields'] = $plan['meta_fields'];
			}
			return $args;
		}

		$args = [
			'title'     => $plan['title'],
			// Older pending plans stored before content became required only carry an outline.
			'content'   => ! empty( $plan['content'] ) ? $plan['content'] : ( $plan['outline'] ?? '' ),
			'status'    => $plan['post_status'] ?? 'draft',
			'post_type' => $plan['post_type'] ?? 'post',
		];
		if ( ! empty( $plan['meta_fields'] ) ) {
			$args['meta_fields'] = $plan['meta_fields'];
		}
		return $args;
	}
}

<?php
/**
 * REST controller for executing or dismissing pending AI-proposed plans.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Modules\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Core\RestApi;
use Plume\Tools\PostTypeCaps;
use Plume\Tools\PostWriter;
use Plume\Tools\ToolExecutor;

/**
 * REST controller for pending plan execution.
 *
 * Route:
 *   POST   /plume/v1/plans/{id}/execute — execute an AI-proposed plan (create or update post).
 *   DELETE /plume/v1/plans/{id}         — discard a pending plan without executing it.
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
				'permission_callback' => [ $this, 'check_execute_permission' ],
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

		\register_rest_route(
			RestApi::API_NAMESPACE,
			'/plans/(?P<id>[a-f0-9]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'dismiss_plan' ],
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
				\__( 'This plan has expired or does not exist. Please ask the assistant again.', 'plume' ),
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
	 * Discard a pending plan without executing it.
	 *
	 * Called by the review drawer before requesting a revision — otherwise the
	 * superseded plan's transient stays executable for up to an hour — and when
	 * the drawer is dismissed without applying. Deleting an already-expired or
	 * unknown transient is a no-op, so this is safe to call idempotently.
	 *
	 * @since 1.12.0
	 * @param \WP_REST_Request $request Incoming REST request with plan ID in path.
	 * @return \WP_REST_Response
	 */
	public function dismiss_plan( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id = \get_current_user_id();
		$plan_id = $request->get_param( 'id' );

		\delete_transient( ToolExecutor::plan_transient_key( $user_id, $plan_id ) );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Require the edit_posts capability to reach the plan routes at all.
	 *
	 * This is the coarse gate only. Executing a plan additionally requires the
	 * capabilities of the post type the plan targets — see check_execute_permission().
	 *
	 * @since 1.8.0
	 * @return bool|\WP_Error
	 */
	public function check_permission(): bool|\WP_Error {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				\__( 'Insufficient permissions.', 'plume' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Authorise execution against the post type and status the plan actually targets.
	 *
	 * `edit_posts` is the 'post' post type's edit capability, so on its own it would
	 * let a Contributor create a page or publish content. The plan's post type and
	 * post ID live in the transient rather than the request, so the plan is loaded
	 * here and the real capabilities checked before the handler runs.
	 *
	 * A missing or expired plan returns true so that execute_plan() can emit its
	 * existing 404 — a plan the caller cannot see must not become a 403.
	 *
	 * @since 1.13.2
	 * @param \WP_REST_Request $request Incoming REST request with plan ID in path.
	 * @return bool|\WP_Error True when permitted; WP_Error with 403 status otherwise.
	 */
	public function check_execute_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$coarse = $this->check_permission();
		if ( true !== $coarse ) {
			return $coarse;
		}

		$user_id = \get_current_user_id();
		$plan    = \get_transient( ToolExecutor::plan_transient_key( $user_id, (string) $request->get_param( 'id' ) ) );
		if ( ! is_array( $plan ) ) {
			return true;
		}

		$status_override = $request->get_param( 'status' );
		$status          = null !== $status_override ? $status_override : ( $plan['post_status'] ?? 'draft' );

		$caps = null;
		if ( 'update' === ( $plan['plan_type'] ?? 'create' ) ) {
			$post_id = \absint( $plan['post_id'] ?? 0 );
			$post    = 0 !== $post_id ? \get_post( $post_id ) : null;
			if ( null === $post ) {
				return true;
			}

			if ( ! \current_user_can( 'edit_post', $post_id ) ) {
				return $this->forbidden( 'rest_forbidden', \__( 'Sorry, you are not allowed to edit this content.', 'plume' ) );
			}

			$post_type = $post->post_type;
		} else {
			$post_type = \sanitize_key( $plan['post_type'] ?? 'post' );
			$caps      = PostTypeCaps::resolve( $post_type );
			if ( null === $caps ) {
				return $this->forbidden( 'rest_forbidden', \__( 'Sorry, you are not allowed to create content of this type.', 'plume' ) );
			}

			if ( ! \current_user_can( $caps['create'] ) ) {
				return $this->forbidden( 'rest_forbidden', \__( 'Sorry, you are not allowed to create content of this type.', 'plume' ) );
			}
		}

		if ( 'publish' === $status ) {
			// Reuse the create branch's lookup; only the update branch still needs to resolve.
			$caps ??= PostTypeCaps::resolve( $post_type );
			if ( null === $caps || ! \current_user_can( $caps['publish'] ) ) {
				return $this->forbidden( 'rest_cannot_publish', \__( 'Sorry, you are not allowed to publish this content.', 'plume' ) );
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a 403 WP_Error, mirroring core's REST error codes.
	 *
	 * @since 1.13.2
	 * @param string $code    Error code, e.g. rest_forbidden or rest_cannot_publish.
	 * @param string $message Translated, user-facing message.
	 * @return \WP_Error
	 */
	private function forbidden( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'status' => 403 ] );
	}

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

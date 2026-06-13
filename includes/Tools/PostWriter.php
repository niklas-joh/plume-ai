<?php
/**
 * Performs direct post create/update operations on behalf of approved plans.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Content\ContentNormaliser;

/**
 * Performs direct post create/update operations on behalf of approved plans.
 *
 * Intentionally not AI-callable — tools the AI sees use plan_post/plan_update,
 * which store transients for user approval. PlansRestController calls this class
 * after the user approves.
 *
 * @since 1.9.0
 */
class PostWriter {

	/**
	 * Inject the tool registry needed to validate allowed post types.
	 *
	 * @since 1.9.0
	 * @param ToolRegistry      $registry   Used for post-type validation.
	 * @param ContentNormaliser $normaliser Converts AI markdown to block markup before save.
	 */
	public function __construct(
		private ToolRegistry $registry,
		private ContentNormaliser $normaliser = new ContentNormaliser(),
	) {}

	/**
	 * Create a new post or page.
	 *
	 * @since 1.9.0
	 * @param array $args    Keyed: title (string), content (string), status (string), post_type (string), meta_fields (array).
	 * @param int   $user_id WordPress user ID performing the action.
	 * @return array Post data on success; ['error' => string] on failure.
	 */
	public function create( array $args, int $user_id ): array {
		if ( ! (bool) \get_option( 'plume_enable_write_tools', true ) ) {
			return [ 'error' => 'Write tools are disabled.' ];
		}

		if ( ! \user_can( $user_id, 'edit_posts' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$post_type = \sanitize_key( $args['post_type'] ?? 'post' );
		if ( ! \in_array( $post_type, $this->registry->allowed_post_types(), true ) ) {
			return [ 'error' => 'Post type not permitted.' ];
		}

		$title = \sanitize_text_field( $args['title'] ?? '' );
		if ( '' === $title ) {
			return [ 'error' => 'A post title is required.' ];
		}

		$content = \wp_kses_post( $this->normaliser->normalise( (string) ( $args['content'] ?? '' ) ) );
		$status  = \in_array( $args['status'] ?? 'draft', [ 'draft', 'publish', 'pending' ], true )
			? ( $args['status'] ?? 'draft' )
			: 'draft';

		$post_id = \wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_type'    => $post_type,
				'post_author'  => $user_id,
			],
			true
		);

		if ( \is_wp_error( $post_id ) ) {
			return [ 'error' => $post_id->get_error_message() ];
		}

		foreach ( $this->sanitize_meta_fields( $args['meta_fields'] ?? [] ) as $key => $value ) {
			\update_post_meta( $post_id, $key, $value );
		}

		return [
			'post_id'  => $post_id,
			'edit_url' => \get_edit_post_link( $post_id, 'raw' ),
			'title'    => $title,
			'status'   => $status,
		];
	}

	/**
	 * Update an existing post or page.
	 *
	 * @since 1.9.0
	 * @param array $args    Keyed: post_id (int), title (string?), content (string?), status (string?), meta_fields (array?).
	 * @param int   $user_id WordPress user ID performing the action.
	 * @return array ['post_id', 'updated' => true] on success; ['error' => string] on failure.
	 */
	public function update( array $args, int $user_id ): array {
		if ( ! (bool) \get_option( 'plume_enable_write_tools', true ) ) {
			return [ 'error' => 'Write tools are disabled.' ];
		}

		$post_id = \absint( $args['post_id'] ?? 0 );
		if ( 0 === $post_id ) {
			return [ 'error' => 'A valid post_id is required.' ];
		}

		if ( ! \user_can( $user_id, 'edit_post', $post_id ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$update_data = [ 'ID' => $post_id ];

		if ( isset( $args['title'] ) ) {
			$update_data['post_title'] = \sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['content'] ) ) {
			$update_data['post_content'] = \wp_kses_post( $this->normaliser->normalise( (string) $args['content'] ) );
		}

		if ( isset( $args['status'] ) ) {
			$update_data['post_status'] = \in_array( $args['status'], [ 'draft', 'publish', 'pending', 'private', 'trash' ], true )
				? $args['status']
				: 'draft';
		}

		$meta_fields = $this->sanitize_meta_fields( $args['meta_fields'] ?? [] );

		if ( 1 === count( $update_data ) && empty( $meta_fields ) ) {
			return [ 'error' => 'No fields to update were provided.' ];
		}

		if ( count( $update_data ) > 1 ) {
			$result = \wp_update_post( $update_data, true );

			if ( \is_wp_error( $result ) ) {
				return [ 'error' => $result->get_error_message() ];
			}
		}

		foreach ( $meta_fields as $key => $value ) {
			\update_post_meta( $post_id, $key, $value );
		}

		return [
			'post_id' => $post_id,
			'updated' => true,
		];
	}

	/**
	 * Sanitise an arbitrary meta_fields map from AI input.
	 *
	 * Only string keys and string values are accepted; empty keys are discarded.
	 * Leading underscores are preserved so WooCommerce private meta (e.g. _price)
	 * passes through correctly.
	 *
	 * @since 1.9.0
	 * @param mixed $raw Raw meta_fields value from AI arguments.
	 * @return array<string, string> Sanitised key/value pairs.
	 */
	private function sanitize_meta_fields( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$clean = [];
		foreach ( $raw as $key => $value ) {
			// sanitize_key strips leading underscores — preserve them for WooCommerce private meta.
			$prefix = str_starts_with( (string) $key, '_' ) ? '_' : '';
			$skey   = $prefix . \sanitize_key( ltrim( (string) $key, '_' ) );
			if ( '' !== $skey && '_' !== $skey ) {
				$clean[ $skey ] = \sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}
}

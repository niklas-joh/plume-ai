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
	 * @since NEXT_VERSION Capabilities are resolved from the target post type
	 *                     (create_posts/publish_posts) instead of a flat edit_posts
	 *                     check, and protected meta keys are rejected by default.
	 * @param array $args    Keyed: title (string), content (string), status (string), post_type (string), meta_fields (array).
	 * @param int   $user_id WordPress user ID performing the action.
	 * @return array Post data on success; ['error' => string] on failure.
	 */
	public function create( array $args, int $user_id ): array {
		if ( ! (bool) \get_option( 'plume_enable_write_tools', true ) ) {
			return [ 'error' => 'Write tools are disabled.' ];
		}

		$post_type = \sanitize_key( $args['post_type'] ?? 'post' );
		if ( ! \in_array( $post_type, $this->registry->allowed_post_types(), true ) ) {
			return [ 'error' => 'Post type not permitted.' ];
		}

		$caps = PostTypeCaps::resolve( $post_type );
		if ( null === $caps ) {
			return [ 'error' => 'Post type not permitted.' ];
		}

		// 'edit_posts' is the capability of the 'post' post type alone — a user who may
		// write posts is not thereby allowed to create pages or any custom post type.
		if ( ! \user_can( $user_id, $caps['create'] ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$title = \sanitize_text_field( $args['title'] ?? '' );
		if ( '' === $title ) {
			return [ 'error' => 'A post title is required.' ];
		}

		$content = \wp_kses_post( $this->normaliser->normalise( (string) ( $args['content'] ?? '' ) ) );
		$status  = \in_array( $args['status'] ?? 'draft', [ 'draft', 'publish', 'pending' ], true )
			? ( $args['status'] ?? 'draft' )
			: 'draft';

		// Checked against the resolved status, not the raw argument, so an unknown
		// status that falls back to 'draft' is never treated as a publish attempt.
		if ( 'publish' === $status && ! \user_can( $user_id, $caps['publish'] ) ) {
			return [ 'error' => 'You are not allowed to publish this content.' ];
		}

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

		$meta_fields = $this->filter_protected_meta(
			$this->sanitize_meta_fields( $args['meta_fields'] ?? [] ),
			$post_type
		);
		foreach ( $meta_fields as $key => $value ) {
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
	 * @since NEXT_VERSION Status transitions to publish/private require the post type's
	 *                     publish_posts capability, trashing requires delete_post, and
	 *                     protected meta keys are rejected by default.
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

		$post = \get_post( $post_id );
		if ( null === $post ) {
			return [ 'error' => 'Post not found.' ];
		}

		if ( ! \user_can( $user_id, 'edit_post', $post_id ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$caps = PostTypeCaps::resolve( $post->post_type );
		if ( null === $caps ) {
			return [ 'error' => 'Post type not permitted.' ];
		}

		$update_data = [ 'ID' => $post_id ];

		if ( isset( $args['title'] ) ) {
			$update_data['post_title'] = \sanitize_text_field( $args['title'] );
		}

		if ( isset( $args['content'] ) ) {
			$update_data['post_content'] = \wp_kses_post( $this->normaliser->normalise( (string) $args['content'] ) );
		}

		if ( isset( $args['status'] ) ) {
			$status = \in_array( $args['status'], [ 'draft', 'publish', 'pending', 'private', 'trash' ], true )
				? $args['status']
				: 'draft';

			// 'edit_post' authorises editing the post, not making it publicly visible.
			if ( \in_array( $status, [ 'publish', 'private' ], true )
				&& ! \user_can( $user_id, $caps['publish'] ) ) {
				return [ 'error' => 'You are not allowed to publish this content.' ];
			}

			if ( 'trash' === $status && ! \user_can( $user_id, 'delete_post', $post_id ) ) {
				return [ 'error' => 'You are not allowed to trash this content.' ];
			}

			$update_data['post_status'] = $status;
		}

		$meta_fields = $this->filter_protected_meta(
			$this->sanitize_meta_fields( $args['meta_fields'] ?? [] ),
			$post->post_type
		);

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

	/**
	 * Drop protected meta keys the AI is not explicitly permitted to write.
	 *
	 * `sanitize_meta_fields()` preserves leading underscores so that private meta
	 * such as WooCommerce's `_price` survives sanitisation, which would otherwise
	 * let AI-supplied arguments overwrite protected keys (`_wp_page_template`, another
	 * plugin's private state) on the strength of `edit_post` alone. Protected keys are
	 * therefore opt-in: add them to the `plume_allowed_protected_meta` filter.
	 *
	 * @since NEXT_VERSION
	 * @param array<string, string> $meta      Sanitised meta key/value pairs.
	 * @param string                $post_type Post type the meta will be written to.
	 * @return array<string, string> Meta pairs the current write is allowed to persist.
	 */
	private function filter_protected_meta( array $meta, string $post_type ): array {
		/**
		 * Filters the protected meta keys AI-proposed plans may write.
		 *
		 * Protected keys (those WordPress reports via is_protected_meta(), conventionally
		 * prefixed with an underscore) are rejected by default. Return the keys your site
		 * wants Plume to be able to set, for example WooCommerce's `_price`.
		 *
		 * @since NEXT_VERSION
		 * @param string[] $allowed   Protected meta keys Plume may write. Default empty.
		 * @param string   $post_type Post type the meta will be written to.
		 */
		$allowed = (array) \apply_filters( 'plume_allowed_protected_meta', [], $post_type );

		$permitted = [];
		foreach ( $meta as $key => $value ) {
			// is_protected_meta() expects a meta object type ('post', 'term', ...), not a
			// post-type slug; every writable type here is a post object, so pass 'post'.
			if ( \is_protected_meta( $key, 'post' ) && ! \in_array( $key, $allowed, true ) ) {
				continue;
			}
			$permitted[ $key ] = $value;
		}

		return $permitted;
	}
}

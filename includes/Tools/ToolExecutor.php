<?php
/**
 * Executes AI tool calls on behalf of authenticated WordPress users.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes tool calls on behalf of an authenticated user.
 *
 * Every public method returns a plain array. WP_Error instances are
 * converted internally to ['error' => $message] so callers never need to
 * handle two different return types.
 */
class ToolExecutor {

	/**
	 * Inject the tool registry needed to validate allowed post types.
	 *
	 * @since 1.0.0
	 * @param ToolRegistry $registry The tool registry instance.
	 */
	public function __construct( private ToolRegistry $registry ) {}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a tool call by name.
	 *
	 * @param string $tool_name  Registered tool name.
	 * @param array  $args       Arguments from the AI provider.
	 * @param int    $user_id    WordPress user ID performing the call.
	 * @return array
	 */
	public function execute( string $tool_name, array $args, int $user_id ): array {
		$dispatch = [
			'get_recent_posts'    => [ $this, 'get_recent_posts' ],
			'get_post_content'    => [ $this, 'get_post_content' ],
			'search_posts'        => [ $this, 'search_posts' ],
			'get_pages'           => [ $this, 'get_pages' ],
			'get_site_info'       => [ $this, 'get_site_info' ],
			'generate_seo_meta'   => [ $this, 'generate_seo_meta' ],
			'plan_post'           => [ $this, 'plan_post' ],
			'plan_update'         => [ $this, 'plan_update' ],
			'submit_post_content' => [ $this, 'submit_post_content' ],
		];

		if ( ! isset( $dispatch[ $tool_name ] ) ) {
			return [ 'error' => 'Unknown tool: ' . $tool_name ];
		}

		return ( $dispatch[ $tool_name ] )( $args, $user_id );
	}

	// -------------------------------------------------------------------------
	// Tool implementations
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a list of recent posts.
	 *
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function get_recent_posts( array $args, int $user_id ): array {
		if ( ! \user_can( $user_id, 'edit_posts' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$post_type = \sanitize_key( $args['post_type'] ?? 'post' );
		if ( ! \in_array( $post_type, $this->registry->allowed_post_types(), true ) ) {
			return [ 'error' => 'Post type not permitted.' ];
		}

		$count  = min( max( 1, (int) ( $args['count'] ?? 5 ) ), 20 );
		$status = \sanitize_key( $args['status'] ?? 'publish' );

		$query = new \WP_Query(
			[
				'post_type'      => $post_type,
				'posts_per_page' => $count,
				'post_status'    => $status,
				'fields'         => 'all',
				'no_found_rows'  => true,
			]
		);

		$posts = [];
		foreach ( $query->posts as $post ) {
			$posts[] = [
				'id'      => $post->ID,
				'title'   => \html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'status'  => $post->post_status,
				'date'    => $post->post_date,
				'excerpt' => \wp_trim_words( \wp_strip_all_tags( ! empty( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content ), 100 ),
			];
		}
		\wp_reset_postdata();

		return [ 'posts' => $posts ];
	}

	/**
	 * Retrieve the full content of a single post or page.
	 *
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function get_post_content( array $args, int $user_id ): array {
		$post_id = \absint( $args['post_id'] ?? 0 );
		if ( 0 === $post_id ) {
			return [ 'error' => 'A valid post_id is required.' ];
		}

		$post = \get_post( $post_id );
		if ( null === $post ) {
			return [ 'error' => 'Post not found.' ];
		}

		if ( 'publish' !== $post->post_status ) {
			$is_author    = ( (int) $post->post_author === $user_id );
			$can_edit_all = \user_can( $user_id, 'edit_others_posts' );
			if ( ! $is_author && ! $can_edit_all ) {
				return [ 'error' => 'Not authorised to read this post.' ];
			}
		}

		$content = \wp_strip_all_tags( \apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return [
			'id'      => $post->ID,
			'title'   => \html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			'content' => $content,
			'status'  => $post->post_status,
			'date'    => $post->post_date,
		];
	}

	/**
	 * Search for posts matching a keyword query.
	 *
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function search_posts( array $args, int $user_id ): array {
		if ( ! \user_can( $user_id, 'edit_posts' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$post_type = \sanitize_key( $args['post_type'] ?? 'post' );
		if ( ! \in_array( $post_type, $this->registry->allowed_post_types(), true ) ) {
			return [ 'error' => 'Post type not permitted.' ];
		}

		$search_query = \sanitize_text_field( $args['query'] ?? '' );
		if ( '' === $search_query ) {
			return [ 'error' => 'A search query is required.' ];
		}

		$count = min( max( 1, (int) ( $args['count'] ?? 5 ) ), 20 );

		$query = new \WP_Query(
			[
				's'              => $search_query,
				'post_type'      => $post_type,
				'posts_per_page' => $count,
				'no_found_rows'  => true,
			]
		);

		$posts = [];
		foreach ( $query->posts as $post ) {
			$posts[] = [
				'id'      => $post->ID,
				'title'   => \html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'excerpt' => \wp_trim_words( \wp_strip_all_tags( ! empty( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content ), 50 ),
				'status'  => $post->post_status,
			];
		}
		\wp_reset_postdata();

		return [ 'posts' => $posts ];
	}

	/**
	 * Retrieve a list of published pages.
	 *
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function get_pages( array $args, int $user_id ): array {
		if ( ! \user_can( $user_id, 'edit_posts' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$count = min( max( 1, (int) ( $args['count'] ?? 10 ) ), 20 );

		$query = new \WP_Query(
			[
				'post_type'      => 'page',
				'posts_per_page' => $count,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			]
		);

		$pages = [];
		foreach ( $query->posts as $post ) {
			$pages[] = [
				'id'     => $post->ID,
				'title'  => \html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'slug'   => $post->post_name,
				'status' => $post->post_status,
			];
		}
		\wp_reset_postdata();

		return [ 'pages' => $pages ];
	}

	/**
	 * Retrieve general site information.
	 *
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function get_site_info( array $args, int $user_id ): array {
		if ( ! \user_can( $user_id, 'read' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		return [
			'name'         => \get_bloginfo( 'name' ),
			'description'  => \get_bloginfo( 'description' ),
			'url'          => \get_bloginfo( 'url' ),
			'wp_version'   => $GLOBALS['wp_version'],
			'active_theme' => \wp_get_theme()->get( 'Name' ),
		];
	}

	/**
	 * Store a pending post-creation plan for user approval.
	 *
	 * The plan is saved as a WordPress transient keyed by user ID + plan ID so that
	 * only the owning user can execute it. Transient expires after one hour.
	 *
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function plan_post( array $args, int $user_id ): array {
		if ( ! \user_can( $user_id, 'edit_posts' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$title = \sanitize_text_field( $args['title'] ?? '' );
		if ( '' === $title ) {
			return [ 'error' => 'A post title is required.' ];
		}

		$post_type = \sanitize_key( $args['post_type'] ?? 'post' );
		if ( ! \in_array( $post_type, $this->registry->allowed_post_types(), true ) ) {
			return [ 'error' => 'Post type not permitted.' ];
		}

		$content = \wp_kses_post( $args['content'] ?? '' );
		if ( '' === $content ) {
			return [ 'error' => 'The full post content (content) is required.' ];
		}

		$plan_data = [
			'plan_type'   => 'create',
			'title'       => $title,
			'outline'     => \sanitize_textarea_field( $args['outline'] ?? '' ),
			'content'     => $content,
			'post_type'   => $post_type,
			'post_status' => \in_array( $args['status'] ?? 'draft', [ 'draft', 'publish', 'pending' ], true )
				? $args['status'] ?? 'draft'
				: 'draft',
		];

		if ( ! empty( $args['meta_fields'] ) && is_array( $args['meta_fields'] ) ) {
			$plan_data['meta_fields'] = $args['meta_fields'];
		}

		return $this->store_plan( $plan_data, $user_id );
	}

	/**
	 * Stage a pending post-update plan awaiting its full content.
	 *
	 * Deliberately does not accept the rewritten post body: a single completion
	 * that has to emit a short analysis/summary alongside a potentially large post
	 * body shares one fixed output-token budget between them, so any post long
	 * enough to matter risks the body being cut off mid-generation while the
	 * short fields survive untouched. Splitting the body into its own dedicated
	 * completion (via submit_post_content, forced as the only available tool on
	 * the next agentic-loop iteration) gives it the full budget to itself instead.
	 *
	 * @since 1.12.0 Removed new_content; stages a draft instead of the final plan.
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function plan_update( array $args, int $user_id ): array {
		if ( ! \user_can( $user_id, 'edit_posts' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$post_id = \absint( $args['post_id'] ?? 0 );
		if ( 0 === $post_id ) {
			return [ 'error' => 'A valid post_id is required.' ];
		}

		if ( ! \user_can( $user_id, 'edit_post', $post_id ) ) {
			return [ 'error' => 'Insufficient permissions to edit this post.' ];
		}

		$changes = \sanitize_textarea_field( $args['changes'] ?? '' );
		if ( '' === $changes ) {
			return [ 'error' => 'A description of changes is required.' ];
		}

		$plan_data = [
			'plan_type'   => 'update',
			'post_id'     => $post_id,
			'changes'     => $changes,
			'post_status' => \in_array( $args['status'] ?? '', [ 'draft', 'publish', 'pending' ], true )
				? $args['status']
				: '',
		];

		if ( ! empty( $args['new_title'] ) ) {
			$plan_data['new_title'] = \sanitize_text_field( $args['new_title'] );
		}

		if ( ! empty( $args['meta_fields'] ) && is_array( $args['meta_fields'] ) ) {
			$plan_data['meta_fields'] = $args['meta_fields'];
		}

		\set_transient( self::draft_transient_key( $user_id ), $plan_data, 5 * MINUTE_IN_SECONDS );

		return [
			'status'  => 'awaiting_content',
			'post_id' => $post_id,
		];
	}

	/**
	 * Complete a staged plan_update by attaching the full rewritten post body.
	 *
	 * @since 1.12.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function submit_post_content( array $args, int $user_id ): array {
		if ( ! \user_can( $user_id, 'edit_posts' ) ) {
			return [ 'error' => 'Insufficient permissions.' ];
		}

		$post_id = \absint( $args['post_id'] ?? 0 );
		if ( 0 === $post_id ) {
			return [ 'error' => 'A valid post_id is required.' ];
		}

		$draft_key = self::draft_transient_key( $user_id );
		$plan_data = \get_transient( $draft_key );
		if ( false === $plan_data || ( $plan_data['post_id'] ?? 0 ) !== $post_id ) {
			return [ 'error' => 'No pending update found for this post. Call plan_update first.' ];
		}

		$new_content = \wp_kses_post( $args['content'] ?? '' );
		if ( '' === $new_content ) {
			return [ 'error' => 'The updated post content is required.' ];
		}

		\delete_transient( $draft_key );

		$plan_data['new_content'] = $new_content;

		return $this->store_plan( $plan_data, $user_id );
	}

	/**
	 * Returns the WordPress transient key for a user's in-flight plan_update draft.
	 *
	 * Single-slot per user — only one agentic loop runs per user request, so a
	 * new plan_update call safely overwrites any prior, unclaimed draft. The
	 * short TTL only needs to outlive the current request's remaining loop
	 * iterations, not a full user-approval window like the final plan transient.
	 *
	 * @since 1.12.0
	 * @param int $user_id WordPress user ID who owns the draft.
	 * @return string
	 */
	private static function draft_transient_key( int $user_id ): string {
		return "plume_plan_draft_{$user_id}";
	}

	/**
	 * Returns the WordPress transient key for a user-scoped plan.
	 *
	 * Centralised here so ToolExecutor and PlansRestController always use the same format.
	 *
	 * @since 1.9.0
	 * @param int    $user_id WordPress user ID who owns the plan.
	 * @param string $plan_id Plan identifier (8-character UUID fragment).
	 * @return string
	 */
	public static function plan_transient_key( int $user_id, string $plan_id ): string {
		return "plume_plan_{$user_id}_{$plan_id}";
	}

	/**
	 * Persist a plan as a user-scoped transient and return the populated data array.
	 *
	 * @since 1.0.0
	 * @param array $data    Plan fields (must not include 'id' or 'status').
	 * @param int   $user_id WordPress user ID who owns the plan.
	 * @return array Plan data including generated 'id' and 'status' => 'pending_approval'.
	 */
	private function store_plan( array $data, int $user_id ): array {
		$id             = \substr( \wp_generate_uuid4(), 0, 8 );
		$data['id']     = $id;
		$data['status'] = 'pending_approval';
		\set_transient( self::plan_transient_key( $user_id, $id ), $data, HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Generate and apply SEO metadata for a post.
	 *
	 * Available to every tier — calls the AI provider and applies metadata
	 * automatically regardless of tier. Credit enforcement happens entirely on
	 * the Worker side (it rejects exhausted requests), not via a local gate here.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $args    Tool arguments from the AI provider.
	 * @param int                 $user_id WordPress user ID performing the call.
	 * @return array<string,mixed>
	 */
	private function generate_seo_meta( array $args, int $user_id ): array {
		$post_id = \absint( $args['post_id'] ?? 0 );
		if ( 0 === $post_id ) {
			return [ 'error' => __( 'A valid post_id is required.', 'plume' ) ];
		}

		$post = \get_post( $post_id );
		if ( null === $post ) {
			return [ 'error' => __( 'Post not found.', 'plume' ) ];
		}

		if ( ! \user_can( $user_id, 'edit_post', $post_id ) ) {
			return [ 'error' => __( 'Insufficient permissions.', 'plume' ) ];
		}

		$seo_data = \Plume\Modules\Seo\SeoModule::generate_for_post( $post_id, $user_id );
		if ( \is_wp_error( $seo_data ) ) {
			return [ 'error' => $seo_data->get_error_message() ];
		}

		\Plume\Tiers\UsageTracker::log_usage( $seo_data['tokens_used'], $user_id );
		$applied = \Plume\Modules\Seo\SeoModule::apply_for_post( $post_id, $seo_data );

		return [
			'post_id'        => $post_id,
			'meta_title'     => $seo_data['meta_title'],
			'og_description' => $seo_data['og_description'],
			'excerpt'        => $seo_data['excerpt'],
			'alt_text'       => $seo_data['alt_text'],
			'applied'        => $applied['updated'],
			'tokens_used'    => $seo_data['tokens_used'],
		];
	}
}

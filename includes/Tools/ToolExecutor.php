<?php
/**
 * Executes AI tool calls on behalf of authenticated WordPress users.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tools;

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
			'get_recent_posts'  => [ $this, 'get_recent_posts' ],
			'get_post_content'  => [ $this, 'get_post_content' ],
			'search_posts'      => [ $this, 'search_posts' ],
			'create_post'       => [ $this, 'create_post' ],
			'update_post'       => [ $this, 'update_post' ],
			'get_pages'         => [ $this, 'get_pages' ],
			'get_site_info'     => [ $this, 'get_site_info' ],
			'generate_seo_meta' => [ $this, 'generate_seo_meta' ],
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

		$content = \wp_trim_words(
			\wp_strip_all_tags( \apply_filters( 'the_content', $post->post_content ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			500
		);

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
	 * Create a new post or page.
	 *
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function create_post( array $args, int $user_id ): array {
		if ( ! (bool) \get_option( 'wp_ai_mind_enable_write_tools', false ) ) {
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

		$content = \wp_kses_post( $args['content'] ?? '' );
		$status  = \in_array( $args['status'] ?? 'draft', [ 'draft', 'publish', 'pending' ], true )
			? $args['status']
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
	 * @since 1.0.0
	 * @param array $args    Tool arguments from the AI provider.
	 * @param int   $user_id WordPress user ID performing the call.
	 * @return array
	 */
	private function update_post( array $args, int $user_id ): array {
		if ( ! (bool) \get_option( 'wp_ai_mind_enable_write_tools', false ) ) {
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
			$update_data['post_content'] = \wp_kses_post( $args['content'] );
		}

		if ( isset( $args['status'] ) ) {
			$update_data['post_status'] = \in_array( $args['status'], [ 'draft', 'publish', 'pending', 'private', 'trash' ], true )
				? $args['status']
				: 'draft';
		}

		$result = \wp_update_post( $update_data, true );

		if ( \is_wp_error( $result ) ) {
			return [ 'error' => $result->get_error_message() ];
		}

		return [
			'post_id' => $post_id,
			'updated' => true,
		];
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
	 * Generate and apply SEO metadata for a post.
	 *
	 * Pro users: calls the AI provider and applies metadata automatically.
	 * Free-tier users: returns post data for manual suggestion by the AI.
	 *
	 * @since 1.0.0
	 * @param array<string,mixed> $args    Tool arguments from the AI provider.
	 * @param int                 $user_id WordPress user ID performing the call.
	 * @return array<string,mixed>
	 */
	private function generate_seo_meta( array $args, int $user_id ): array {
		$post_id = \absint( $args['post_id'] ?? 0 );
		if ( 0 === $post_id ) {
			return [ 'error' => __( 'A valid post_id is required.', 'wp-ai-mind' ) ];
		}

		$post = \get_post( $post_id );
		if ( null === $post ) {
			return [ 'error' => __( 'Post not found.', 'wp-ai-mind' ) ];
		}

		if ( ! \user_can( $user_id, 'edit_post', $post_id ) ) {
			return [ 'error' => __( 'Insufficient permissions.', 'wp-ai-mind' ) ];
		}

		if ( ! \WP_AI_Mind\Tiers\NJ_Tier_Manager::user_can( 'seo', $user_id ) ) {
			return [
				'seo_access'           => false,
				'post_title'           => \html_entity_decode( $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'post_content_snippet' => mb_substr( \wp_strip_all_tags( $post->post_content ), 0, 500 ),
				'note'                 => __( 'SEO auto-generation requires the Pro plan. Use the post data above to suggest appropriate SEO fields manually, and let the user know they can upgrade to Pro for one-click automated SEO optimisation.', 'wp-ai-mind' ),
			];
		}

		if ( ! \WP_AI_Mind\Tiers\NJ_Usage_Tracker::check_limit( $user_id ) ) {
			return [ 'error' => __( 'Monthly usage limit reached. Please upgrade your plan to continue.', 'wp-ai-mind' ) ];
		}

		$seo_data = \WP_AI_Mind\Modules\Seo\SeoModule::generate_for_post( $post_id, $user_id );
		if ( \is_wp_error( $seo_data ) ) {
			return [ 'error' => $seo_data->get_error_message() ];
		}

		\WP_AI_Mind\Tiers\NJ_Usage_Tracker::log_usage( $seo_data['tokens_used'], $user_id );
		$applied = \WP_AI_Mind\Modules\Seo\SeoModule::apply_for_post( $post_id, $seo_data );

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

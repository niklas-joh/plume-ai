<?php
/**
 * Data-access layer for conversations and their messages.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data-access layer for conversations and their messages.
 *
 * Authentication is not enforced here — callers (REST controllers) are
 * responsible for ownership and permission checks before invoking these methods.
 */
class ConversationStore {

	/**
	 * Create a new conversation owned by the current logged-in user.
	 *
	 * @since 1.0.0
	 * @param string   $title   Optional conversation title; sanitised before storage.
	 * @param int|null $post_id Optional WordPress post ID to associate the conversation with.
	 * @return int Inserted conversation row ID, or 0 on failure.
	 */
	public function create( string $title = '', ?int $post_id = null ): int {
		global $wpdb;
		// $wpdb->insert handles its own preparation via format specifiers; no direct SQL involved.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( 'conversations' ),
			[
				'user_id' => get_current_user_id(),
				'title'   => sanitize_text_field( $title ),
				'post_id' => $post_id,
			],
			[ '%d', '%s', '%d' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Append a message to an existing conversation.
	 *
	 * $role must be 'user' or 'assistant'. $model is empty for user turns.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id The conversation record ID.
	 * @param string $role            Message author: 'user' or 'assistant'.
	 * @param string $content         Message text; HTML-sanitised via wp_kses_post before storage.
	 * @param string $model           Model slug (e.g. 'claude-opus-4-6'); empty for user turns.
	 * @param int    $tokens          Token count for usage tracking; 0 if unknown.
	 * @return int Inserted message row ID, or 0 on failure.
	 */
	public function add_message( int $conversation_id, string $role, string $content, string $model = '', int $tokens = 0 ): int {
		global $wpdb;
		// $wpdb->insert handles its own preparation via format specifiers; no direct SQL involved.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( 'messages' ),
			[
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'content'         => wp_kses_post( $content ),
				'model'           => sanitize_text_field( $model ),
				'tokens'          => $tokens,
			],
			[ '%d', '%s', '%s', '%s', '%d' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Retrieve all messages for a conversation in ascending ID order.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id The conversation record ID.
	 * @return array<int, array<string, mixed>> Rows from the messages table; empty array if none.
	 */
	public function get_messages( int $conversation_id ): array {
		global $wpdb;
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE conversation_id = %d ORDER BY id ASC', Schema::table( 'messages' ), $conversation_id ),
			ARRAY_A
		);
		return ! empty( $results ) ? $results : [];
	}

	/**
	 * List conversations belonging to a user, newest first.
	 *
	 * @since 1.0.0
	 * @param int $user_id WordPress user ID.
	 * @param int $limit   Maximum rows to return; defaults to 50.
	 * @return array<int, array<string, mixed>> Conversation rows; empty array if none.
	 */
	public function list_for_user( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d',
				Schema::table( 'conversations' ),
				$user_id,
				$limit
			),
			ARRAY_A
		);
		return ! empty( $results ) ? $results : [];
	}

	/**
	 * Fetch a single conversation row by ID.
	 *
	 * @since 1.0.0
	 * @param int $conversation_id The conversation record ID.
	 * @return array<string, mixed>|null Conversation row, or null if not found.
	 */
	public function get_conversation( int $conversation_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'conversations' ), $conversation_id ),
			ARRAY_A
		);
		return ! empty( $row ) ? $row : null;
	}

	/**
	 * Delete a conversation and all its messages.
	 *
	 * Messages are deleted before the conversation row to respect the logical
	 * foreign-key relationship (no DB constraint enforces this ordering).
	 *
	 * @since 1.0.0
	 * @param int $conversation_id The conversation record ID.
	 * @return void
	 */
	public function delete( int $conversation_id ): void {
		global $wpdb;
		// $wpdb->delete handles its own preparation; no direct SQL string involved.
		$wpdb->delete( Schema::table( 'messages' ), [ 'conversation_id' => $conversation_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Schema::table( 'conversations' ), [ 'id' => $conversation_id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Update the title of an existing conversation.
	 *
	 * @since 1.0.0
	 * @param int    $conversation_id The conversation record ID.
	 * @param string $title           New title text; sanitised before storage.
	 * @return bool True on success, false if the DB update failed.
	 */
	public function update_title( int $conversation_id, string $title ): bool {
		global $wpdb;
		// $wpdb->update handles its own preparation via format specifiers.
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Schema::table( 'conversations' ),
			[ 'title' => sanitize_text_field( $title ) ],
			[ 'id' => $conversation_id ],
			[ '%s' ],
			[ '%d' ]
		);
		return false !== $result;
	}
}

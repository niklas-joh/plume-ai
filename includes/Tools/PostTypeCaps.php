<?php
/**
 * Resolves the create/publish capabilities for a post type in one place.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small resolver for a post type's create/publish capability strings.
 *
 * Several write paths (PostWriter, ToolExecutor, PlansRestController, ChatPage)
 * repeated the same `get_post_type_object()` + null guard + `->cap->*` lookup.
 * Centralising it here keeps the null-handling and capability names in one spot.
 *
 * @since 1.13.2
 */
final class PostTypeCaps {

	/**
	 * Resolve the create/publish capability strings for a post type.
	 *
	 * @since 1.13.2
	 * @param string $post_type Post type slug.
	 * @return array{create: string, publish: string}|null Capability strings, or null for an unregistered post type.
	 */
	public static function resolve( string $post_type ): ?array {
		$object = \get_post_type_object( $post_type );
		if ( null === $object ) {
			return null;
		}

		return [
			'create'  => $object->cap->create_posts,
			'publish' => $object->cap->publish_posts,
		];
	}
}

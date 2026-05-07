<?php
/**
 * Immutable value object describing a single AI tool definition.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object describing a single AI tool.
 */
final class ToolDefinition {

	/**
	 * Create a tool definition.
	 *
	 * @since 1.0.0
	 * @param string $name                Tool name used by the AI provider (snake_case).
	 * @param string $description         Human-readable description sent to the provider.
	 * @param array  $parameters          JSON Schema object describing the tool's parameters.
	 * @param string $capability          WordPress capability required to call this tool.
	 * @param bool   $requires_write_tools Whether the tool requires the write-tools setting to be enabled.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly array $parameters,
		public readonly string $capability = 'edit_posts',
		public readonly bool $requires_write_tools = false,
	) {}
}

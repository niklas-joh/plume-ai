<?php
/**
 * Immutable value object representing an AI completion request.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures all parameters required to make an AI completion request.
 *
 * @since 1.0.0
 */
final class CompletionRequest {
	/**
	 * Constructor.
	 *
	 * @param array  $messages        Message history: [['role'=>'user','content'=>'...']].
	 * @param string $system          System prompt.
	 * @param string $model           Model ID (empty = use provider default).
	 * @param float  $temperature     Sampling temperature.
	 * @param int    $max_tokens      Maximum tokens to generate.
	 * @param array  $metadata        Arbitrary context (post_id, feature, etc.).
	 * @param array  $tools           Tool definitions in provider wire format (empty = no tools).
	 * @param bool   $force_tool_use  When true, instructs the provider to always call a tool rather
	 *                                than returning a bare text response. Requires tools to be non-empty.
	 * @param int    $timeout         Per-request HTTP timeout in seconds; 0 = use the provider default
	 *                                (PLUME_HTTP_TIMEOUT, or 120 s for Ollama). Added NEXT_VERSION.
	 */
	public function __construct(
		public readonly array $messages,
		public readonly string $system = '',
		public readonly string $model = '',
		public readonly float $temperature = 0.7,
		public readonly int $max_tokens = 2048,
		public readonly array $metadata = [],
		public readonly array $tools = [],
		public readonly bool $force_tool_use = false,
		public readonly int $timeout = 0,
	) {}
}

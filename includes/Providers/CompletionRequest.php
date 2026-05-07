<?php
/**
 * Immutable value object representing an AI completion request.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Providers;

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
	 * @param array  $messages      Message history: [['role'=>'user','content'=>'...']].
	 * @param string $system        System prompt.
	 * @param string $model         Model ID (empty = use provider default).
	 * @param float  $temperature   Sampling temperature.
	 * @param int    $max_tokens    Maximum tokens to generate.
	 * @param array  $metadata      Arbitrary context (post_id, feature, etc.).
	 * @param array  $tools         Tool definitions in provider wire format (empty = no tools).
	 */
	public function __construct(
		public readonly array $messages,
		public readonly string $system = '',
		public readonly string $model = '',
		public readonly float $temperature = 0.7,
		public readonly int $max_tokens = 2048,
		public readonly array $metadata = [],
		public readonly array $tools = [],
	) {}
}

<?php
/**
 * Immutable value object representing an AI completion response.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures content, token counts, cost, and optional tool-call data from a provider response.
 *
 * @since 1.0.0
 */
final class CompletionResponse {
	/**
	 * Sum of prompt and completion token counts.
	 *
	 * @var int
	 */
	public readonly int $total_tokens;

	/**
	 * Constructor.
	 *
	 * @param string     $content             The completion content.
	 * @param string     $model               The model used.
	 * @param int        $prompt_tokens       Tokens used in the prompt.
	 * @param int        $completion_tokens   Tokens used in the completion.
	 * @param float      $cost_usd            USD cost of the request.
	 * @param mixed      $raw                 Raw API response (array or structured value).
	 * @param array|null $tool_call           Tool call data if the response is a tool invocation.
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $model,
		public readonly int $prompt_tokens,
		public readonly int $completion_tokens,
		public readonly float $cost_usd = 0.0,
		public readonly mixed $raw = [],
		public readonly ?array $tool_call = null,
	) {
		$this->total_tokens = $prompt_tokens + $completion_tokens;
	}

	/**
	 * Returns true if this response represents a tool call rather than a text completion.
	 *
	 * @return bool
	 */
	public function is_tool_call(): bool {
		return null !== $this->tool_call;
	}
}

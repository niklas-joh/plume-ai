<?php
/**
 * Immutable value object representing an AI completion response.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Providers;

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
	 * @since 1.0.0
	 * @param string     $content             The completion content.
	 * @param string     $model               The model used.
	 * @param int        $prompt_tokens       Tokens used in the prompt.
	 * @param int        $completion_tokens   Tokens used in the completion.
	 * @param float      $cost_usd            USD cost of the request.
	 * @param mixed      $raw                 Raw API response (array or structured value).
	 * @param array|null $tool_call           Tool call data if the response is a tool invocation.
	 * @param int        $cache_read_tokens   Tokens served from the prompt cache (Anthropic cache_read_input_tokens).
	 * @param int        $cache_write_tokens  Tokens written to the prompt cache (Anthropic cache_creation_input_tokens).
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $model,
		public readonly int $prompt_tokens,
		public readonly int $completion_tokens,
		public readonly float $cost_usd = 0.0,
		public readonly mixed $raw = [],
		public readonly ?array $tool_call = null,
		public readonly int $cache_read_tokens = 0,
		public readonly int $cache_write_tokens = 0,
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

	/**
	 * Return a new instance with the content replaced.
	 *
	 * Used by the agentic loop to extract the message from a chat_response tool call
	 * and treat it as the final text completion without mutating readonly properties.
	 *
	 * @since 1.0.0
	 * @param string $text Replacement content.
	 * @return static
	 */
	public function with_text( string $text ): self {
		return new self(
			content:            $text,
			model:              $this->model,
			prompt_tokens:      $this->prompt_tokens,
			completion_tokens:  $this->completion_tokens,
			cost_usd:           $this->cost_usd,
			raw:                $this->raw,
			tool_call:          null,
			cache_read_tokens:  $this->cache_read_tokens,
			cache_write_tokens: $this->cache_write_tokens,
		);
	}
}

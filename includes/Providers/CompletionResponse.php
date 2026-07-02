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
	 * @param int        $credits_charged     Credits charged by the Worker for this call; 0 on the BYOK path (no Worker).
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
		public readonly int $credits_charged = 0,
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
	 * Extract tool calls from a normalised proxy result.
	 *
	 * The Worker sends `tool_calls` (plural array); older in-flight responses used
	 * `tool_call` (singular). Centralising this contract here prevents the per-provider
	 * drift that let Claude diverge from OpenAI/Gemini (see #892/#893). Returns the first
	 * call (or null) so callers can keep is_tool_call() a simple null-check, plus the full
	 * array for multi-call execution in a single turn.
	 *
	 * @since NEXT_VERSION
	 * @param mixed $result Normalised proxy response; non-array input yields no calls.
	 * @return array{0: array|null, 1: array<int, array>} [first_tool_call_or_null, all_tool_calls].
	 */
	public static function first_and_all_tool_calls_from_proxy( mixed $result ): array {
		if ( ! \is_array( $result ) ) {
			return [ null, [] ];
		}
		if ( ! empty( $result['tool_calls'] ) && \is_array( $result['tool_calls'] ) ) {
			$all = array_values( $result['tool_calls'] );
			return [ $all[0] ?? null, $all ];
		}
		if ( ! empty( $result['tool_call'] ) ) {
			return [ $result['tool_call'], [ $result['tool_call'] ] ];
		}
		return [ null, [] ];
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
			credits_charged:    $this->credits_charged,
		);
	}
}

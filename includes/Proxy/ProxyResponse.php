<?php
/**
 * Typed value object for the Cloudflare Worker proxy normalised response.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Proxy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object wrapping the normalised response returned by the AI proxy worker.
 *
 * The proxy flattens all provider responses (Claude, OpenAI, Gemini) to a
 * single shape: a plain string `content` field and a unified usage object.
 * This DTO adapts that shape to the Claude API wire format expected by
 * ClaudeProvider::parse_response() so the two can interoperate without
 * cluttering the provider with format-detection logic.
 *
 * @since 1.2.0
 */
class ProxyResponse {

	/**
	 * Construct a typed proxy response from its three normalised fields.
	 *
	 * @since 1.2.0
	 * @param string $content       Plain text content returned by the proxy.
	 * @param int    $input_tokens  Input token count for usage tracking.
	 * @param int    $output_tokens Output token count for usage tracking.
	 */
	public function __construct(
		public readonly string $content,
		public readonly int $input_tokens,
		public readonly int $output_tokens,
	) {}

	/**
	 * Build a ProxyResponse from the raw array returned by NJ_Proxy_Client::chat().
	 *
	 * @since 1.2.0
	 * @param array<string, mixed> $data Decoded proxy response body.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			content: is_string( $data['content'] ?? null ) ? $data['content'] : '',
			input_tokens: (int) ( $data['usage']['input_tokens'] ?? 0 ),
			output_tokens: (int) ( $data['usage']['output_tokens'] ?? 0 ),
		);
	}

	/**
	 * Convert to the Claude API wire format expected by ClaudeProvider::parse_response().
	 *
	 * The method wraps the plain string in a block array because parse_response()
	 * iterates over content blocks looking for type=text.
	 *
	 * @since 1.2.0
	 * @return array<string, mixed>
	 */
	public function to_claude_format(): array {
		return [
			'content' => [
				[
					'type' => 'text',
					'text' => $this->content,
				],
			],
			'usage'   => [
				'input_tokens'  => $this->input_tokens,
				'output_tokens' => $this->output_tokens,
			],
		];
	}
}

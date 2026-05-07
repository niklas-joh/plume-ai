<?php
/**
 * AI provider implementation for the OpenAI API.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles completions, streaming, and image generation for OpenAI models.
 *
 * @since 1.0.0
 */
class OpenAIProvider extends AbstractProvider {

	private const API_BASE      = 'https://api.openai.com/v1';
	private const DEFAULT_MODEL = 'gpt-4o';

	private const MODELS = [
		'gpt-4o'      => 'GPT-4o',
		'gpt-4o-mini' => 'GPT-4o Mini',
		'o3'          => 'o3',
		'o4-mini'     => 'o4 Mini',
	];

	private const PRICING = [
		'gpt-4o'      => [
			'in'  => 2.5,
			'out' => 10.0,
		],
		'gpt-4o-mini' => [
			'in'  => 0.15,
			'out' => 0.60,
		],
		'o3'          => [
			'in'  => 10.0,
			'out' => 40.0,
		],
		'o4-mini'     => [
			'in'  => 1.1,
			'out' => 4.4,
		],
	];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $api_key OpenAI API key.
	 */
	public function __construct( private readonly string $api_key ) {}

	/**
	 * Return the provider slug used throughout the plugin.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_slug(): string {
		return 'openai';
	}

	/**
	 * Return the available model identifiers keyed by model ID.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public function get_models(): array {
		return self::MODELS;
	}

	/**
	 * Return the default model identifier.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_default_model(): string {
		return self::DEFAULT_MODEL;
	}

	/**
	 * Return true when an API key is configured.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Send a completion request to the OpenAI Chat Completions endpoint.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 * @throws ProviderException On HTTP failure or non-2xx status.
	 */
	protected function do_complete( CompletionRequest $request ): CompletionResponse {
		$messages = $request->messages;
		if ( '' !== $request->system ) {
			array_unshift(
				$messages,
				[
					'role'    => 'system',
					'content' => $request->system,
				]
			);
		}
		$model = ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL;
		$body  = [
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => $request->max_tokens,
			'temperature' => $request->temperature,
		];
		if ( ! empty( $request->tools ) ) {
			$body['tools']       = $request->tools; // Already in OpenAI wire format from ToolRegistry.
			$body['tool_choice'] = 'auto';
		}
		$raw = $this->post( '/chat/completions', $body );
		return $this->parse_response( $raw, $model );
	}

	/**
	 * Simulate streaming by word-chunking a non-streaming completion.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request  The completion request.
	 * @param callable          $on_chunk Callback invoked with each word token.
	 * @return CompletionResponse
	 * @throws ProviderException On HTTP failure or non-2xx status.
	 */
	protected function do_stream( CompletionRequest $request, callable $on_chunk ): CompletionResponse {
		$response = $this->do_complete( $request );
		$words    = explode( ' ', $response->content );
		foreach ( $words as $i => $word ) {
			$on_chunk( ( $i > 0 ? ' ' : '' ) . $word );
		}
		return $response;
	}

	/**
	 * Generate an image using the DALL-E 3 model.
	 *
	 * @since 1.0.0
	 * @param string $prompt  Image generation prompt.
	 * @param array  $options Optional overrides: 'size' and 'quality'.
	 * @return int WordPress attachment ID.
	 * @throws ProviderException When the API returns no image URL.
	 */
	public function generate_image( string $prompt, array $options = [] ): int {
		$body = [
			'model'   => 'dall-e-3',
			'prompt'  => $prompt,
			'n'       => 1,
			'size'    => $options['size'] ?? '1024x1024',
			'quality' => $options['quality'] ?? 'hd',
		];
		$raw  = $this->post( '/images/generations', $body );
		$url  = $raw['data'][0]['url'] ?? '';
		if ( empty( $url ) ) {
			throw new ProviderException( 'No image URL in response', 'openai' );
		}
		return $this->save_image_to_media_library( $url, 'dalle-' . time(), $prompt );
	}

	/**
	 * POST a JSON body to the OpenAI API and return the decoded response.
	 *
	 * @since 1.0.0
	 * @param string $path API endpoint path (e.g. '/chat/completions').
	 * @param array  $body Request payload.
	 * @return array
	 * @throws ProviderException On HTTP failure or non-2xx status.
	 */
	private function post( string $path, array $body ): array {
		$response = wp_remote_post(
			self::API_BASE . $path,
			[
				'timeout' => WP_AI_MIND_HTTP_TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new ProviderException( $response->get_error_message(), 'openai' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? "HTTP {$code}";
			throw new ProviderException( $msg, 'openai', $code, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $data;
	}

	/**
	 * Parse a raw OpenAI API response into a CompletionResponse value object.
	 *
	 * Detects tool_calls finish reason and populates tool_call on the response
	 * when the model has invoked a function.
	 *
	 * @since 1.0.0
	 * @param array  $data  Decoded API response body.
	 * @param string $model Model identifier used for pricing lookup.
	 * @return CompletionResponse
	 */
	private function parse_response( array $data, string $model ): CompletionResponse {
		$in_tokens  = (int) ( $data['usage']['prompt_tokens'] ?? 0 );
		$out_tokens = (int) ( $data['usage']['completion_tokens'] ?? 0 );
		$pricing    = self::PRICING[ $model ] ?? self::PRICING[ self::DEFAULT_MODEL ];
		$cost       = ( $in_tokens / 1_000_000 * $pricing['in'] ) + ( $out_tokens / 1_000_000 * $pricing['out'] );

		$message       = $data['choices'][0]['message'] ?? [];
		$finish_reason = $data['choices'][0]['finish_reason'] ?? '';

		// Detect tool call response.
		if ( 'tool_calls' === $finish_reason ) {
			$tool_call_data = $message['tool_calls'][0] ?? null;
			if ( $tool_call_data ) {
				$arguments = json_decode( $tool_call_data['function']['arguments'] ?? '{}', true ) ?? [];
				return new CompletionResponse(
					content: '',
					model: $data['model'] ?? $model,
					prompt_tokens: $in_tokens,
					completion_tokens: $out_tokens,
					cost_usd: $cost,
					raw: $data,
					tool_call: [
						'id'        => $tool_call_data['id'],
						'name'      => $tool_call_data['function']['name'],
						'arguments' => $arguments,
					],
				);
			}
		}

		$content = $message['content'] ?? '';
		return new CompletionResponse( $content, $model, $in_tokens, $out_tokens, $cost, $data );
	}
}

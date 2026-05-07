<?php
/**
 * AI provider implementation for locally-hosted Ollama models.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles completions via a local Ollama instance.
 *
 * Ollama does not support function/tool calling in the plugin's tool-use
 * protocol, so tool definitions are never forwarded to this provider.
 *
 * @since 1.0.0
 */
class OllamaProvider extends AbstractProvider {

	private const DEFAULT_MODEL = 'llama3.2';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $base_url      Base URL of the Ollama HTTP server.
	 * @param string $default_model Default model tag to use when none is specified.
	 */
	public function __construct(
		private readonly string $base_url = 'http://localhost:11434',
		private readonly string $default_model = self::DEFAULT_MODEL,
	) {}

	/**
	 * Return the provider slug used throughout the plugin.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_slug(): string {
		return 'ollama';
	}

	/**
	 * Return the available model identifiers keyed by model tag.
	 *
	 * @since 1.0.0
	 * @return array<string, string>
	 */
	public function get_models(): array {
		return [ $this->default_model => $this->default_model ];
	}

	/**
	 * Return the default model identifier.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_default_model(): string {
		return $this->default_model;
	}

	/**
	 * Return true when a base URL is configured.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->base_url;
	}

	/**
	 * Ollama does not support function/tool calling in the plugin's tool-use protocol.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function supports_tools(): bool {
		return false;
	}

	/**
	 * Send a completion request to Ollama and return the response.
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
		$body = [
			'model'    => ! empty( $request->model ) ? $request->model : $this->default_model,
			'messages' => $messages,
			'stream'   => false,
			'options'  => [ 'temperature' => $request->temperature ],
		];
		$raw  = $this->post( '/api/chat', $body );

		$content    = $raw['message']['content'] ?? '';
		$in_tokens  = (int) ( $raw['prompt_eval_count'] ?? 0 );
		$out_tokens = (int) ( $raw['eval_count'] ?? 0 );

		return new CompletionResponse(
			$content,
			$raw['model'] ?? $this->default_model,
			$in_tokens,
			$out_tokens,
			0.0, // Local inference — no cost.
			$raw
		);
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
		foreach ( explode( ' ', $response->content ) as $i => $word ) {
			$on_chunk( ( $i > 0 ? ' ' : '' ) . $word );
		}
		return $response;
	}

	/**
	 * Image generation is not supported by Ollama.
	 *
	 * @since 1.0.0
	 * @param string $prompt  Image generation prompt.
	 * @param array  $options Optional generation options.
	 * @throws ProviderException Always — Ollama does not support image generation.
	 */
	public function generate_image( string $prompt, array $options = [] ): never {
		throw new ProviderException(
			'Ollama does not support image generation. Use Gemini or OpenAI.',
			'ollama',
			0
		);
	}

	/**
	 * POST a JSON body to the Ollama API and return the decoded response.
	 *
	 * @since 1.0.0
	 * @param string $path API endpoint path (e.g. '/api/chat').
	 * @param array  $body Request payload.
	 * @return array
	 * @throws ProviderException On HTTP failure or non-2xx status.
	 */
	private function post( string $path, array $body ): array {
		$response = wp_remote_post(
			rtrim( $this->base_url, '/' ) . $path,
			[
				'timeout' => 120, // Ollama can be slow on first run.
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new ProviderException( $response->get_error_message(), 'ollama' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code < 200 || $code >= 300 ) {
			throw new ProviderException( $data['error'] ?? "HTTP {$code}", 'ollama', $code, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $data;
	}
}

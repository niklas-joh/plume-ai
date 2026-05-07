<?php
/**
 * AI provider implementation for the Google Gemini API.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles completions, streaming, and image generation for Google Gemini models.
 *
 * Image generation uses the Imagen 3 model, decoding the base64-encoded response
 * directly to a temp file to avoid a second HTTP round trip.
 *
 * @since 1.0.0
 */
class GeminiProvider extends AbstractProvider {

	private const API_BASE      = 'https://generativelanguage.googleapis.com/v1beta';
	private const DEFAULT_MODEL = 'gemini-2.5-pro';
	private const IMAGE_MODEL   = 'imagen-3.0-generate-001';

	private const MODELS = [
		'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
		'gemini-2.5-flash' => 'Gemini 2.5 Flash',
		'gemini-2.0-flash' => 'Gemini 2.0 Flash',
	];

	private const PRICING = [
		'gemini-2.5-pro'   => [
			'in'  => 1.25,
			'out' => 10.0,
		],
		'gemini-2.5-flash' => [
			'in'  => 0.075,
			'out' => 0.30,
		],
		'gemini-2.0-flash' => [
			'in'  => 0.10,
			'out' => 0.40,
		],
	];

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $api_key Google AI Studio API key.
	 */
	public function __construct( private readonly string $api_key ) {}

	/**
	 * Return the provider slug used throughout the plugin.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_slug(): string {
		return 'gemini';
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
	 * Send a completion request to the Gemini generateContent endpoint.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 * @throws ProviderException On HTTP failure or non-2xx status.
	 */
	protected function do_complete( CompletionRequest $request ): CompletionResponse {
		$model    = ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL;
		$contents = $this->messages_to_contents( $request->messages );
		$body     = [ 'contents' => $contents ];
		if ( '' !== $request->system ) {
			$body['systemInstruction'] = [ 'parts' => [ [ 'text' => $request->system ] ] ];
		}
		if ( ! empty( $request->tools ) ) {
			$body['tools'] = $request->tools; // Already in Gemini wire format (functionDeclarations).
		}
		$raw = $this->post( "/models/{$model}:generateContent", $body );
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
		foreach ( explode( ' ', $response->content ) as $i => $word ) {
			$on_chunk( ( $i > 0 ? ' ' : '' ) . $word );
		}
		return $response;
	}

	/**
	 * Generate an image using the Imagen 3 model.
	 *
	 * The base64-encoded image is decoded directly to a temp file to avoid a
	 * second HTTP request, then handed off to media_handle_sideload.
	 *
	 * @since 1.0.0
	 * @param string $prompt  Image generation prompt.
	 * @param array  $options Optional overrides: 'aspect_ratio'.
	 * @return int WordPress attachment ID.
	 * @throws ProviderException When the API returns no image data or sideload fails.
	 */
	public function generate_image( string $prompt, array $options = [] ): int {
		$body = [
			'instances'  => [ [ 'prompt' => $prompt ] ],
			'parameters' => [
				'sampleCount'       => 1,
				'aspectRatio'       => $options['aspect_ratio'] ?? '1:1',
				'safetyFilterLevel' => 'block_only_high',
			],
		];
		$raw  = $this->post( '/models/' . self::IMAGE_MODEL . ':predict', $body );
		$b64  = $raw['predictions'][0]['bytesBase64Encoded'] ?? '';
		$mime = $raw['predictions'][0]['mimeType'] ?? 'image/png';

		if ( empty( $b64 ) ) {
			throw new ProviderException( 'No image data in Imagen 3 response', 'gemini' );
		}

		// Decode base64 directly to temp file (avoids second HTTP request).
		$tmp_file = tempnam( sys_get_temp_dir(), 'wpaim_img_' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing AI-generated image to temp file; WP_Filesystem is not available in this context.
		file_put_contents( $tmp_file, base64_decode( $b64 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Decoding AI-generated image data; temp file write before media_handle_sideload.
		$ext      = str_contains( $mime, 'png' ) ? 'png' : 'jpg';
		$filename = 'imagen3-' . time() . '.' . $ext;

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_sideload(
			[
				'name'     => $filename,
				'tmp_name' => $tmp_file,
			],
			0,
			$prompt
		);
		wp_delete_file( $tmp_file );

		if ( is_wp_error( $attachment_id ) ) {
			throw new ProviderException(
				'Failed to save Imagen 3 image: ' . $attachment_id->get_error_message(), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				'gemini'
			);
		}
		update_post_meta( $attachment_id, '_wp_ai_mind_prompt', sanitize_textarea_field( $prompt ) );
		return $attachment_id;
	}

	/**
	 * Convert OpenAI-style message array to Gemini contents format.
	 *
	 * @since 1.0.0
	 * @param array $messages Array of messages with 'role' and 'content' keys.
	 * @return array
	 */
	private function messages_to_contents( array $messages ): array {
		return array_map(
			fn( $m ) => [
				'role'  => 'assistant' === $m['role'] ? 'model' : 'user',
				'parts' => [ [ 'text' => $m['content'] ] ],
			],
			$messages
		);
	}

	/**
	 * POST a JSON body to the Gemini API and return the decoded response.
	 *
	 * @since 1.0.0
	 * @param string $path API endpoint path (e.g. '/models/gemini-2.5-pro:generateContent').
	 * @param array  $body Request payload.
	 * @return array
	 * @throws ProviderException On HTTP failure or non-2xx status.
	 */
	private function post( string $path, array $body ): array {
		$url      = self::API_BASE . $path . '?key=' . rawurlencode( $this->api_key );
		$response = wp_remote_post(
			$url,
			[
				'timeout' => WP_AI_MIND_HTTP_TIMEOUT,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new ProviderException( $response->get_error_message(), 'gemini' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? "HTTP {$code}";
			throw new ProviderException( $msg, 'gemini', $code, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $data;
	}

	/**
	 * Parse a raw Gemini API response into a CompletionResponse value object.
	 *
	 * Detects functionCall parts and populates tool_call on the response when the
	 * model has invoked a function. Gemini does not always return a stable call ID,
	 * so one is generated locally when absent.
	 *
	 * @since 1.0.0
	 * @param array  $data  Decoded API response body.
	 * @param string $model Model identifier used for pricing lookup.
	 * @return CompletionResponse
	 */
	private function parse_response( array $data, string $model ): CompletionResponse {
		$meta       = $data['usageMetadata'] ?? [];
		$in_tokens  = (int) ( $meta['promptTokenCount'] ?? 0 );
		$out_tokens = (int) ( $meta['candidatesTokenCount'] ?? 0 );
		$pricing    = self::PRICING[ $model ] ?? self::PRICING[ self::DEFAULT_MODEL ];
		$cost       = ( $in_tokens / 1_000_000 * $pricing['in'] ) + ( $out_tokens / 1_000_000 * $pricing['out'] );

		// Check for a functionCall in the response parts.
		$parts = $data['candidates'][0]['content']['parts'] ?? [];
		foreach ( $parts as $part ) {
			if ( isset( $part['functionCall'] ) ) {
				$fc = $part['functionCall'];
				// Gemini does not always return a stable call ID; generate one for history use.
				$call_id = $fc['id'] ?? \uniqid( 'gemini_', true );
				return new CompletionResponse(
					content: '',
					model: $data['modelVersion'] ?? $model,
					prompt_tokens: $in_tokens,
					completion_tokens: $out_tokens,
					cost_usd: $cost,
					raw: [
						'data'    => $data,
						'call_id' => $call_id,
					], // Preserve generated call_id for history reconstruction.
					tool_call: [
						'id'        => $call_id,
						'name'      => $fc['name'],
						'arguments' => $fc['args'] ?? [],
					],
				);
			}
		}

		$content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
		return new CompletionResponse( $content, $model, $in_tokens, $out_tokens, $cost, $data );
	}
}

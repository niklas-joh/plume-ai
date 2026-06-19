<?php
/**
 * AI provider implementation for the Google Gemini API.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Proxy\ProxyClient;
use Plume\Proxy\SiteRegistration;
use Plume\Tiers\TierManager;
use Plume\Tools\ToolRegistry;

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
	 * Tracks whether the proxy handled logging so maybe_log() can skip double-logging.
	 *
	 * Mutable flag reset in do_complete() (pro_byok path) and maybe_log() (proxy path).
	 * PHP does not run concurrent requests on the same instance, so there is no race risk
	 * in practice; a future async runtime should replace this with a tagged return value.
	 *
	 * @var bool
	 */
	private bool $proxy_logged = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $api_key Google AI Studio API key; empty string for proxy-routed tiers.
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
	 * Return true if the provider can handle requests for the current user.
	 *
	 * Available when an API key is set, or when the site is registered and the
	 * user's tier is eligible for proxy routing.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_available(): bool {
		if ( '' !== $this->api_key ) {
			return true;
		}
		$tier = TierManager::get_user_tier( get_current_user_id() );
		return in_array( $tier, [ 'free', 'trial', 'pro_managed' ], true )
			&& SiteRegistration::is_registered();
	}

	/**
	 * Route completion by tier:
	 *   - free / trial / pro_managed → proxy (ProxyClient handles usage logging)
	 *   - pro_byok                   → direct Gemini API call (AbstractProvider logs usage)
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 * @throws ProviderException On API or proxy failure.
	 */
	protected function do_complete( CompletionRequest $request ): CompletionResponse {
		$tier = TierManager::get_user_tier( get_current_user_id() );

		if ( in_array( $tier, [ 'free', 'trial', 'pro_managed' ], true ) ) {
			return $this->complete_via_proxy( $request );
		}

		// pro_byok — direct Gemini API call; AbstractProvider::complete() will log usage.
		$this->proxy_logged = false;
		$model              = ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL;
		$contents           = $this->messages_to_contents( $request->messages );
		$body               = [ 'contents' => $contents ];
		if ( '' !== $request->system ) {
			$body['systemInstruction'] = [ 'parts' => [ [ 'text' => $request->system ] ] ];
		}
		if ( ! empty( $request->tools ) ) {
			$body['tools'] = $request->tools; // Already in Gemini wire format (functionDeclarations).
			if ( $request->force_tool_use ) {
				$body['tool_config'] = [ 'function_calling_config' => [ 'mode' => 'ANY' ] ];
			}
		}
		$raw = $this->post( "/models/{$model}:generateContent", $body );
		return $this->parse_response( $raw, $model );
	}

	/**
	 * Suppress AbstractProvider usage logging when the proxy already logged it.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest  $request  The original completion request.
	 * @param CompletionResponse $response The completed response.
	 * @return void
	 */
	protected function maybe_log( CompletionRequest $request, CompletionResponse $response ): void {
		if ( $this->proxy_logged ) {
			$this->proxy_logged = false; // Reset for re-use.
			return;
		}
		parent::maybe_log( $request, $response );
	}

	/**
	 * Send the completion request through the NJ proxy client.
	 *
	 * The proxy receives messages in standard user/assistant roles; the Worker
	 * translates them to Gemini's user/model convention before forwarding.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 * @throws ProviderException When the proxy returns a WP_Error.
	 */
	private function complete_via_proxy( CompletionRequest $request ): CompletionResponse {
		// The model sent here is a hint only — the Worker's getModelForTier() may override
		// it to enforce the tier's allowed model list.
		$model       = ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL;
		$raw_options = [
			'model'      => $model,
			'max_tokens' => $request->max_tokens,
			'system'     => '' !== $request->system ? $this->build_system_field( $request->system ) : null,
		];
		$options     = array_filter( $raw_options, fn( $v ) => null !== $v );
		if ( ! empty( $request->tools ) ) {
			// Re-instantiate ToolRegistry to obtain the canonical proxy format.
			// $request->tools carries Gemini wire format so we cannot forward it directly.
			// TODO: have the caller pass tools in canonical proxy format so providers do not
			// need to re-register on every proxied request (SRP concern, tracked in #485).
			$options['tools'] = ( new ToolRegistry() )->get_for_provider( 'proxy' );
		}
		$result = ProxyClient::chat( $request->messages, $options, 'gemini' );

		if ( is_wp_error( $result ) ) {
			throw new ProviderException( $result->get_error_message(), 'gemini' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// ProxyClient::chat() already called UsageTracker::log_usage() — flag to suppress parent logging.
		$this->proxy_logged = true;

		// Build CompletionResponse directly from the proxy's normalised shape { content, usage, tool_call? }.
		// parse_response() expects the upstream Gemini wire format and cannot handle the normalised response.
		$in_tokens  = (int) ( $result['usage']['input_tokens'] ?? 0 );
		$out_tokens = (int) ( $result['usage']['output_tokens'] ?? 0 );
		$pricing    = self::PRICING[ $model ] ?? self::PRICING[ self::DEFAULT_MODEL ];
		$cost       = ( $in_tokens / 1_000_000 * $pricing['in'] ) + ( $out_tokens / 1_000_000 * $pricing['out'] );

		if ( ! empty( $result['tool_call'] ) ) {
			return new CompletionResponse(
				content: '',
				model: $model,
				prompt_tokens: $in_tokens,
				completion_tokens: $out_tokens,
				cost_usd: $cost,
				raw: $result,
				tool_call: $result['tool_call'],
			);
		}

		return new CompletionResponse( $result['content'] ?? '', $model, $in_tokens, $out_tokens, $cost, $result );
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
		$tmp_file = tempnam( sys_get_temp_dir(), 'plume_img_' );
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
		update_post_meta( $attachment_id, '_plume_prompt', sanitize_textarea_field( $prompt ) );
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
				'timeout' => PLUME_HTTP_TIMEOUT,
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

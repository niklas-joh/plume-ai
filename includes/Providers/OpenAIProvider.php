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

use WP_AI_Mind\Proxy\NJ_Proxy_Client;
use WP_AI_Mind\Proxy\NJ_Site_Registration;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tools\ToolRegistry;

/**
 * Handles completions, streaming, and image generation for OpenAI models.
 *
 * Free, trial, and pro_managed tiers route through the NJ proxy client so that
 * usage is logged centrally. The pro_byok tier calls the OpenAI API directly.
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
	 * @param string $api_key OpenAI API key; empty string for proxy-routed tiers.
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
		$tier = NJ_Tier_Manager::get_user_tier( get_current_user_id() );
		return in_array( $tier, [ 'free', 'trial', 'pro_managed' ], true )
			&& NJ_Site_Registration::is_registered();
	}

	/**
	 * Route completion by tier:
	 *   - free / trial / pro_managed → proxy (NJ_Proxy_Client handles usage logging)
	 *   - pro_byok                   → direct OpenAI API call (AbstractProvider logs usage)
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 * @throws ProviderException On API or proxy failure.
	 */
	protected function do_complete( CompletionRequest $request ): CompletionResponse {
		$tier = NJ_Tier_Manager::get_user_tier( get_current_user_id() );

		if ( in_array( $tier, [ 'free', 'trial', 'pro_managed' ], true ) ) {
			return $this->complete_via_proxy( $request );
		}

		// pro_byok — direct OpenAI API call; AbstractProvider::complete() will log usage.
		$this->proxy_logged = false;
		$messages           = $request->messages;
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
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return CompletionResponse
	 * @throws ProviderException When the proxy returns a WP_Error.
	 */
	private function complete_via_proxy( CompletionRequest $request ): CompletionResponse {
		$model       = ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL;
		$raw_options = [
			'model'      => $model,
			'max_tokens' => $request->max_tokens,
			'system'     => '' !== $request->system ? $request->system : null,
		];
		$options     = array_filter( $raw_options, fn( $v ) => null !== $v );
		if ( ! empty( $request->tools ) ) {
			// Re-instantiate ToolRegistry to obtain the canonical proxy format.
			// $request->tools carries OpenAI wire format so we cannot forward it directly.
			// TODO: have the caller pass tools in canonical proxy format so providers do not
			// need to re-register on every proxied request (SRP concern, tracked in #485).
			$options['tools'] = ( new ToolRegistry() )->get_for_provider( 'proxy' );
		}
		$result = NJ_Proxy_Client::chat( $request->messages, $options, 'openai' );

		if ( is_wp_error( $result ) ) {
			throw new ProviderException( $result->get_error_message(), 'openai' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// NJ_Proxy_Client::chat() already called NJ_Usage_Tracker::log_usage() — flag to suppress parent logging.
		$this->proxy_logged = true;

		// Build CompletionResponse directly from the proxy's normalised shape { content, usage, tool_call? }.
		// parse_response() expects the upstream OpenAI wire format and cannot handle the normalised response.
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

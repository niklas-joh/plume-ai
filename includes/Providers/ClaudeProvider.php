<?php
/**
 * AI provider implementation for the Anthropic Claude API.
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

/**
 * Handles completions, streaming, and tier-aware routing for Anthropic Claude.
 *
 * Free, trial, and pro_managed tiers route through the NJ proxy client so that
 * usage is logged centrally. The pro_byok tier calls the Anthropic API directly.
 *
 * @since 1.0.0
 */
class ClaudeProvider extends AbstractProvider {

	private const API_BASE      = 'https://api.anthropic.com/v1';
	private const API_VERSION   = '2023-06-01';
	private const DEFAULT_MODEL = 'claude-sonnet-4-6';

	// Mirrors TIER_MODELS in wp-ai-mind-proxy/src/index.ts — update both when adding models.
	private const MODELS = [
		'claude-opus-4-6'           => 'Claude Opus 4.6',
		'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
		'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
	];

	// Cost per 1M tokens (input/output) in USD.
	private const PRICING = [
		'claude-opus-4-6'           => [
			'in'  => 15.0,
			'out' => 75.0,
		],
		'claude-sonnet-4-6'         => [
			'in'  => 3.0,
			'out' => 15.0,
		],
		'claude-haiku-4-5-20251001' => [
			'in'  => 0.25,
			'out' => 1.25,
		],
	];

	/**
	 * Tracks whether the proxy handled logging so maybe_log() can skip double-logging.
	 *
	 * @var bool
	 */
	private bool $proxy_logged = false;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param string $api_key Anthropic API key; empty string for proxy-routed tiers.
	 */
	public function __construct( private readonly string $api_key ) {}

	/**
	 * Return the provider slug used throughout the plugin.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_slug(): string {
		return 'claude';
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
	 *   - pro_byok                   → direct Anthropic API call (AbstractProvider logs usage)
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

		// pro_byok — direct Anthropic API call; AbstractProvider::complete() will log usage.
		$this->proxy_logged = false;
		$body               = $this->build_body( $request );
		$raw                = $this->post( '/messages', $body );
		return $this->parse_response( $raw, $request );
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
		$result = NJ_Proxy_Client::chat(
			$request->messages,
			array_filter(
				[
					'model'      => ! empty( $request->model ) ? $request->model : null,
					'max_tokens' => $request->max_tokens,
					'system'     => '' !== $request->system ? $request->system : null,
				],
				fn( $v ) => null !== $v
			)
		);

		if ( is_wp_error( $result ) ) {
			throw new ProviderException( $result->get_error_message(), 'claude' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// NJ_Proxy_Client::chat() already called NJ_Usage_Tracker::log_usage() — flag to suppress parent logging.
		$this->proxy_logged = true;
		return $this->parse_response( $result, $request );
	}

	/**
	 * Simulate streaming by word-chunking a non-streaming completion.
	 *
	 * The WP HTTP API does not support SSE natively, so a full completion is
	 * fetched and the response is word-split to simulate incremental delivery.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request  The completion request.
	 * @param callable          $on_chunk Callback invoked with each word token.
	 * @return CompletionResponse
	 * @throws ProviderException On API or proxy failure.
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
	 * Image generation is not supported by Claude.
	 *
	 * @since 1.0.0
	 * @param string $prompt  Image generation prompt.
	 * @param array  $options Optional generation options.
	 * @throws ProviderException Always — Claude does not support image generation.
	 */
	public function generate_image( string $prompt, array $options = [] ): never {
		throw new ProviderException(
			'Claude does not support image generation. Use Gemini or OpenAI.',
			'claude',
			0
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Build the Anthropic API request body from a completion request.
	 *
	 * @since 1.0.0
	 * @param CompletionRequest $request The completion request.
	 * @return array
	 */
	private function build_body( CompletionRequest $request ): array {
		$body = [
			'model'      => ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL,
			'max_tokens' => $request->max_tokens,
			'messages'   => $request->messages,
		];
		if ( '' !== $request->system ) {
			$body['system'] = $request->system;
		}
		if ( ! empty( $request->tools ) ) {
			$body['tools'] = $request->tools; // Already in Claude wire format from ToolRegistry.
		}
		return $body;
	}

	/**
	 * POST a JSON body to the Anthropic API and return the decoded response.
	 *
	 * @since 1.0.0
	 * @param string $path API endpoint path (e.g. '/messages').
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
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new ProviderException( $response->get_error_message(), 'claude' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? "HTTP {$code}";
			throw new ProviderException( $msg, 'claude', $code, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $data;
	}

	/**
	 * Parse a raw Anthropic API response into a CompletionResponse value object.
	 *
	 * Detects tool_use content blocks and populates tool_call on the response
	 * when the model has invoked a function.
	 *
	 * @since 1.0.0
	 * @param array             $data    Decoded API response body.
	 * @param CompletionRequest $request The originating request (used for model fallback).
	 * @return CompletionResponse
	 */
	protected function parse_response( array $data, CompletionRequest $request ): CompletionResponse {
		$model      = $data['model'] ?? ( ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL );
		$in_tokens  = (int) ( $data['usage']['input_tokens'] ?? 0 );
		$out_tokens = (int) ( $data['usage']['output_tokens'] ?? 0 );
		$pricing    = self::PRICING[ $model ] ?? self::PRICING[ self::DEFAULT_MODEL ];
		$cost       = ( $in_tokens / 1_000_000 * $pricing['in'] ) + ( $out_tokens / 1_000_000 * $pricing['out'] );

		// Check for a tool_use block in the response content.
		$content_blocks = $data['content'] ?? [];
		$tool_use_block = null;
		foreach ( $content_blocks as $block ) {
			if ( ( $block['type'] ?? '' ) === 'tool_use' ) {
				$tool_use_block = $block;
				break;
			}
		}

		if ( null !== $tool_use_block ) {
			return new CompletionResponse(
				content: '',
				model: $model,
				prompt_tokens: $in_tokens,
				completion_tokens: $out_tokens,
				cost_usd: $cost,
				raw: $data,
				tool_call: [
					'id'        => $tool_use_block['id'],
					'name'      => $tool_use_block['name'],
					'arguments' => $tool_use_block['input'] ?? [],
				],
			);
		}

		// Normal text response — extract text from the first text block.
		$text_content = '';
		foreach ( $content_blocks as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$text_content = $block['text'] ?? '';
				break;
			}
		}

		return new CompletionResponse( $text_content, $model, $in_tokens, $out_tokens, $cost, $data );
	}
}

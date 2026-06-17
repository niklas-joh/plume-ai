<?php
/**
 * AI provider implementation for the Anthropic Claude API.
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
	// Minimum character count for a system prompt to receive a cache_control block (~2,048 tokens).
	private const CACHE_CONTROL_MIN_CHARS = 8192;

	// Mirrors TIER_MODELS in plume-proxy/src/index.ts — update both when adding models.
	private const MODELS = [
		'claude-opus-4-6'           => 'Claude Opus 4.6',
		'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
		'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
	];

	// Cost per 1M tokens (input/output/cache) in USD.
	// cache_in: cache-write surcharge (1.25× normal input).
	// cache_read_in: cache-read rate (0.10× normal input).
	private const PRICING = [
		'claude-opus-4-6'           => [
			'in'            => 5.0,
			'out'           => 25.0,
			'cache_in'      => 6.25,
			'cache_read_in' => 0.50,
		],
		'claude-sonnet-4-6'         => [
			'in'            => 3.0,
			'out'           => 15.0,
			'cache_in'      => 3.75,
			'cache_read_in' => 0.30,
		],
		'claude-haiku-4-5-20251001' => [
			'in'            => 1.0,
			'out'           => 5.0,
			'cache_in'      => 1.25,
			'cache_read_in' => 0.10,
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
		$tier = TierManager::get_user_tier( get_current_user_id() );
		return in_array( $tier, [ 'free', 'trial', 'pro_managed' ], true )
			&& SiteRegistration::is_registered();
	}

	/**
	 * Route completion by tier:
	 *   - free / trial / pro_managed → proxy (ProxyClient handles usage logging)
	 *   - pro_byok                   → direct Anthropic API call (AbstractProvider logs usage)
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
		$raw_options = [
			'model'      => ! empty( $request->model ) ? $request->model : null,
			'max_tokens' => $request->max_tokens,
			'system'     => '' !== $request->system ? $this->system_payload( $request->system ) : null,
		];
		$options     = array_filter( $raw_options, fn( $v ) => null !== $v );
		if ( ! empty( $request->tools ) ) {
			// Convert from Claude wire format (input_schema) to canonical proxy format (parameters).
			$options['tools'] = array_map(
				fn( array $t ) => [
					'name'        => $t['name'],
					'description' => $t['description'] ?? '',
					'parameters'  => $t['input_schema'] ?? [],
				],
				$request->tools
			);
		}
		$result = ProxyClient::chat( $request->messages, $options, 'claude' );

		if ( is_wp_error( $result ) ) {
			throw new ProviderException( $result->get_error_message(), 'claude' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		// ProxyClient::chat() already called UsageTracker::log_usage() — flag to suppress parent logging.
		$this->proxy_logged = true;

		// Build CompletionResponse directly from the proxy's normalised shape { content, usage, tool_call? }.
		// parse_response() expects the upstream Claude wire format and cannot handle the normalised response.
		$model      = ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL;
		$in_tokens  = (int) ( $result['usage']['input_tokens'] ?? 0 );
		$out_tokens = (int) ( $result['usage']['output_tokens'] ?? 0 );
		$cost       = $this->calculate_cost( $model, $in_tokens, $out_tokens );

		if ( ! empty( $result['tool_call'] ) ) {
			return new CompletionResponse(
				content: $result['content'] ?? '',
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
	 * Format the system prompt for Claude's API, adding a cache_control block when
	 * the prompt exceeds CACHE_CONTROL_MIN_CHARS characters (~2,048 tokens at 4 chars/token).
	 * Short prompts are passed through unchanged.
	 *
	 * @since NEXT_VERSION
	 * @param string $system Raw system prompt text.
	 * @return string|array Plain string for short prompts; cache-control block array for long ones.
	 */
	protected function system_payload( string $system ): string|array {
		if ( mb_strlen( $system ) <= self::CACHE_CONTROL_MIN_CHARS ) {
			return $system;
		}
		return [
			[
				'type'          => 'text',
				'text'          => $system,
				'cache_control' => [ 'type' => 'ephemeral' ],
			],
		];
	}

	/**
	 * Calculate the USD cost for a completion based on token usage.
	 *
	 * Centralises pricing so future rate changes require a single edit.
	 * Cache read tokens are billed at cache_read_in (0.10× normal input).
	 * Cache write tokens are billed at cache_in (1.25× normal input).
	 *
	 * @since 1.0.0
	 * @param string $model              Model slug used for the completion.
	 * @param int    $in_tokens          Normal input token count.
	 * @param int    $out_tokens         Output token count.
	 * @param int    $cache_read_tokens  Tokens read from cache (billed at cache_read_in rate).
	 * @param int    $cache_write_tokens Tokens written to cache (billed at cache_in rate).
	 * @return float Cost in USD.
	 */
	private function calculate_cost(
		string $model,
		int $in_tokens,
		int $out_tokens,
		int $cache_read_tokens = 0,
		int $cache_write_tokens = 0
	): float {
		$pricing     = self::PRICING[ $model ] ?? self::PRICING[ self::DEFAULT_MODEL ];
		$normal_cost = $in_tokens / 1_000_000 * $pricing['in'];
		$out_cost    = $out_tokens / 1_000_000 * $pricing['out'];
		$read_cost   = $cache_read_tokens / 1_000_000 * $pricing['cache_read_in'];
		$write_cost  = $cache_write_tokens / 1_000_000 * $pricing['cache_in'];
		return $normal_cost + $out_cost + $read_cost + $write_cost;
	}

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
			$body['system'] = $this->system_payload( $request->system );
		}
		if ( ! empty( $request->tools ) ) {
			$body['tools'] = $request->tools; // Already in Claude wire format from ToolRegistry.
			if ( $request->force_tool_use ) {
				$body['tool_choice'] = [ 'type' => 'any' ];
			}
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
				'timeout' => PLUME_HTTP_TIMEOUT,
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
		$model       = $data['model'] ?? ( ! empty( $request->model ) ? $request->model : self::DEFAULT_MODEL );
		$in_tokens   = (int) ( $data['usage']['input_tokens'] ?? 0 );
		$out_tokens  = (int) ( $data['usage']['output_tokens'] ?? 0 );
		$cache_read  = (int) ( $data['usage']['cache_read_input_tokens'] ?? 0 );
		$cache_write = (int) ( $data['usage']['cache_creation_input_tokens'] ?? 0 );
		$cost        = $this->calculate_cost( $model, $in_tokens, $out_tokens, $cache_read, $cache_write );

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
				content:            '',
				model:              $model,
				prompt_tokens:      $in_tokens,
				completion_tokens:  $out_tokens,
				cost_usd:           $cost,
				raw:                $data,
				tool_call:          [
					'id'        => $tool_use_block['id'],
					'name'      => $tool_use_block['name'],
					'arguments' => $tool_use_block['input'] ?? [],
				],
				cache_read_tokens:  $cache_read,
				cache_write_tokens: $cache_write,
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

		return new CompletionResponse(
			content:            $text_content,
			model:              $model,
			prompt_tokens:      $in_tokens,
			completion_tokens:  $out_tokens,
			cost_usd:           $cost,
			raw:                $data,
			cache_read_tokens:  $cache_read,
			cache_write_tokens: $cache_write,
		);
	}
}

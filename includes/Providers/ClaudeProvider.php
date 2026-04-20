<?php
// includes/Providers/ClaudeProvider.php
declare( strict_types=1 );
namespace WP_AI_Mind\Providers;

use WP_AI_Mind\Proxy\NJ_Proxy_Client;
use WP_AI_Mind\Proxy\NJ_Site_Registration;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class ClaudeProvider extends AbstractProvider {

	private const API_BASE      = 'https://api.anthropic.com/v1';
	private const API_VERSION   = '2023-06-01';
	private const DEFAULT_MODEL = 'claude-sonnet-4-6';

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

	/** Tracks whether the proxy handled logging so maybe_log() can skip double-logging. */
	private bool $proxy_logged = false;

	public function __construct( private readonly string $api_key ) {}

	public function get_slug(): string {
		return 'claude'; }
	public function get_models(): array {
		return self::MODELS; }
	public function get_default_model(): string {
		return self::DEFAULT_MODEL; }
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
	 * @param CompletionRequest  $request
	 * @param CompletionResponse $response
	 */
	protected function maybe_log( CompletionRequest $request, CompletionResponse $response ): void {
		if ( $this->proxy_logged ) {
			$this->proxy_logged = false; // Reset for re-use.
			return;
		}
		parent::maybe_log( $request, $response );
	}

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

	protected function do_stream( CompletionRequest $request, callable $on_chunk ): CompletionResponse {
		// Claude supports SSE; WP HTTP API doesn't support streaming natively,
		// so we fall back to non-streaming and simulate chunking.
		$response = $this->do_complete( $request );
		$words    = explode( ' ', $response->content );
		foreach ( $words as $i => $word ) {
			$on_chunk( ( $i > 0 ? ' ' : '' ) . $word );
		}
		return $response;
	}

	public function generate_image( string $prompt, array $options = [] ): int {
		throw new ProviderException(
			'Claude does not support image generation. Use Gemini or OpenAI.',
			'claude',
			0
		);
	}

	// ── Private helpers ───────────────────────────────────────────────────────

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
			$body['tools'] = $request->tools; // already in Claude wire format from ToolRegistry
		}
		return $body;
	}

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

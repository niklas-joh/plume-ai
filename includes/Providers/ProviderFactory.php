<?php
/**
 * Factory for instantiating AI provider instances.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_AI_Mind\Settings\ProviderSettings;

/**
 * Creates concrete provider instances from a slug and decrypted API keys.
 *
 * @since 1.0.0
 */
class ProviderFactory {

	/**
	 * Inject the provider settings to supply decrypted API keys.
	 *
	 * @since 1.0.0
	 * @param ProviderSettings $settings Provider settings holding encrypted API keys.
	 */
	public function __construct( private readonly ProviderSettings $settings ) {}

	/**
	 * Instantiate a provider by slug.
	 *
	 * @since 1.0.0
	 * @param string $slug Provider slug: 'claude', 'openai', 'gemini', or 'ollama'.
	 * @return ProviderInterface
	 * @throws \InvalidArgumentException For unknown slugs.
	 */
	public function make( string $slug ): ProviderInterface {
		return match ( $slug ) {
			'claude' => new ClaudeProvider( $this->settings->get_api_key( 'claude' ) ),
			'openai' => new OpenAIProvider( $this->settings->get_api_key( 'openai' ) ),
			'gemini' => new GeminiProvider( $this->settings->get_api_key( 'gemini' ) ),
			'ollama' => new OllamaProvider(
				(string) get_option( 'wp_ai_mind_ollama_url', 'http://localhost:11434' ),
				(string) get_option( 'wp_ai_mind_ollama_model', 'llama3.2' )
			),
			default  => throw new \InvalidArgumentException( "Unknown provider: {$slug}" ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
	}

	/**
	 * Return the active provider for text completions (resolved from plugin settings).
	 *
	 * @since 1.0.0
	 * @return ProviderInterface
	 */
	public function make_default(): ProviderInterface {
		$slug = get_option( 'wp_ai_mind_default_provider', 'claude' );
		return $this->make( ! empty( $slug ) ? $slug : 'claude' );
	}

	/**
	 * Return the active provider for image generation (resolved from plugin settings).
	 *
	 * @since 1.0.0
	 * @return ProviderInterface
	 */
	public function make_image_provider(): ProviderInterface {
		$slug = get_option( 'wp_ai_mind_image_provider', 'gemini' );
		return $this->make( ! empty( $slug ) ? $slug : 'gemini' );
	}

	/**
	 * Return all providers that have a valid API key configured.
	 *
	 * @since 1.0.0
	 * @return ProviderInterface[]
	 */
	public function get_available(): array {
		return array_values(
			array_filter(
				array_map( fn( $s ) => $this->make( $s ), [ 'claude', 'openai', 'gemini', 'ollama' ] ),
				fn( $p ) => $p->is_available()
			)
		);
	}

	/**
	 * Return all supported providers unconditionally, regardless of API key status.
	 *
	 * @since 1.0.0
	 * @return ProviderInterface[]
	 */
	public function get_all(): array {
		return array_map( fn( $s ) => $this->make( $s ), [ 'claude', 'openai', 'gemini', 'ollama' ] );
	}
}

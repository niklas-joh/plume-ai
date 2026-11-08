<?php
/**
 * Factory for instantiating AI provider instances.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Settings\ProviderSettings;
use Plume\Tiers\TierManager;

/**
 * Creates concrete provider instances from a slug and decrypted API keys.
 *
 * @since 1.0.0
 */
class ProviderFactory {

	/**
	 * Providers that can route through the managed proxy when no API key is set.
	 *
	 * Ollama is excluded — it has no proxy path and requires a configured URL.
	 *
	 * @since 1.12.0
	 * @var string[]
	 */
	public const PROXY_CAPABLE = [ 'claude', 'openai', 'gemini' ];

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
				(string) get_option( 'plume_ollama_url', 'http://localhost:11434' ),
				(string) get_option( 'plume_ollama_model', 'llama3.2' )
			),
			default  => throw new \InvalidArgumentException( "Unknown provider: {$slug}" ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
	}

	/**
	 * Return the active provider for text completions (resolved from plugin settings).
	 *
	 * Used by Generator and SEO. Applies the same runtime resolution as chat (see
	 * resolve_text_slug()).
	 *
	 * @since 1.0.0
	 * @since NEXT_VERSION Resolves a managed-disabled default at runtime.
	 * @return ProviderInterface
	 * @throws ProviderException With code 'provider_key_missing' when the site has its own
	 *                           key for some provider but none for the stored default,
	 *                           which the managed service does not serve.
	 */
	public function make_default(): ProviderInterface {
		$tier      = TierManager::get_user_tier();
		$requested = (string) get_option( 'plume_default_provider', 'claude' );
		$requested = '' !== $requested ? $requested : 'claude';

		if ( $this->missing_key_for( $requested, $tier ) ) {
			// The message is plain translated text, surfaced through JSON REST responses, not HTML.
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new ProviderException(
				$this->missing_key_message( $requested ),
				$requested,
				422,
				[],
				null,
				'provider_key_missing'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $this->make( $this->resolve_text_slug( $requested, $tier ) );
	}

	/**
	 * Return the active provider for image generation (resolved from plugin settings).
	 *
	 * @since 1.0.0
	 * @return ProviderInterface
	 */
	public function make_image_provider(): ProviderInterface {
		$slug = get_option( 'plume_image_provider', 'gemini' );
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

	/**
	 * Human-readable provider name for messages.
	 *
	 * Brand names are not translated.
	 *
	 * @since NEXT_VERSION
	 * @param string $slug Provider slug.
	 * @return string Brand name, or the capitalised slug for an unknown provider.
	 */
	public function label( string $slug ): string {
		return match ( $slug ) {
			'claude' => 'Claude',
			'openai' => 'OpenAI',
			'gemini' => 'Gemini',
			default  => ucfirst( $slug ),
		};
	}

	/**
	 * Whether a request for this provider would go through the managed proxy.
	 *
	 * True for proxy-capable providers on a managed tier when the site has no own key
	 * for them. Own-key requests call the provider directly, and Ollama has no proxy.
	 *
	 * @since NEXT_VERSION
	 * @param string $slug Provider slug.
	 * @param string $tier Site tier slug.
	 * @return bool
	 */
	public function is_managed_route( string $slug, string $tier ): bool {
		return in_array( $slug, self::PROXY_CAPABLE, true )
			&& 'pro_byok' !== $tier
			&& ! $this->settings->has_key( $slug );
	}

	/**
	 * Resolve the provider slug to use for a text request.
	 *
	 * Resolved at runtime and never written back to `plume_default_provider`: when the
	 * Worker re-enables a provider (a KV change) or the site adds its own key, the
	 * user's original choice takes effect again without any migration.
	 *
	 * An own-key site is not substituted (see missing_key_for()): it is told which key
	 * is missing instead of silently getting another provider.
	 *
	 * @since NEXT_VERSION
	 * @param string $slug Requested provider slug; empty means Claude.
	 * @param string $tier Site tier slug.
	 * @return string Slug to build the provider from.
	 */
	public function resolve_text_slug( string $slug, string $tier ): string {
		if ( '' === $slug ) {
			return 'claude';
		}

		// TEMPORARY(#1020): a keyless site whose provider the managed service no longer
		// serves is answered by Claude, which is what the Worker would do anyway.
		// On removal: delete this branch so the slug is returned unchanged; keep the
		// method as a seam or inline it.
		if (
			$this->is_managed_route( $slug, $tier )
			&& ! ManagedCatalogue::is_enabled( $slug, $tier )
			&& ! $this->settings->has_any_key()
		) {
			return 'claude';
		}

		return $slug;
	}

	/**
	 * Whether the requested provider is unavailable because the site lacks its own key.
	 *
	 * True when the request would use the managed service for a provider it does not
	 * serve, and the site is an own-key site (so it is not silently switched to Claude).
	 *
	 * @since NEXT_VERSION
	 * @param string $slug Requested provider slug.
	 * @param string $tier Site tier slug.
	 * @return bool
	 */
	public function missing_key_for( string $slug, string $tier ): bool {
		return $this->is_managed_route( $slug, $tier )
			&& ! ManagedCatalogue::is_enabled( $slug, $tier )
			// TEMPORARY(#1020): keyless sites are substituted with Claude instead of told
			// to add a key. On removal: delete this clause so every keyless site gets
			// provider_key_missing too.
			&& $this->settings->has_any_key();
	}

	/**
	 * The user-facing message for a provider the site has no key for.
	 *
	 * @since NEXT_VERSION
	 * @param string $slug Requested provider slug.
	 * @return string Translated message.
	 */
	public function missing_key_message( string $slug ): string {
		return sprintf(
			/* translators: %s: AI provider name, e.g. "OpenAI". */
			__( 'You have not added an API key for %s. Add one under Settings → Providers, or choose a different model.', 'plume' ),
			$this->label( $slug )
		);
	}
}

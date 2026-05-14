<?php
/**
 * Encrypted storage and retrieval of AI provider API keys.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and retrieves encrypted API keys for AI providers.
 *
 * Keys are AES-256-CBC encrypted using WordPress's AUTH_KEY and
 * SECURE_AUTH_KEY constants as the secret. If a matching environment
 * variable is set, it takes precedence over the database-stored key.
 */
class ProviderSettings {

	private const OPTION_KEY      = 'wp_ai_mind_provider_keys';
	private const CIPHER          = 'AES-256-CBC';
	private const VALID_PROVIDERS = [ 'claude', 'openai', 'gemini', 'ollama' ];
	private const ENV_VARS        = [
		'claude' => 'CLAUDE_API_KEY',
		'openai' => 'OPENAI_API_KEY',
		'gemini' => 'GEMINI_API_KEY',
	];

	/**
	 * Decrypted API keys keyed by provider slug.
	 *
	 * @var array<string, string>
	 */
	private array $keys;

	/**
	 * Load stored encrypted keys from the database on construction.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$raw        = get_option( self::OPTION_KEY, [] );
		$this->keys = is_array( $raw ) ? $raw : [];
	}

	/**
	 * Return the plaintext API key for a provider.
	 *
	 * Environment variable takes precedence over the database value so
	 * server-level secrets can override per-site settings without touching the DB.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider slug: 'claude', 'openai', 'gemini', or 'ollama'.
	 * @return string Decrypted API key, or empty string if none is set.
	 */
	public function get_api_key( string $provider ): string {
		$env_var = self::ENV_VARS[ $provider ] ?? null;
		if ( $env_var ) {
			$env_value = getenv( $env_var );
			if ( false !== $env_value && '' !== $env_value ) {
				return $env_value;
			}
		}

		if ( ! isset( $this->keys[ $provider ] ) ) {
			return '';
		}
		return self::decrypt( $this->keys[ $provider ] );
	}

	/**
	 * Encrypt and persist an API key for a provider.
	 *
	 * Silently ignores unknown provider slugs.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider slug.
	 * @param string $key      Plaintext API key to store.
	 * @return void
	 */
	public function set_api_key( string $provider, string $key ): void {
		if ( ! in_array( $provider, self::VALID_PROVIDERS, true ) ) {
			return;
		}
		$this->keys[ $provider ] = self::encrypt( $key );
		update_option( self::OPTION_KEY, $this->keys );
	}

	/**
	 * Check whether a non-empty key is available for a provider.
	 *
	 * @since 1.0.0
	 * @param string $provider Provider slug.
	 * @return bool True if a key is set via environment variable or database.
	 */
	public function has_key( string $provider ): bool {
		return '' !== $this->get_api_key( $provider );
	}

	// ── Encryption helpers ─────────────────────────────────────────────────────

	/**
	 * Derive the 256-bit encryption secret from WordPress salt constants.
	 *
	 * @since 1.0.0
	 * @return string Binary 32-byte key.
	 */
	private static function secret(): string {
		return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
	}

	/**
	 * Encrypt a plaintext string with AES-256-CBC and base64-encode the result.
	 *
	 * IV is prepended as raw bytes (fixed length) rather than using a '::' separator,
	 * avoiding any ambiguity when binary IV bytes contain the separator sequence.
	 *
	 * @since 1.0.0
	 * @param string $plain Plaintext to encrypt.
	 * @return string Base64-encoded IV-prefixed ciphertext, or empty string on failure.
	 */
	private static function encrypt( string $plain ): string {
		$iv_len     = openssl_cipher_iv_length( self::CIPHER );
		$iv         = random_bytes( $iv_len );
		$ciphertext = openssl_encrypt( $plain, self::CIPHER, self::secret(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return '';
		}
		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding encrypted API key, not obfuscation.
	}

	/**
	 * Decrypt a base64-encoded IV-prefixed ciphertext produced by encrypt().
	 *
	 * @since 1.0.0
	 * @param string $encoded Base64-encoded encrypted value.
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	private static function decrypt( string $encoded ): string {
		$iv_len  = openssl_cipher_iv_length( self::CIPHER );
		$decoded = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding encrypted API key, not obfuscation.
		if ( false === $decoded || strlen( $decoded ) <= $iv_len ) {
			return '';
		}
		$iv         = substr( $decoded, 0, $iv_len );
		$ciphertext = substr( $decoded, $iv_len );
		$plain      = openssl_decrypt( $ciphertext, self::CIPHER, self::secret(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}
}

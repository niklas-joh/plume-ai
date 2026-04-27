<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro BYOK: API key management for each provider.
 *
 * Keys are stored in user meta as AES-256-CBC encrypted strings.
 * Meta key format: wp_ai_mind_api_key_{provider}
 *
 * @since 1.2.0
 */
class NJ_Api_Key_Settings {

	private const SUPPORTED_PROVIDERS = [ 'claude', 'openai', 'gemini' ];

	/**
	 * Registers WordPress action hooks for the admin menu and REST API.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Registers the "AI Mind API Keys" settings page under Settings.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function add_menu_page(): void {
		add_options_page(
			__( 'WP AI Mind — API Keys', 'wp-ai-mind' ),
			__( 'AI Mind API Keys', 'wp-ai-mind' ),
			'manage_options',
			'wp-ai-mind-api-keys',
			[ self::class, 'render' ]
		);
	}

	/**
	 * Registers the /wp-ai-mind/v1/user/api-key REST endpoint.
	 *
	 * Restricted to logged-in users whose tier grants the 'own_api_key' feature.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'wp-ai-mind/v1',
			'/user/api-key',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'save_api_key' ],
				'permission_callback' => fn() => is_user_logged_in() && NJ_Tier_Manager::user_can( 'own_api_key' ),
				'args'                => [
					'provider' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'enum'              => self::SUPPORTED_PROVIDERS,
					],
					'api_key'  => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Saves or deletes a provider API key for the current user.
	 *
	 * Passing an empty string for 'api_key' removes the stored key.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request REST request containing 'provider' and 'api_key' params.
	 * @return WP_REST_Response Response with 'saved', 'deleted', or 'error' key.
	 */
	public static function save_api_key( WP_REST_Request $request ): WP_REST_Response {
		$provider = $request->get_param( 'provider' );
		$api_key  = $request->get_param( 'api_key' );
		$user_id  = get_current_user_id();

		if ( empty( $api_key ) ) {
			delete_user_meta( $user_id, "wp_ai_mind_api_key_{$provider}" );
			return new WP_REST_Response( [ 'deleted' => true ] );
		}

		$encrypted = self::encrypt( $api_key );
		if ( false === $encrypted ) {
			return new WP_REST_Response( [ 'error' => 'Encryption failed' ], 500 );
		}

		update_user_meta( $user_id, "wp_ai_mind_api_key_{$provider}", $encrypted );
		return new WP_REST_Response( [ 'saved' => true ] );
	}

	/**
	 * Outputs the API keys settings page HTML.
	 *
	 * Calls wp_die() when the current user's tier does not include 'own_api_key'.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function render(): void {
		if ( ! NJ_Tier_Manager::user_can( 'own_api_key' ) ) {
			wp_die( esc_html__( 'This page requires a Pro BYOK plan.', 'wp-ai-mind' ) );
		}

		$nonce = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<p><?php esc_html_e( 'Your API keys are encrypted before storage. Enter a key to save it; leave blank to remove it.', 'wp-ai-mind' ); ?></p>

			<table class="form-table" role="presentation">
				<?php foreach ( self::SUPPORTED_PROVIDERS as $provider ) : ?>
					<?php $placeholder = self::get_masked_key( $provider ) ? self::get_masked_key( $provider ) : __( 'Not set', 'wp-ai-mind' ); ?>
				<tr>
					<th scope="row">
						<label for="api-key-<?php echo esc_attr( $provider ); ?>">
							<?php echo esc_html( ucfirst( $provider ) ); ?>
						</label>
					</th>
					<td>
						<input
							type="password"
							id="api-key-<?php echo esc_attr( $provider ); ?>"
							data-provider="<?php echo esc_attr( $provider ); ?>"
							class="wp-ai-mind-api-key-input regular-text"
							placeholder="<?php echo esc_attr( $placeholder ); ?>"
							value=""
							autocomplete="new-password"
						>
						<button
							type="button"
							class="button wp-ai-mind-save-api-key"
							data-provider="<?php echo esc_attr( $provider ); ?>"
							data-nonce="<?php echo esc_attr( $nonce ); ?>"
							data-endpoint="<?php echo esc_url( rest_url( 'wp-ai-mind/v1/user/api-key' ) ); ?>">
							<?php esc_html_e( 'Save', 'wp-ai-mind' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</table>

			<script>
			document.querySelectorAll('.wp-ai-mind-save-api-key').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var provider = btn.dataset.provider;
					var input    = document.getElementById('api-key-' + provider);
					fetch(btn.dataset.endpoint, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce':   btn.dataset.nonce
						},
						body: JSON.stringify({ provider: provider, api_key: input.value })
					})
					.then(function(r) { return r.json(); })
					.then(function(data) {
						input.value       = '';
						input.placeholder = data.saved ? '<?php echo esc_js( __( 'Saved', 'wp-ai-mind' ) ); ?>' : '<?php echo esc_js( __( 'Error', 'wp-ai-mind' ) ); ?>';
					});
				});
			});
			</script>
		</div>
		<?php
	}

	private static function get_masked_key( string $provider ): string {
		$encrypted = get_user_meta( get_current_user_id(), "wp_ai_mind_api_key_{$provider}", true );
		return $encrypted ? '••••••••••••••••••••' : '';
	}

	/**
	 * Return (or create) the stable 32-byte AES-256 encryption key stored in wp_options.
	 *
	 * Unlike deriving from AUTH_KEY, this key survives WordPress salt rotations.
	 * Rotating AUTH_KEY (e.g. via `wp config shuffle-salts`) would silently make all
	 * stored BYOK API keys unrecoverable; a stored key is immune to that.
	 *
	 * @since 1.2.0
	 * @return string|false 32-byte binary key, or false if random_bytes() is unavailable.
	 */
	private static function derive_key(): string|false {
		$stored = get_option( 'wp_ai_mind_enc_key' );
		if ( $stored ) {
			return base64_decode( $stored ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		try {
			$raw = random_bytes( 32 );
		} catch ( \Exception $e ) {
			return false;
		}
		update_option( 'wp_ai_mind_enc_key', base64_encode( $raw ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return $raw;
	}

	/**
	 * Encrypts a plaintext string using AES-256-CBC with a key derived from AUTH_KEY.
	 *
	 * Returns false when AUTH_KEY is not defined or OpenSSL encryption fails.
	 *
	 * @since 1.2.0
	 * @param string $plaintext The value to encrypt.
	 * @return string|false Base64-encoded ciphertext, or false on failure.
	 */
	public static function encrypt( string $plaintext ): string|false {
		$key = self::derive_key();
		if ( false === $key ) {
			return false;
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return false;
		}
		// IV is always 16 bytes — prepend directly so no delimiter is needed.
		return base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts an AES-256-CBC ciphertext previously produced by encrypt().
	 *
	 * Returns false when the key is unavailable, the ciphertext is malformed,
	 * or OpenSSL decryption fails.
	 *
	 * @since 1.2.0
	 * @param string $ciphertext Base64-encoded ciphertext from encrypt().
	 * @return string|false Decrypted plaintext, or false on failure.
	 */
	public static function decrypt( string $ciphertext ): string|false {
		$key = self::derive_key();
		if ( false === $key ) {
			return false;
		}
		$data = base64_decode( $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		// IV is always 16 bytes; slice by fixed offset rather than a delimiter that binary IV bytes could collide with.
		if ( false === $data || strlen( $data ) < 17 ) {
			return false;
		}
		$iv  = substr( $data, 0, 16 );
		$enc = substr( $data, 16 );
		return openssl_decrypt( $enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	}
}

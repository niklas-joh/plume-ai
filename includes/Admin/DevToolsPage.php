<?php
/**
 * Hidden developer tools page for testing subscription tiers and usage limits.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hidden admin page for switching tiers and manipulating usage counters during development.
 *
 * Activated by defining WP_AI_MIND_DEV_KEY in wp-config.php. The page never appears
 * in any admin menu — it is accessible only at the direct URL. On first access the key
 * is hashed with the site's auth salt and stored in wp_options, so changing the constant
 * to a different value invalidates access until the stored option is deleted.
 *
 * @since 1.11.0
 */
class DevToolsPage {

	/**
	 * WordPress option key that stores the HMAC of the accepted dev key.
	 *
	 * @since 1.11.0
	 */
	private const OPTION_KEY_HASH = 'wp_ai_mind_dev_key_hash';

	/**
	 * Admin page slug used in the URL: wp-admin/admin.php?page=wp-ai-mind-dev-tools
	 *
	 * @since 1.11.0
	 */
	public const PAGE_SLUG = 'wp-ai-mind-dev-tools';

	/**
	 * Register the admin_menu hook that adds the hidden page.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'add_page' ] );
	}

	/**
	 * Add the page with a null parent so it never appears in any menu.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function add_page(): void {
		add_submenu_page(
			null,
			__( 'Developer Tools — WP AI Mind', 'wp-ai-mind' ),
			__( 'Dev Tools', 'wp-ai-mind' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Verify that WP_AI_MIND_DEV_KEY is defined, non-empty, and matches the stored hash.
	 *
	 * On the very first call with a previously unseen key value the hash is stored and
	 * true is returned, locking in that value. A subsequent change to the constant will
	 * fail verification until the stored option is manually deleted via WP-CLI or the DB.
	 *
	 * @since 1.11.0
	 * @return bool True when the constant is valid and the current user has manage_options.
	 */
	public static function is_active(): bool {
		if ( ! defined( 'WP_AI_MIND_DEV_KEY' ) || '' === (string) WP_AI_MIND_DEV_KEY ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$hash   = self::hash_key( (string) WP_AI_MIND_DEV_KEY );
		$stored = (string) get_option( self::OPTION_KEY_HASH, '' );
		if ( '' === $stored ) {
			// First activation — store the hash and grant access.
			update_option( self::OPTION_KEY_HASH, $hash, false );
			return true;
		}
		return hash_equals( $stored, $hash );
	}

	/**
	 * Render the developer tools admin page.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function render(): void {
		if ( ! self::is_active() ) {
			wp_die( esc_html__( 'Developer tools are not enabled on this site.', 'wp-ai-mind' ), 403 );
		}

		$usage        = NJ_Usage_Tracker::get_usage();
		$tier_labels  = NJ_Tier_Config::get_tier_labels();
		$all_tiers    = NJ_Tier_Config::get_valid_tiers();
		$current_tier = $usage['tier'];
		$rest_url     = rest_url( 'wp-ai-mind/v1/dev/' );
		$nonce        = wp_create_nonce( 'wp_rest' );

		if ( null === $usage['limit'] ) {
			$usage_display = __( 'Unlimited', 'wp-ai-mind' );
		} else {
			$usage_display = number_format_i18n( $usage['used'] ) . ' / ' . number_format_i18n( $usage['limit'] ) . ' ' . __( 'tokens', 'wp-ai-mind' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Developer Tools', 'wp-ai-mind' ); ?></h1>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Development use only.', 'wp-ai-mind' ); ?></strong>
					<?php esc_html_e( 'Changes here affect only local WordPress state. The Cloudflare proxy enforces real quotas independently.', 'wp-ai-mind' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Current State', 'wp-ai-mind' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Tier', 'wp-ai-mind' ); ?></th>
					<td id="wpaim-dev-tier-label">
						<strong><?php echo esc_html( $tier_labels[ $current_tier ] ?? $current_tier ); ?></strong>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Usage this month', 'wp-ai-mind' ); ?></th>
					<td id="wpaim-dev-usage"><?php echo esc_html( $usage_display ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Can use', 'wp-ai-mind' ); ?></th>
					<td id="wpaim-dev-can-use"><?php echo $usage['can_use'] ? '&#10003; Yes' : '&#10007; No (limit reached)'; ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Switch Tier', 'wp-ai-mind' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wpaim-tier-select"><?php esc_html_e( 'Tier', 'wp-ai-mind' ); ?></label>
					</th>
					<td>
						<select id="wpaim-tier-select">
							<?php foreach ( $all_tiers as $slug ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $slug, $current_tier ); ?>>
									<?php echo esc_html( $tier_labels[ $slug ] ?? $slug ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button class="button button-primary" id="wpaim-apply-tier">
							<?php esc_html_e( 'Apply', 'wp-ai-mind' ); ?>
						</button>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Usage Controls', 'wp-ai-mind' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Reset', 'wp-ai-mind' ); ?></th>
					<td>
						<button class="button" id="wpaim-reset-usage">
							<?php esc_html_e( 'Reset to zero', 'wp-ai-mind' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( "Clears this month's token counter — simulates a fresh month.", 'wp-ai-mind' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exhaust', 'wp-ai-mind' ); ?></th>
					<td>
						<button class="button" id="wpaim-set-ceiling">
							<?php esc_html_e( 'Set to ceiling', 'wp-ai-mind' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( "Sets usage to the current tier's monthly limit to trigger the blocked state.", 'wp-ai-mind' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<div id="wpaim-dev-notice" style="display:none;" class="notice inline" aria-live="polite"></div>
		</div>

		<script>
		( function () {
			var restUrl = <?php echo wp_json_encode( $rest_url ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

			function post( endpoint, body ) {
				return fetch( restUrl + endpoint, {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body:    JSON.stringify( body ),
				} ).then( function ( r ) { return r.json(); } );
			}

			function get( endpoint ) {
				return fetch( restUrl + endpoint, {
					headers: { 'X-WP-Nonce': nonce },
				} ).then( function ( r ) { return r.json(); } );
			}

			function showNotice( msg, type ) {
				var el = document.getElementById( 'wpaim-dev-notice' );
				el.className  = 'notice notice-' + type + ' inline';
				el.textContent = msg;
				el.style.display = '';
			}

			function refreshState() {
				get( 'status' ).then( function ( data ) {
					if ( ! data.tier ) {
						return;
					}
					var strong = document.createElement( 'strong' );
					strong.textContent = data.tier_label;
					document.getElementById( 'wpaim-dev-tier-label' ).replaceChildren( strong );
					document.getElementById( 'wpaim-dev-usage' ).textContent    = data.usage_display;
					document.getElementById( 'wpaim-dev-can-use' ).textContent  = data.can_use ? '✓ Yes' : '✗ No (limit reached)';
					document.getElementById( 'wpaim-tier-select' ).value        = data.tier;
				} );
			}

			document.getElementById( 'wpaim-apply-tier' ).addEventListener( 'click', function () {
				var tier = document.getElementById( 'wpaim-tier-select' ).value;
				post( 'set-tier', { tier: tier } ).then( function ( data ) {
					showNotice( data.message || ( data.success ? 'Done.' : 'Error.' ), data.success ? 'success' : 'error' );
					if ( data.success ) {
						refreshState();
					}
				} );
			} );

			document.getElementById( 'wpaim-reset-usage' ).addEventListener( 'click', function () {
				post( 'reset-usage', {} ).then( function ( data ) {
					showNotice( data.message || ( data.success ? 'Done.' : 'Error.' ), data.success ? 'success' : 'error' );
					if ( data.success ) {
						refreshState();
					}
				} );
			} );

			document.getElementById( 'wpaim-set-ceiling' ).addEventListener( 'click', function () {
				post( 'set-ceiling', {} ).then( function ( data ) {
					showNotice( data.message || ( data.success ? 'Done.' : 'Error.' ), data.success ? 'success' : 'error' );
					if ( data.success ) {
						refreshState();
					}
				} );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Compute an HMAC of the key using the site's secure-auth salt.
	 *
	 * @since 1.11.0
	 * @param string $key Plaintext key value.
	 * @return string Hex HMAC-SHA256 digest.
	 */
	public static function hash_key( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'secure_auth' ) );
	}
}

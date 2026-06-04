<?php
/**
 * Hidden developer tools page for testing subscription tiers and usage limits.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Admin;

use Stilus\Tiers\TierConfig;
use Stilus\Tiers\UsageTracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hidden admin page for switching tiers and manipulating usage counters during development.
 *
 * Activated by defining STILUS_DEV_KEY in wp-config.php. The page never appears
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
	 * Not autoloaded — only needed on the DevTools admin page, not on every request.
	 *
	 * @since 1.11.0
	 */
	private const OPTION_KEY_HASH = 'stilus_dev_key_hash';

	/**
	 * Admin page slug used in the URL: wp-admin/admin.php?page=stilus-dev-tools
	 *
	 * @since 1.11.0
	 */
	public const PAGE_SLUG = 'stilus-dev-tools';

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
			__( 'Developer Tools — Stilus', 'stilus' ),
			__( 'Dev Tools', 'stilus' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * Verify that STILUS_DEV_KEY is defined, non-empty, and matches the stored hash.
	 *
	 * On the very first call with a previously unseen key value the hash is stored and
	 * true is returned, locking in that value. A subsequent change to the constant will
	 * fail verification until the stored option is manually deleted via WP-CLI or the DB.
	 *
	 * @since 1.11.0
	 * @return bool True when the constant is valid and the current user has manage_options.
	 */
	public static function is_active(): bool {
		if ( ! defined( 'STILUS_DEV_KEY' ) || '' === (string) STILUS_DEV_KEY ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$hash   = self::hash_key( (string) STILUS_DEV_KEY );
		$stored = (string) get_option( self::OPTION_KEY_HASH, '' );
		if ( '' === $stored ) {
			// First activation — store the hash and grant access.
			update_option( self::OPTION_KEY_HASH, $hash, false );
			return true;
		}
		return hash_equals( $stored, $hash );
	}

	/**
	 * Enqueue the compiled dev-tools script and pass runtime data via wp_localize_script.
	 *
	 * Called from render() so assets are registered at the correct time (after the page
	 * hook fires) without adding a separate admin_enqueue_scripts hook for a hidden page.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	private static function enqueue_assets(): void {
		$asset_file = STILUS_DIR . 'assets/admin/dev-tools.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => STILUS_VERSION,
			];

		wp_enqueue_script(
			'stilus-dev-tools',
			STILUS_URL . 'assets/admin/dev-tools.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'stilus-dev-tools',
			'njDevTools',
			[
				'restUrl' => esc_url_raw( rest_url( 'stilus/v1/dev/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			]
		);
	}

	/**
	 * Render the developer tools admin page.
	 *
	 * @since 1.11.0
	 * @return void
	 */
	public static function render(): void {
		if ( ! self::is_active() ) {
			wp_die(
				esc_html__( 'Developer tools are not enabled on this site.', 'stilus' ),
				'',
				[ 'response' => 403 ]
			);
		}

		self::enqueue_assets();

		$usage        = UsageTracker::get_usage();
		$tier_labels  = TierConfig::get_tier_labels();
		$all_tiers    = TierConfig::get_valid_tiers();
		$current_tier = $usage['tier'];

		if ( null === $usage['limit'] ) {
			$usage_display = __( 'Unlimited', 'stilus' );
		} else {
			$usage_display = number_format_i18n( $usage['used'] ) . ' / ' . number_format_i18n( $usage['limit'] ) . ' ' . __( 'tokens', 'stilus' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Developer Tools', 'stilus' ); ?></h1>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Development use only.', 'stilus' ); ?></strong>
					<?php esc_html_e( 'Changes here affect only local WordPress state. The Cloudflare proxy enforces real quotas independently.', 'stilus' ); ?>
				</p>
			</div>

			<h2><?php esc_html_e( 'Current State', 'stilus' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Tier', 'stilus' ); ?></th>
					<td id="stilus-dev-tier-label">
						<strong><?php echo esc_html( $tier_labels[ $current_tier ] ?? $current_tier ); ?></strong>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Usage this month', 'stilus' ); ?></th>
					<td id="stilus-dev-usage"><?php echo esc_html( $usage_display ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Can use', 'stilus' ); ?></th>
					<td id="stilus-dev-can-use"><?php echo $usage['can_use'] ? '&#10003; ' . esc_html__( 'Yes', 'stilus' ) : '&#10007; ' . esc_html__( 'No (limit reached)', 'stilus' ); ?></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Switch Tier', 'stilus' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="stilus-tier-select"><?php esc_html_e( 'Tier', 'stilus' ); ?></label>
					</th>
					<td>
						<select id="stilus-tier-select">
							<?php foreach ( $all_tiers as $slug ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $slug, $current_tier ); ?>>
									<?php echo esc_html( $tier_labels[ $slug ] ?? $slug ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button class="button button-primary" id="stilus-apply-tier">
							<?php esc_html_e( 'Apply', 'stilus' ); ?>
						</button>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Usage Controls', 'stilus' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Reset', 'stilus' ); ?></th>
					<td>
						<button class="button" id="stilus-reset-usage">
							<?php esc_html_e( 'Reset to zero', 'stilus' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( "Clears this month's token counter — simulates a fresh month.", 'stilus' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Exhaust', 'stilus' ); ?></th>
					<td>
						<button class="button" id="stilus-set-ceiling">
							<?php esc_html_e( 'Set to ceiling', 'stilus' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( "Sets usage to the current tier's monthly limit to trigger the blocked state.", 'stilus' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<div id="stilus-dev-notice" style="display:none;" class="notice inline" aria-live="polite"></div>
		</div>
		<?php
	}

	/**
	 * Compute an HMAC of the key using the site's secure-auth salt.
	 *
	 * @since 1.11.0
	 * @param string $key Plaintext key value.
	 * @return string Hex HMAC-SHA256 digest.
	 */
	private static function hash_key( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'secure_auth' ) );
	}
}

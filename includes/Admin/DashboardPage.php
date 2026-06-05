<?php
/**
 * Admin page rendering the Stilus dashboard.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Settings\ProviderSettings;
use Stilus\Tiers\TierManager;
use Stilus\Tiers\UsageTracker;

/**
 * Renders the Stilus dashboard admin page.
 *
 * Also handles the "Run setup again" GET action, which clears the
 * onboarding-seen flag and redirects back to the dashboard root.
 *
 * @since 1.0.0
 */
class DashboardPage {

	/**
	 * Handle the optional run-setup action, then output the page markup and enqueue assets.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		// Handle "Run setup again" — nonce-protected GET action.
		if (
			isset( $_GET['run_setup'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'stilus_run_setup' ) &&
			current_user_can( 'manage_options' )
		) {
			delete_option( 'stilus_onboarding_seen' );
			wp_safe_redirect( admin_url( 'admin.php?page=stilus' ) );
			exit;
		}

		self::enqueue_assets();
		echo '<div id="stilus-dashboard" class="stilus-page"></div>';
	}

	/**
	 * Enqueue the admin script and stylesheet, and localise dashboard data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function enqueue_assets(): void {
		$asset_file = STILUS_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => STILUS_VERSION,
			];

		wp_enqueue_script(
			'stilus-admin',
			STILUS_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'stilus-admin',
			STILUS_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'stilus-admin',
			'stilusDashboard',
			self::get_dashboard_data()
		);
	}

	/**
	 * Assemble the data object passed to the dashboard React app.
	 *
	 * Determines the upgrade banner state: suppressed for Pro/BYOK users and
	 * trial users (who already have access and receive a separate expiry notice).
	 *
	 * @since 1.0.0
	 * @return array<string, mixed> Associative array of localised dashboard data.
	 */
	private static function get_dashboard_data(): array {
		$provider_settings = new ProviderSettings();
		$provider          = (string) get_option( 'stilus_default_provider', '' );
		$has_own_key       = $provider && $provider_settings->has_key( $provider );
		$is_pro            = TierManager::user_can( 'generator' );

		// Suppress the upgrade banner when the user has generator access (pro_managed, pro_byok, or
		// trial tiers all have generator = true) or when they supply their own API key. Trial users
		// intentionally do not see the banner — they already have access and will receive a separate
		// trial-expiry notice when their quota runs low.
		if ( $is_pro || $has_own_key ) {
			$banner_state = 'none';
		} else {
			$banner_state = 'free_tier';
		}

		return [
			'bannerState'    => $banner_state,
			'onboardingSeen' => (bool) get_option( 'stilus_onboarding_seen', false ),
			'isPro'          => $is_pro,
			'usage'          => current_user_can( 'manage_options' ) ? UsageTracker::get_usage() : null,
			'version'        => STILUS_VERSION,
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'restUrl'        => esc_url_raw( rest_url( 'stilus/v1' ) ),
			'runSetupUrl'    => wp_nonce_url(
				admin_url( 'admin.php?page=stilus&run_setup=1' ),
				'stilus_run_setup'
			),
			'urls'           => [
				'chat'      => admin_url( 'admin.php?page=stilus-chat' ),
				'generator' => admin_url( 'admin.php?page=stilus-generator' ),
				'images'    => admin_url( 'admin.php?page=stilus-images' ),
				'seo'       => admin_url( 'admin.php?page=stilus-seo' ),
				'usage'     => admin_url( 'admin.php?page=stilus-usage' ),
				'settings'  => admin_url( 'admin.php?page=stilus-settings' ),
				'posts'     => admin_url( 'edit.php' ),
				'upgrade'   => 'https://wpaimind.com/pricing', // TODO: update to canonical Stilus domain once finalised.
			],
			'resourceUrls'   => [
				'gettingStarted' => 'https://wpaimind.com/docs/getting-started', // TODO: update to canonical Stilus domain once finalised.
				'promptTips'     => 'https://wpaimind.com/docs/prompt-tips', // TODO: update to canonical Stilus domain once finalised.
				'apiKeySetup'    => 'https://wpaimind.com/docs/api-key-setup', // TODO: update to canonical Stilus domain once finalised.
				'changelog'      => 'https://wpaimind.com/changelog', // TODO: update to canonical Stilus domain once finalised.
			],
		];
	}
}

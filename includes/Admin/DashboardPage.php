<?php
/**
 * Admin page rendering the Plume dashboard.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Settings\ProviderSettings;
use Plume\Tiers\TierManager;
use Plume\Tiers\UsageTracker;

/**
 * Renders the Plume dashboard admin page.
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
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'plume_run_setup' ) &&
			current_user_can( 'manage_options' )
		) {
			delete_option( 'plume_onboarding_seen' );
			wp_safe_redirect( admin_url( 'admin.php?page=plume' ) );
			exit;
		}

		self::enqueue_assets();
		echo '<div id="plume-dashboard" class="plume-page"></div>';
	}

	/**
	 * Enqueue the admin script and stylesheet, and localise dashboard data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function enqueue_assets(): void {
		$asset_file = PLUME_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => PLUME_VERSION,
			];

		wp_enqueue_script(
			'plume-admin',
			PLUME_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'plume-admin',
			PLUME_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'plume-admin',
			'plumeDashboard',
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
		$provider          = (string) get_option( 'plume_default_provider', '' );
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
			'onboardingSeen' => (bool) get_option( 'plume_onboarding_seen', false ),
			'isPro'          => $is_pro,
			'usage'          => current_user_can( 'manage_options' ) ? UsageTracker::get_usage() : null,
			'version'        => PLUME_VERSION,
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'restUrl'        => esc_url_raw( rest_url( 'plume/v1' ) ),
			'runSetupUrl'    => wp_nonce_url(
				admin_url( 'admin.php?page=plume&run_setup=1' ),
				'plume_run_setup'
			),
			'urls'           => [
				'chat'      => admin_url( 'admin.php?page=plume-chat' ),
				'generator' => admin_url( 'admin.php?page=plume-generator' ),
				'images'    => admin_url( 'admin.php?page=plume-images' ),
				'seo'       => admin_url( 'admin.php?page=plume-seo' ),
				'usage'     => admin_url( 'admin.php?page=plume-usage' ),
				'settings'  => admin_url( 'admin.php?page=plume-settings' ),
				'posts'     => admin_url( 'edit.php' ),
				'upgrade'   => PLUME_WEBSITE_URL . '/pricing',
				'docs'      => PLUME_WEBSITE_URL . '/docs',
				'support'   => PLUME_WEBSITE_URL . '/support',
			],
			'resourceUrls'   => [
				'gettingStarted' => PLUME_WEBSITE_URL . '/docs/getting-started',
				'promptTips'     => PLUME_WEBSITE_URL . '/docs/prompt-tips',
				'apiKeySetup'    => PLUME_WEBSITE_URL . '/docs/api-key-setup',
				'changelog'      => PLUME_WEBSITE_URL . '/changelog',
			],
		];
	}
}

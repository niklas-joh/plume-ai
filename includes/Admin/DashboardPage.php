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
	 * Determines the upgrade banner state: free-tier users see a low-credits
	 * nudge once their usage crosses 80% of their monthly limit; paid tiers
	 * (pro_managed, pro_byok) never see the banner regardless of usage.
	 *
	 * @since 1.0.0
	 * @since 1.11.0 Removed the ProviderSettings/own-API-key lookup — that
	 *                      signal no longer suppresses the banner on its own;
	 *                      replaced isPro with isPaid and the free_tier banner
	 *                      state with free_tier_low_credits (usage-threshold-gated).
	 * @return array<string, mixed> Associative array of localised dashboard data.
	 */
	private static function get_dashboard_data(): array {
		$is_paid = TierManager::is_paid();
		$usage   = UsageTracker::get_usage();

		$banner_state = 'none';
		if ( ! $is_paid && null !== $usage['limit'] && $usage['limit'] > 0 ) {
			$pct = ( $usage['used'] / $usage['limit'] ) * 100;
			if ( $pct > 80 ) {
				$banner_state = 'free_tier_low_credits';
			}
		}

		return [
			'bannerState'    => $banner_state,
			'onboardingSeen' => (bool) get_option( 'plume_onboarding_seen', false ),
			'isPaid'         => $is_paid,
			'usage'          => current_user_can( 'manage_options' ) ? $usage : null,
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

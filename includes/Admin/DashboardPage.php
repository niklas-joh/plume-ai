<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Settings\ProviderSettings;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class DashboardPage {

	public static function render(): void {
		// Handle "Run setup again" — nonce-protected GET action.
		if (
			isset( $_GET['run_setup'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpaim_run_setup' ) &&
			current_user_can( 'manage_options' )
		) {
			delete_option( 'wp_ai_mind_onboarding_seen' );
			wp_safe_redirect( admin_url( 'admin.php?page=wp-ai-mind' ) );
			exit;
		}

		self::enqueue_assets();
		echo '<div id="wp-ai-mind-dashboard" class="wp-ai-mind-page"></div>';
	}

	private static function enqueue_assets(): void {
		$asset_file = WP_AI_MIND_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		wp_enqueue_script(
			'wp-ai-mind-admin',
			WP_AI_MIND_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'wp-ai-mind-admin',
			WP_AI_MIND_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'wp-ai-mind-admin',
			'wpAiMindDashboard',
			self::get_dashboard_data()
		);
	}

	private static function get_dashboard_data(): array {
		$provider_settings = new ProviderSettings();
		$provider          = (string) get_option( 'wp_ai_mind_default_provider', '' );
		$has_own_key       = $provider && $provider_settings->has_key( $provider );
		$is_pro            = NJ_Tier_Manager::user_can( 'generator' );

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
			'onboardingSeen' => (bool) get_option( 'wp_ai_mind_onboarding_seen', false ),
			'isPro'          => $is_pro,
			'version'        => WP_AI_MIND_VERSION,
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'restUrl'        => esc_url_raw( rest_url( 'wp-ai-mind/v1' ) ),
			'runSetupUrl'    => wp_nonce_url(
				admin_url( 'admin.php?page=wp-ai-mind&run_setup=1' ),
				'wpaim_run_setup'
			),
			'urls'           => [
				'chat'      => admin_url( 'admin.php?page=wp-ai-mind-chat' ),
				'generator' => admin_url( 'admin.php?page=wp-ai-mind-generator' ),
				'images'    => admin_url( 'admin.php?page=wp-ai-mind-images' ),
				'seo'       => admin_url( 'admin.php?page=wp-ai-mind-seo' ),
				'usage'     => admin_url( 'admin.php?page=wp-ai-mind-usage' ),
				'settings'  => admin_url( 'admin.php?page=wp-ai-mind-settings' ),
				'posts'     => admin_url( 'edit.php' ),
				'upgrade'   => 'https://wpaimind.com/pricing',
			],
			'resourceUrls'   => [
				'gettingStarted' => 'https://wpaimind.com/docs/getting-started',
				'promptTips'     => 'https://wpaimind.com/docs/prompt-tips',
				'apiKeySetup'    => 'https://wpaimind.com/docs/api-key-setup',
				'changelog'      => 'https://wpaimind.com/changelog',
			],
		];
	}
}

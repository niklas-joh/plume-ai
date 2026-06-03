<?php
/**
 * Usage module — REST routes and asset enqueuing for the usage dashboard.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Modules\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\Tiers\TierManager;
use Stilus\Tiers\UsageTracker;

/**
 * Registers the Usage admin page assets and REST endpoint.
 */
class UsageModule {

	/**
	 * Register WordPress hooks for this module.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	/**
	 * Enqueue usage-module assets on the usage admin page only.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix (unused; page detection uses $_GET).
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by admin_enqueue_scripts hook signature.
		if ( ! isset( $_GET['page'] ) || 'stilus-usage' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$asset_file = STILUS_DIR . 'assets/usage/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => STILUS_VERSION,
			];

		\wp_enqueue_script(
			'stilus-usage',
			STILUS_URL . 'assets/usage/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'stilus-usage',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'stilus/v1' ) ),
				'currentPostId' => 0,
				'isPro'         => TierManager::user_can( 'generator' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		\wp_enqueue_style(
			'stilus-usage',
			STILUS_URL . 'assets/usage/index.css',
			[],
			$asset['version']
		);
	}

	/**
	 * Register the /stilus/v1/usage REST route.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register_routes(): void {
		\register_rest_route(
			'stilus/v1',
			'/usage',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_usage' ],
				'permission_callback' => fn() => \current_user_can( 'manage_options' ),
			]
		);
	}

	/**
	 * Return the current user's token usage summary.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request $request Incoming REST request (unused).
	 * @return \WP_REST_Response
	 */
	public static function get_usage( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_REST_Server callback signature.
		$usage = UsageTracker::get_usage();

		return new \WP_REST_Response(
			[
				'tier'      => $usage['tier'],
				'used'      => $usage['used'],
				'limit'     => $usage['limit'],
				'remaining' => $usage['remaining'],
				'can_use'   => $usage['can_use'],
			]
		);
	}
}

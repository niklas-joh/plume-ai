<?php
// includes/Modules/Usage/UsageModule.php
declare( strict_types=1 );

namespace WP_AI_Mind\Modules\Usage;

use WP_AI_Mind\DB\Schema;

/**
 * Registers the Usage admin page assets and REST endpoint.
 */
class UsageModule {

	public static function register(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
	}

	public static function enqueue_assets( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by admin_enqueue_scripts hook signature.
		if ( ! isset( $_GET['page'] ) || 'wp-ai-mind-usage' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$asset_file = WP_AI_MIND_DIR . 'assets/usage/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		\wp_enqueue_script(
			'wp-ai-mind-usage',
			WP_AI_MIND_URL . 'assets/usage/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-api-fetch', 'wp-i18n' ] ),
			$asset['version'],
			true
		);

		\wp_localize_script(
			'wp-ai-mind-usage',
			'wpAiMindData',
			[
				'nonce'         => \wp_create_nonce( 'wp_rest' ),
				'restUrl'       => \esc_url_raw( \rest_url( 'wp-ai-mind/v1' ) ),
				'currentPostId' => 0,
				'isPro'         => \nj_can_user( 'chat' ),
				'siteTitle'     => \get_bloginfo( 'name' ),
			]
		);

		\wp_enqueue_style(
			'wp-ai-mind-usage',
			WP_AI_MIND_URL . 'assets/usage/index.css',
			[],
			$asset['version']
		);
	}

	public static function register_routes(): void {
		\register_rest_route(
			'wp-ai-mind/v1',
			'/usage',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'get_usage' ],
				'permission_callback' => fn() => \current_user_can( 'manage_options' ),
			]
		);
	}

	public static function get_usage( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_REST_Server callback signature.
		global $wpdb;

		$table = Schema::table( 'usage_log' );

		// Total tokens + cost per provider (last 30 days).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider, feature,
			        SUM(tokens_used)  AS tokens,
			        SUM(cost_usd)     AS cost,
			        COUNT(*)          AS requests
			 FROM   {$table}
			 WHERE  created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP  BY provider, feature
			 ORDER  BY cost DESC",
				30
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Daily totals for the sparkline (last 30 days).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day,
			        SUM(tokens_used) AS tokens,
			        SUM(cost_usd)    AS cost
			 FROM   {$table}
			 WHERE  created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP  BY DATE(created_at)
			 ORDER  BY day ASC",
				30
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows_data    = ! empty( $rows ) ? $rows : [];
		$daily_data   = ! empty( $daily ) ? $daily : [];
		$total_tokens = array_sum( array_column( $rows_data, 'tokens' ) );
		$total_cost   = array_sum( array_column( $rows_data, 'cost' ) );

		return new \WP_REST_Response(
			[
				'breakdown'   => $rows_data,
				'daily'       => $daily_data,
				'totals'      => [
					'tokens'   => (int) $total_tokens,
					'cost_usd' => round( (float) $total_cost, 4 ),
					'requests' => array_sum( array_column( $rows_data, 'requests' ) ),
				],
				'period_days' => 30,
			]
		);
	}
}

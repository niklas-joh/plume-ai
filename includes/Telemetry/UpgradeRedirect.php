<?php
/**
 * Redirect that records an upgrade click before sending the admin to checkout.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Proxy\SiteRegistration;

/**
 * Handles admin-post.php?action=plume_upgrade&plan=monthly|annual.
 *
 * PHP-rendered checkout links go through this handler so the click can be recorded
 * (only queued, never sent here). The checkout URL carries the site token, so the
 * handler requires manage_options, and the plan must be one of PLANS.
 *
 * @since NEXT_VERSION
 */
final class UpgradeRedirect {

	/**
	 * Admin-post action and nonce action.
	 *
	 * @since NEXT_VERSION
	 */
	public const ACTION = 'plume_upgrade';

	/**
	 * Plans with a checkout URL.
	 *
	 * @since NEXT_VERSION
	 * @var string[]
	 */
	public const PLANS = [ 'monthly', 'annual' ];

	/**
	 * Hook the admin-post handler.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
	}

	/**
	 * Nonced admin-post URL for a plan.
	 *
	 * @since NEXT_VERSION
	 * @param string $plan One of PLANS.
	 * @return string Unescaped URL; escape with esc_url() when printing.
	 */
	public static function url( string $plan ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&plan=' . rawurlencode( $plan ) ), self::ACTION );
	}

	/**
	 * Verify the request, record the click and redirect to checkout.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'plume' ), '', [ 'response' => 403 ] );
		}

		// Nonce verified above by check_admin_referer().
		$plan = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $plan, self::PLANS, true ) ) {
			wp_die( esc_html__( 'Unknown plan.', 'plume' ), '', [ 'response' => 400 ] );
		}

		Telemetry::record(
			'upgrade_click',
			[
				'placement' => 'upgrade_page',
				'screen'    => 'upgrade',
				'plan'      => $plan,
			]
		);

		add_filter( 'allowed_redirect_hosts', [ self::class, 'allow_checkout_host' ] );
		$target = 'monthly' === $plan
			? SiteRegistration::checkout_url_pro_managed_monthly()
			: SiteRegistration::checkout_url_pro_managed_annual();
		wp_safe_redirect( $target );
		// exit still runs the shutdown hooks, so Telemetry::persist() queues the click.
		exit;
	}

	/**
	 * Add the checkout host to the hosts wp_safe_redirect() accepts.
	 *
	 * @since NEXT_VERSION
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public static function allow_checkout_host( array $hosts ): array {
		$hosts[] = SiteRegistration::CHECKOUT_HOST;
		return $hosts;
	}
}

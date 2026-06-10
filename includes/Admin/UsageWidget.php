<?php
/**
 * WordPress admin dashboard widget showing current-user token usage.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Admin;

use Plume\Tiers\TierConfig;
use Plume\Tiers\UsageTracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard widget showing the current user's tier and token usage.
 *
 * @since 1.2.0
 */
class UsageWidget {

	/**
	 * Register WordPress hooks for this widget.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'add_dashboard_widget' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue the widget stylesheet on the dashboard only.
	 *
	 * @since 1.2.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_styles( string $hook ): void {
		if ( 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'plume-admin-widgets',
			PLUME_URL . 'assets/admin/plume-admin-widgets.css',
			[],
			PLUME_VERSION
		);
	}

	/**
	 * Register the Plume Usage dashboard widget.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function add_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'plume_usage',
			__( 'Plume Usage', 'plume' ),
			[ self::class, 'render' ]
		);
	}

	/**
	 * Render the widget HTML showing tier label, progress bar, and token counts.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function render(): void {
		$user_id = get_current_user_id();
		$usage   = UsageTracker::get_usage( $user_id );
		$tier    = $usage['tier'];

		$tier_labels = TierConfig::get_tier_labels();
		$tier_label  = $tier_labels[ $tier ] ?? ucwords( str_replace( '_', ' ', $tier ) );

		echo '<div class="plume-usage-widget">';
		echo '<p><strong>' . esc_html( $tier_label ) . ' ' . esc_html__( 'Plan', 'plume' ) . '</strong></p>';

		if ( null !== $usage['limit'] && $usage['limit'] > 0 ) {
			$pct = min( 100, (int) round( ( $usage['used'] / $usage['limit'] ) * 100 ) );

			if ( $pct > 80 ) {
				$bar_modifier = 'danger';
			} elseif ( $pct > 60 ) {
				$bar_modifier = 'warning';
			} else {
				$bar_modifier = 'success';
			}

			printf(
				'<div class="plume-progress-track"><div class="plume-progress-bar plume-progress-bar--%s" style="width:%d%%"></div></div>',
				esc_attr( $bar_modifier ),
				absint( $pct )
			);
			printf(
				'<p class="plume-meta-text">%s / %s %s (%s %s)</p>',
				esc_html( number_format_i18n( (int) $usage['used'] ) ),
				esc_html( number_format_i18n( (int) $usage['limit'] ) ),
				esc_html__( 'tokens', 'plume' ),
				esc_html( number_format_i18n( (int) $usage['remaining'] ) ),
				esc_html__( 'remaining', 'plume' )
			);
			if ( $pct > 80 ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Over 80% of monthly tokens used. Consider upgrading.', 'plume' ) . '</p></div>';
			}
		} else {
			echo '<p>' . esc_html__( 'Unlimited — using your own API key.', 'plume' ) . '</p>';
		}

		echo '</div>';
	}
}

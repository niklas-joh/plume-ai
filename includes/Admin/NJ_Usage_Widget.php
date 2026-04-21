<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NJ_Usage_Widget {

	public static function register_hooks(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'add_dashboard_widget' ] );
	}

	public static function add_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'wp_ai_mind_usage',
			__( 'AI Mind Usage', 'wp-ai-mind' ),
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		$user_id = get_current_user_id();
		$usage   = NJ_Usage_Tracker::get_usage( $user_id );
		$tier    = NJ_Tier_Manager::get_user_tier( $user_id );

		$tier_labels = [
			'free'        => __( 'Free', 'wp-ai-mind' ),
			'trial'       => __( 'Trial', 'wp-ai-mind' ),
			'pro_managed' => __( 'Pro Managed', 'wp-ai-mind' ),
			'pro_byok'    => __( 'Pro BYOK', 'wp-ai-mind' ),
		];
		$tier_label  = $tier_labels[ $tier ] ?? ucwords( str_replace( '_', ' ', $tier ) );

		echo '<div class="wp-ai-mind-usage-widget">';
		echo '<p><strong>' . esc_html( $tier_label ) . ' ' . esc_html__( 'Plan', 'wp-ai-mind' ) . '</strong></p>';

		if ( null !== $usage['limit'] ) {
			$color_danger   = '#d63638';
			$color_warning  = '#dba617';
			$color_success  = '#00a32a';
			$color_track_bg = '#e0e0e0';
			$color_label    = '#666';

			$pct   = min( 100, (int) round( ( $usage['used'] / $usage['limit'] ) * 100 ) );
			$color = $pct > 80 ? $color_danger : ( $pct > 60 ? $color_warning : $color_success );
			printf(
				'<div style="background:%s;height:10px;border-radius:5px;margin:8px 0"><div style="width:%d%%;background:%s;height:100%%;border-radius:5px"></div></div>',
				esc_attr( $color_track_bg ),
				absint( $pct ),
				esc_attr( $color )
			);
			printf(
				'<p style="font-size:12px;color:%s">%s / %s %s (%s %s)</p>',
				esc_attr( $color_label ),
				esc_html( number_format_i18n( (int) $usage['used'] ) ),
				esc_html( number_format_i18n( (int) $usage['limit'] ) ),
				esc_html__( 'tokens', 'wp-ai-mind' ),
				esc_html( number_format_i18n( (int) $usage['remaining'] ) ),
				esc_html__( 'remaining', 'wp-ai-mind' )
			);
			if ( $pct > 80 ) {
				echo '<p class="notice notice-warning inline">' . esc_html__( 'Over 80% of monthly tokens used. Consider upgrading.', 'wp-ai-mind' ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'Unlimited — using your own API key.', 'wp-ai-mind' ) . '</p>';
		}

		echo '</div>';
	}
}

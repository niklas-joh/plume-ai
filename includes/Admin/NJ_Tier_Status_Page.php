<?php
/**
 * Admin page displaying the current user's licence tier and usage status.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Proxy\NJ_Site_Registration;
use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page displaying the site's current plan and monthly token usage.
 *
 * @since 1.2.0
 */
class NJ_Tier_Status_Page {

	private const STYLE_HOOKS = [
		'settings_page_wp-ai-mind-tier-status',
		// The upgrade-page hook is registered by NJ_Upgrade_Page, not this class; included so the
		// shared admin stylesheet loads on both pages without duplicating enqueue logic.
		'ai-mind_page_wp-ai-mind-upgrade',
	];

	/**
	 * Register WordPress hooks for the tier status page.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue the admin stylesheet on the tier status and upgrade pages only.
	 *
	 * @since 1.2.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_styles( string $hook ): void {
		if ( ! in_array( $hook, self::STYLE_HOOKS, true ) ) {
			return;
		}
		wp_enqueue_style(
			'wpaim-admin-widgets',
			WP_AI_MIND_URL . 'assets/admin/wpaim-admin-widgets.css',
			[],
			WP_AI_MIND_VERSION
		);
	}

	/**
	 * Register the Plan & Usage submenu page under Settings.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function add_menu_page(): void {
		add_options_page(
			__( 'WP AI Mind — Plan & Usage', 'wp-ai-mind' ),
			__( 'AI Mind Plan', 'wp-ai-mind' ),
			'manage_options',
			'wp-ai-mind-tier-status',
			[ self::class, 'render' ]
		);
	}

	/**
	 * Render the Plan & Usage page for administrators.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tier  = NJ_Tier_Manager::get_user_tier();
		$usage = NJ_Usage_Tracker::get_usage();

		$tier_labels = NJ_Tier_Config::get_tier_labels();
		$tier_label  = $tier_labels[ $tier ] ?? ucwords( str_replace( '_', ' ', $tier ) );
		$registered  = NJ_Site_Registration::is_registered();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Current plan', 'wp-ai-mind' ); ?></th>
					<td><strong><?php echo esc_html( $tier_label ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Proxy connection', 'wp-ai-mind' ); ?></th>
					<td>
						<?php if ( $registered ) : ?>
							<span class="wpaim-status--active">&#10003; <?php esc_html_e( 'Connected', 'wp-ai-mind' ); ?></span>
						<?php else : ?>
							<span class="wpaim-status--expired"><?php esc_html_e( 'Not connected — will auto-connect on next page load', 'wp-ai-mind' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( null !== $usage['limit'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tokens used this month', 'wp-ai-mind' ); ?></th>
					<td>
						<?php
						echo esc_html(
							number_format_i18n( $usage['used'] ) . ' / ' . number_format_i18n( $usage['limit'] )
						);
						?>
						<br>
						<progress
							class="wpaim-usage-meter"
							max="<?php echo esc_attr( (string) $usage['limit'] ); ?>"
							value="<?php echo esc_attr( (string) $usage['used'] ); ?>">
						</progress>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tokens remaining', 'wp-ai-mind' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $usage['remaining'] ?? 0 ) ); ?></td>
				</tr>
				<?php else : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Token usage', 'wp-ai-mind' ); ?></th>
					<td><?php esc_html_e( 'Unlimited (your API key, your cost)', 'wp-ai-mind' ); ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( 'free' === $tier || 'trial' === $tier ) : ?>
			<div class="card wpaim-upgrade-card">
				<h2><?php esc_html_e( 'Upgrade your plan', 'wp-ai-mind' ); ?></h2>
				<p><?php esc_html_e( 'Pro Managed gives you 2M tokens/month with model selection. Pro BYOK gives you unlimited usage with your own API key.', 'wp-ai-mind' ); ?></p>
				<div class="wpaim-upgrade-actions">
					<a href="<?php echo esc_url( NJ_Site_Registration::checkout_url_pro_managed_monthly() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Pro Managed — Monthly', 'wp-ai-mind' ); ?>
					</a>
					<a href="<?php echo esc_url( NJ_Site_Registration::checkout_url_pro_managed_annual() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Pro Managed — Annual', 'wp-ai-mind' ); ?>
					</a>
					<a href="<?php echo esc_url( NJ_Site_Registration::checkout_url_pro_byok_onetime() ); ?>" class="button">
						<?php esc_html_e( 'Pro BYOK — One-time', 'wp-ai-mind' ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>
			<?php if ( 'pro_byok' === $tier ) : ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=wp-ai-mind-api-keys' ) ); ?>">
					<?php esc_html_e( 'Manage your API keys →', 'wp-ai-mind' ); ?>
				</a>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}
}

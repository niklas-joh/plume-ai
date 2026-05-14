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
 * Registered as the render callback for the "Upgrade" submenu page under the
 * plugin's own admin menu. Not added to the Settings menu.
 *
 * @since 1.2.0
 */
class TierStatusPage {

	private const STYLE_HOOKS = [
		'ai-mind_page_wp-ai-mind-upgrade',
	];

	/**
	 * Register WordPress hooks for the tier status page.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue the admin stylesheet on the upgrade page only.
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
					<th scope="row"><?php esc_html_e( 'Service connection', 'wp-ai-mind' ); ?></th>
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-mind-settings' ) ); ?>">
					<?php esc_html_e( 'Manage your API keys →', 'wp-ai-mind' ); ?>
				</a>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}
}

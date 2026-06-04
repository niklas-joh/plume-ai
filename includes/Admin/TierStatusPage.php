<?php
/**
 * Admin page displaying the current user's licence tier and usage status.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Admin;

use Stilus\Proxy\SiteRegistration;
use Stilus\Tiers\TierConfig;
use Stilus\Tiers\TierManager;
use Stilus\Tiers\UsageTracker;

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
		'ai-mind_page_stilus-upgrade',
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
			'stilus-admin-widgets',
			STILUS_URL . 'assets/admin/stilus-admin-widgets.css',
			[],
			STILUS_VERSION
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
		$tier  = TierManager::get_user_tier();
		$usage = UsageTracker::get_usage();

		$tier_labels = TierConfig::get_tier_labels();
		$tier_label  = $tier_labels[ $tier ] ?? ucwords( str_replace( '_', ' ', $tier ) );
		$registered  = SiteRegistration::is_registered();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Current plan', 'stilus' ); ?></th>
					<td><strong><?php echo esc_html( $tier_label ); ?></strong></td>
				</tr>
				<tr>
				<th scope="row"><?php esc_html_e( 'Service connection', 'stilus' ); ?></th>
					<td>
						<?php if ( $registered ) : ?>
							<span class="wpaim-status--active">&#10003; <?php esc_html_e( 'Connected', 'stilus' ); ?></span>
						<?php else : ?>
							<span class="wpaim-status--expired"><?php esc_html_e( 'Not connected — will auto-connect on next page load', 'stilus' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( null !== $usage['limit'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tokens used this month', 'stilus' ); ?></th>
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
					<th scope="row"><?php esc_html_e( 'Tokens remaining', 'stilus' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $usage['remaining'] ?? 0 ) ); ?></td>
				</tr>
				<?php else : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Token usage', 'stilus' ); ?></th>
					<td><?php esc_html_e( 'Unlimited (your API key, your cost)', 'stilus' ); ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( 'free' === $tier || 'trial' === $tier ) : ?>
			<div class="card wpaim-upgrade-card">
				<h2><?php esc_html_e( 'Upgrade your plan', 'stilus' ); ?></h2>
				<p><?php esc_html_e( 'Pro Managed gives you 2M tokens/month with model selection. Pro BYOK gives you unlimited usage with your own API key.', 'stilus' ); ?></p>
				<div class="wpaim-upgrade-actions">
					<a href="<?php echo esc_url( SiteRegistration::checkout_url_pro_managed_monthly() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Pro Managed — Monthly', 'stilus' ); ?>
					</a>
					<a href="<?php echo esc_url( SiteRegistration::checkout_url_pro_managed_annual() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Pro Managed — Annual', 'stilus' ); ?>
					</a>
					<a href="<?php echo esc_url( SiteRegistration::checkout_url_pro_byok_onetime() ); ?>" class="button">
						<?php esc_html_e( 'Pro BYOK — One-time', 'stilus' ); ?>
					</a>
				</div>
			</div>
			<?php endif; ?>
			<?php if ( 'pro_byok' === $tier ) : ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=stilus-settings' ) ); ?>">
					<?php esc_html_e( 'Manage your API keys →', 'stilus' ); ?>
				</a>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}
}

<?php
/**
 * Admin notice that prompts already-registered sites to fetch a tier-sync secret.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

use Plume\Proxy\SiteRegistration;
use Plume\Tiers\TierManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill notice for sites that registered with the service before the
 * tier-sync handshake existed.
 *
 * Such installs hold a valid `plume_site_token` but no
 * `plume_tier_sync_secret`, so the Worker cannot push tier updates to
 * them. The notice exposes a one-click "Re-register" action that calls
 * SiteRegistration::rotate_secret() to populate the missing secret.
 *
 * Hooks are intentionally limited to users with `manage_options` so the
 * action and its nonce never leak to lower-privileged roles.
 *
 * @since 1.9.0
 */
class TierSyncBackfillNotice {

	/**
	 * Admin-post action slug used both for the form submission and the
	 * `admin_post_{action}` hook name.
	 *
	 * @since 1.9.0
	 */
	private const ACTION = 'plume_rotate_secret';

	/**
	 * Nonce action name. Distinct from ACTION to keep nonce verification
	 * explicit at the call site.
	 *
	 * @since 1.9.0
	 */
	private const NONCE = 'plume_rotate_secret_nonce';

	/**
	 * Transient key prefix used to relay a failed-rotation error message from
	 * the admin-post handler to the next admin page render. Suffixed with the
	 * user ID so concurrent admins on the same site don't see each other's
	 * outcomes.
	 *
	 * @since 1.9.0
	 */
	private const ERROR_TRANSIENT_PREFIX = 'plume_rotate_err_';

	/**
	 * Register WordPress hooks for the notice and its admin-post handler.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'admin_notices', [ self::class, 'maybe_display' ] );
		\add_action( 'admin_notices', [ self::class, 'maybe_display_sig_mismatch' ] );
		\add_action( 'admin_notices', [ self::class, 'maybe_display_result' ] );
		\add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_rotate' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue the minimal admin styles required by the backfill notice.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function enqueue_styles(): void {
		\wp_add_inline_style( 'common', '.nj-backfill-form { display: inline; }' );
	}

	/**
	 * Returns true when the current user may see a tier notice.
	 *
	 * Shared preamble for maybe_display() and maybe_display_sig_mismatch() so the
	 * three-guard sequence (capability, registration, secret-presence) stays in sync.
	 *
	 * @since 1.10.0
	 * @return bool True when the common preconditions are met.
	 */
	private static function can_show_tier_notice(): bool {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return false;
		}
		return SiteRegistration::is_registered();
	}

	/**
	 * Render the backfill prompt when registration is complete but no
	 * tier-sync secret has been issued yet.
	 *
	 * Capability check guards both the read of `is_registered()` and the
	 * render of the form so non-admin viewers never see the button.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function maybe_display(): void {
		if ( ! self::can_show_tier_notice() ) {
			return;
		}
		if ( '' !== (string) \get_option( SiteRegistration::OPTION_SECRET, '' ) ) {
			return;
		}

		$action_url = \admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
			<strong><?php \esc_html_e( 'Plume AI - Write and Design — Plan sync setup required', 'plume' ); ?></strong>
			</p>
			<p>
				<?php
				\esc_html_e(
					'Your site is connected to Plume AI - Write and Design, but the connection has not been fully set up yet. Without this step, plan upgrades and cancellations will not take effect automatically. Click the button below to complete the one-time setup.',
					'plume'
				);
				?>
			</p>
			<p>
				<form method="post" action="<?php echo \esc_url( $action_url ); ?>" class="nj-backfill-form">
					<?php \wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="<?php echo \esc_attr( self::ACTION ); ?>" />
					<button type="submit" class="button button-primary">
					<?php \esc_html_e( 'Complete setup', 'plume' ); ?>
					</button>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a warning when a paid tier is stored but its HMAC signature is
	 * missing or invalid, indicating a direct database edit bypassed the webhook.
	 *
	 * This notice is mutually exclusive with maybe_display() — it requires the
	 * sync secret to already be present, whereas maybe_display() fires when the
	 * secret is absent. Re-registering via the existing rotate action re-issues a
	 * signed tier from the Worker, resolving both conditions.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	public static function maybe_display_sig_mismatch(): void {
		if ( ! self::can_show_tier_notice() ) {
			return;
		}
		// The no-secret notice (maybe_display) handles this case; don't show both.
		if ( '' === (string) \get_option( SiteRegistration::OPTION_SECRET, '' ) ) {
			return;
		}
		if ( ! TierManager::needs_tier_verification_resync() ) {
			return;
		}

		$action_url = \admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
			<strong><?php \esc_html_e( 'Plume AI - Write and Design — Plan verification required', 'plume' ); ?></strong>
			</p>
			<p>
				<?php
				\esc_html_e(
					'Your site shows a paid plan in the database, but the plan integrity signature is missing or does not match. This can happen after a direct database edit or a migration. Until re-verified, the plugin will treat your site as free. Click below to re-sync your plan with Plume AI - Write and Design.',
					'plume'
				);
				?>
			</p>
			<p>
				<form method="post" action="<?php echo \esc_url( $action_url ); ?>" class="nj-backfill-form">
					<?php \wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="<?php echo \esc_attr( self::ACTION ); ?>" />
					<button type="submit" class="button button-primary">
						<?php \esc_html_e( 'Re-sync plan now', 'plume' ); ?>
					</button>
				</form>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the success or failure notice after a redirect from handle_rotate().
	 *
	 * Read via $_GET because admin-post.php redirects back to the referer with
	 * the result encoded as a query argument; the rotate action itself produces
	 * no output. Capability check is repeated to avoid leaking outcomes via a
	 * crafted URL share.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function maybe_display_result(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of redirect result.
		$result = isset( $_GET['plume_rotate'] ) ? \sanitize_text_field( \wp_unslash( $_GET['plume_rotate'] ) ) : '';
		if ( 'success' !== $result && 'fail' !== $result ) {
			return;
		}

		if ( 'success' === $result ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
				<?php \esc_html_e( 'Plume AI - Write and Design — Plan sync is now active. Your site will automatically receive plan updates.', 'plume' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// Read and consume the transient so the diagnostic is shown exactly once.
		$transient_key = self::ERROR_TRANSIENT_PREFIX . \get_current_user_id();
		$detail        = (string) \get_transient( $transient_key );
		if ( '' !== $detail ) {
			\delete_transient( $transient_key );
		}
		?>
		<div class="notice notice-error is-dismissible">
			<p>
			<?php \esc_html_e( 'Plume AI - Write and Design — Setup failed.', 'plume' ); ?>
				<?php if ( '' !== $detail ) : ?>
					<br />
					<code><?php echo \esc_html( $detail ); ?></code>
				<?php endif; ?>
			</p>
			<p>
				<?php
				\esc_html_e(
					'If this persists, please try again in a few minutes. Contact support if the problem continues.',
					'plume'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the admin-post submission and redirect back to the referer.
	 *
	 * Order of checks is deliberate: capability before nonce so that an attacker
	 * without manage_options cannot probe nonce validity; nonce check uses the
	 * standard WordPress die-on-failure path so a tampered submission produces
	 * the canonical "Are you sure?" screen rather than a silent redirect.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function handle_rotate(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to perform this action.', 'plume' ), '', [ 'response' => 403 ] );
		}

		\check_admin_referer( self::NONCE );

		$result = SiteRegistration::rotate_secret();
		$status = \is_wp_error( $result ) ? 'fail' : 'success';

		if ( \is_wp_error( $result ) ) {
			// Surface the message to the next admin render so the admin sees the
			// actual failure (HTTP 404 from an out-of-date service, network error,
			// etc.) instead of a generic placeholder. 5 minutes is long enough
			// to survive the redirect even on slow connections, short enough
			// that a stale message never leaks into an unrelated session.
			\set_transient(
				self::ERROR_TRANSIENT_PREFIX . \get_current_user_id(),
				$result->get_error_message(),
				5 * MINUTE_IN_SECONDS
			);
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( '[WP AI Mind] Tier-sync rotation failed: ' . $result->get_error_message() );
		}

		$referer = \wp_get_referer();
		if ( ! $referer ) {
			$referer = \admin_url();
		}

		$redirect = \add_query_arg( 'plume_rotate', $status, $referer );
		\wp_safe_redirect( $redirect );
		exit;
	}
}

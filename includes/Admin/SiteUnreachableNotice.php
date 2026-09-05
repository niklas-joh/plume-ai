<?php
/**
 * Admin notice shown when site registration has permanently failed.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

use Plume\Admin\Concerns\DetectsPlumeAdminPage;
use Plume\Proxy\SiteRegistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notice for sites whose /register verification callback has permanently
 * failed (e.g. localhost-only install, invalid TLS certificate, a login
 * wall, or a firewall/VPN/IP-allowlist block).
 *
 * Unlike the transient "still connecting" state, a permanent failure will
 * never resolve on its own, so this notice surfaces the plugin's own fixed
 * diagnostic message (never the Worker's raw text) with a manual
 * "Retry verification now" action that bypasses the backoff.
 *
 * Hooks are intentionally limited to users with `manage_options` on a Plume
 * admin page, matching TierSyncBackfillNotice's pattern, so the action and
 * its nonce never leak to lower-privileged roles or unrelated screens.
 *
 * @since NEXT_VERSION
 */
class SiteUnreachableNotice {

	use DetectsPlumeAdminPage;

	/**
	 * Admin-post action slug used both for the form submission and the
	 * `admin_post_{action}` hook name.
	 *
	 * @since NEXT_VERSION
	 */
	private const ACTION = 'plume_retry_verification';

	/**
	 * Nonce action name. Distinct from ACTION to keep nonce verification
	 * explicit at the call site.
	 *
	 * @since NEXT_VERSION
	 */
	private const NONCE = 'plume_retry_verification_nonce';

	/**
	 * Register WordPress hooks for the notice and its admin-post handler.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'admin_notices', [ self::class, 'maybe_display' ] );
		\add_action( 'admin_notices', [ self::class, 'maybe_display_result' ] );
		\add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_retry' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	/**
	 * Enqueue the minimal admin styles required by the notice.
	 *
	 * Reuses the existing `.nj-backfill-form` class shared with
	 * TierSyncBackfillNotice — no new styles needed.
	 *
	 * Gated on the exact conditions under which maybe_display() renders the
	 * form, so the inline rule is never injected on admin pages where the
	 * notice will not appear (wrong capability, non-Plume page, registered, or
	 * no permanent failure on record). On Plume pages where TierSyncBackfillNotice
	 * also registers this rule, the shared `common` handle de-duplicates it.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function enqueue_styles(): void {
		if ( ! self::current_user_can_see_notice() ) {
			return;
		}
		if ( SiteRegistration::is_registered() ) {
			return;
		}
		if ( null === SiteRegistration::get_permanent_failure() ) {
			return;
		}
		\wp_add_inline_style( 'common', '.nj-backfill-form { display: inline; }' );
	}

	/**
	 * Returns true when the current user may view this notice.
	 *
	 * @since NEXT_VERSION
	 * @return bool True when the user has manage_options on a Plume admin page.
	 */
	private static function current_user_can_see_notice(): bool {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return false;
		}
		return self::is_plume_admin_page();
	}

	/**
	 * Render the permanent-failure notice with a manual retry action.
	 *
	 * Gated on the site not already being registered — a permanent-failure
	 * diagnostic left over from before a successful (re-)registration must
	 * never keep showing once the site is actually connected.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function maybe_display(): void {
		if ( ! self::current_user_can_see_notice() ) {
			return;
		}
		if ( SiteRegistration::is_registered() ) {
			return;
		}

		$failure = SiteRegistration::get_permanent_failure();
		if ( null === $failure ) {
			return;
		}

		$action_url = \admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-error">
			<p>
			<strong><?php \esc_html_e( 'Plume AI - Write and Design — This site could not be connected', 'plume' ); ?></strong>
			</p>
			<p><?php echo \esc_html( $failure['message'] ); ?></p>
			<div class="nj-backfill-form-wrap">
				<form method="post" action="<?php echo \esc_url( $action_url ); ?>" class="nj-backfill-form">
					<?php \wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="<?php echo \esc_attr( self::ACTION ); ?>" />
					<button type="submit" class="button button-primary">
					<?php \esc_html_e( 'Retry verification now', 'plume' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the success or failure notice after a redirect from handle_retry().
	 *
	 * Read via $_GET because admin-post.php redirects back to the referer with
	 * the result encoded as a query argument; the retry action itself produces
	 * no output. Capability check is repeated to avoid leaking outcomes via a
	 * crafted URL share.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function maybe_display_result(): void {
		if ( ! self::current_user_can_see_notice() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of redirect result.
		$result = isset( $_GET['plume_retry_verification'] ) ? \sanitize_text_field( \wp_unslash( $_GET['plume_retry_verification'] ) ) : '';
		if ( 'success' !== $result && 'fail' !== $result ) {
			return;
		}

		if ( 'success' === $result ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
				<?php \esc_html_e( 'Plume AI - Write and Design — This site is now connected.', 'plume' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		// The failure branch never echoes the Worker's raw text. A *permanent*
		// failure leaves a diagnostic on record that maybe_display() renders on
		// this same page load, so the copy can point at it. A *transient* failure
		// (timeout, Worker outage) clears that diagnostic via
		// record_registration_outcome(), leaving nothing below to reference — so
		// the copy must be self-contained in that case.
		if ( null !== SiteRegistration::get_permanent_failure() ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p>
				<?php \esc_html_e( 'Plume AI - Write and Design — Verification failed again. See the message below for details.', 'plume' ); ?>
				</p>
			</div>
			<?php
			return;
		}
		?>
		<div class="notice notice-error is-dismissible">
			<p>
			<?php \esc_html_e( 'Plume AI - Write and Design — Verification failed again. Please try again later.', 'plume' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the admin-post submission and redirect back to the referer.
	 *
	 * Bypasses the usual backoff transient — a manual retry click is an
	 * explicit admin action, not the periodic background attempt the backoff
	 * exists to throttle. Order of checks is deliberate: capability before
	 * nonce so that an attacker without manage_options cannot probe nonce
	 * validity; nonce check uses the standard WordPress die-on-failure path so
	 * a tampered submission produces the canonical "Are you sure?" screen
	 * rather than a silent redirect.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function handle_retry(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to perform this action.', 'plume' ), '', [ 'response' => 403 ] );
		}

		\check_admin_referer( self::NONCE );

		$result = SiteRegistration::register();
		SiteRegistration::record_registration_outcome( $result );
		$status = \is_wp_error( $result ) ? 'fail' : 'success';

		$referer = \wp_get_referer();
		if ( ! $referer ) {
			// Fall back to the main Plume page so the page-guard in
			// maybe_display_result() doesn't suppress the outcome notice.
			$referer = \admin_url( 'admin.php?page=plume' );
		}

		$redirect = \add_query_arg( 'plume_retry_verification', $status, $referer );
		\wp_safe_redirect( $redirect );
		exit;
	}
}

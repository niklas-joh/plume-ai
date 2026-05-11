<?php
/**
 * Admin notice that prompts already-registered sites to fetch a tier-sync secret.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Proxy\NJ_Site_Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill notice for sites that registered with the proxy before the
 * tier-sync handshake existed.
 *
 * Such installs hold a valid `wp_ai_mind_site_token` but no
 * `wp_ai_mind_tier_sync_secret`, so the Worker cannot push tier updates to
 * them. The notice exposes a one-click "Re-register" action that calls
 * NJ_Site_Registration::rotate_secret() to populate the missing secret.
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
	private const ACTION = 'wp_ai_mind_rotate_secret';

	/**
	 * Nonce action name. Distinct from ACTION to keep nonce verification
	 * explicit at the call site.
	 *
	 * @since 1.9.0
	 */
	private const NONCE = 'wp_ai_mind_rotate_secret_nonce';

	/**
	 * Register WordPress hooks for the notice and its admin-post handler.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'admin_notices', [ self::class, 'maybe_display' ] );
		\add_action( 'admin_notices', [ self::class, 'maybe_display_result' ] );
		\add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle_rotate' ] );
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
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! NJ_Site_Registration::is_registered() ) {
			return;
		}
		if ( '' !== (string) \get_option( NJ_Site_Registration::OPTION_SECRET, '' ) ) {
			return;
		}

		$action_url = \admin_url( 'admin-post.php' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php \esc_html_e( 'WP AI Mind — Plan sync setup required', 'wp-ai-mind' ); ?></strong>
			</p>
			<p>
				<?php
				\esc_html_e(
					'Your site is registered with the WP AI Mind proxy, but it has not yet been issued a tier-sync secret. Without this secret, plan upgrades and cancellations cannot be pushed to your site automatically. Click the button below to complete the one-time setup.',
					'wp-ai-mind'
				);
				?>
			</p>
			<p>
				<form method="post" action="<?php echo \esc_url( $action_url ); ?>" style="display:inline;">
					<?php \wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="<?php echo \esc_attr( self::ACTION ); ?>" />
					<button type="submit" class="button button-primary">
						<?php \esc_html_e( 'Re-register now', 'wp-ai-mind' ); ?>
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
		$result = isset( $_GET['wp_ai_mind_rotate'] ) ? \sanitize_text_field( \wp_unslash( $_GET['wp_ai_mind_rotate'] ) ) : '';
		if ( 'success' !== $result && 'fail' !== $result ) {
			return;
		}

		if ( 'success' === $result ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php \esc_html_e( 'WP AI Mind — Plan sync is now active. Your site can receive tier updates from the proxy.', 'wp-ai-mind' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php \esc_html_e( 'WP AI Mind — Re-registration failed. Please check your connection and try again.', 'wp-ai-mind' ); ?>
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
			\wp_die( \esc_html__( 'You do not have permission to perform this action.', 'wp-ai-mind' ), '', [ 'response' => 403 ] );
		}

		\check_admin_referer( self::NONCE );

		$result = NJ_Site_Registration::rotate_secret();
		$status = \is_wp_error( $result ) ? 'fail' : 'success';

		$referer = \wp_get_referer();
		if ( ! $referer ) {
			$referer = \admin_url();
		}

		$redirect = \add_query_arg( 'wp_ai_mind_rotate', $status, $referer );
		\wp_safe_redirect( $redirect );
		exit;
	}
}

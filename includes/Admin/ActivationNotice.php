<?php
/**
 * Activation notice: one-time external-services disclosure.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays a one-time admin notice after plugin activation disclosing
 * that the plugin connects to Plume AI - Write and Design and to
 * third-party AI providers.
 *
 * Uses the plume_just_activated option as a single-use flag.
 * The option is deleted before rendering so it cannot be displayed twice,
 * even if the page is reloaded.
 *
 * @since 1.0.0
 */
class ActivationNotice {

	private const OPTION = 'plume_just_activated';

	/**
	 * Returns true when the current admin screen is a Plume plugin page.
	 *
	 * Same detection as TierSyncBackfillNotice::is_plume_admin_page() — kept
	 * private in each notice class because notices must not depend on one
	 * another's internals.
	 *
	 * @since NEXT_VERSION
	 * @return bool True when the URL carries a `page` param starting with 'plume'.
	 */
	private static function is_plume_admin_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, no state change.
		$page = isset( $_GET['page'] ) ? \sanitize_key( \wp_unslash( $_GET['page'] ) ) : '';
		return \str_starts_with( $page, 'plume' );
	}

	/**
	 * Register the admin_notices hook.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function register(): void {
		\add_action( 'admin_notices', [ self::class, 'maybe_display' ] );
	}

	/**
	 * Display the notice if the activation flag is set and the current user
	 * has manage_options capability. Deletes the flag before rendering.
	 *
	 * @since 1.0.0
	 * @since NEXT_VERSION Rendering is limited to Plume admin screens so the
	 *                      notice never occupies unrelated wp-admin pages
	 *                      (WP.org Guideline 11). The flag is only consumed
	 *                      once the notice can actually render, so activating
	 *                      and browsing elsewhere first does not burn the
	 *                      single-use disclosure.
	 * @return void
	 */
	public static function maybe_display(): void {
		if ( ! \get_option( self::OPTION ) ) {
			return;
		}
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! self::is_plume_admin_page() ) {
			return;
		}
		// Delete before rendering — single-use flag, prevents re-display on reload.
		\delete_option( self::OPTION );

		?>
		<div class="notice notice-info is-dismissible">
			<p>
			<strong><?php \esc_html_e( 'Plume AI - Write and Design — External Services & Privacy Notice', 'plume' ); ?></strong>
			</p>
			<p>
				<?php
				\esc_html_e(
					'This plugin connects to Plume AI - Write and Design and to third-party AI providers (Anthropic Claude, OpenAI, Google Gemini). Only your site address is shared during setup — no content leaves your site until you start a conversation. Your messages are then forwarded to the AI provider on your behalf.',
					'plume'
				);
				?>
				<?php
				echo wp_kses(
					sprintf(
						' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
						\esc_url( PLUME_WEBSITE_URL . '/privacy-policy' ),
						\esc_html__( 'Learn more', 'plume' )
					),
					[
						'a' => [
							'href'   => true,
							'target' => true,
							'rel'    => true,
						],
					]
				);
				?>
			</p>
		</div>
		<?php
	}
}

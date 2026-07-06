<?php
/**
 * Activation notice: one-time external-services disclosure.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

use Plume\Admin\Concerns\DetectsPlumeAdminPage;

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

	use DetectsPlumeAdminPage;

	private const OPTION = 'plume_just_activated';

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
	 * @since 1.12.0 Rendering is limited to Plume admin screens so the
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
					'This plugin connects to Plume AI - Write and Design and to third-party AI providers (Anthropic Claude, OpenAI, Google Gemini). Nothing is transmitted until you first use an AI feature — at that point your site address is shared once to connect to the service, and the content you submit is forwarded to the AI provider on your behalf.',
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

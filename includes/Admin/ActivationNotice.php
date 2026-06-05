<?php
/**
 * Activation notice: one-time external-services disclosure.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Displays a one-time admin notice after plugin activation disclosing
 * that the plugin connects to Stilus - Write and Design and to
 * third-party AI providers.
 *
 * Uses the stilus_just_activated option as a single-use flag.
 * The option is deleted before rendering so it cannot be displayed twice,
 * even if the page is reloaded.
 *
 * @since 1.0.0
 */
class ActivationNotice {

	private const OPTION         = 'stilus_just_activated';
	private const LEARN_MORE_URL = 'https://wpaimind.com/privacy-policy'; // TODO: update to canonical Stilus domain once finalised.

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
	 * @return void
	 */
	public static function maybe_display(): void {
		if ( ! \get_option( self::OPTION ) ) {
			return;
		}
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}
		// Delete before rendering — single-use flag, prevents re-display on reload.
		\delete_option( self::OPTION );

		?>
		<div class="notice notice-info is-dismissible">
			<p>
			<strong><?php \esc_html_e( 'Stilus - Write and Design — External Services & Privacy Notice', 'stilus' ); ?></strong>
			</p>
			<p>
				<?php
				\esc_html_e(
					'This plugin connects to Stilus - Write and Design and to third-party AI providers (Anthropic Claude, OpenAI, Google Gemini). Only your site address is shared during setup — no content leaves your site until you start a conversation. Your messages are then forwarded to the AI provider on your behalf.',
					'stilus'
				);
				?>
				<?php
				echo wp_kses(
					sprintf(
						' <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
						\esc_url( self::LEARN_MORE_URL ),
						\esc_html__( 'Learn more', 'stilus' )
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

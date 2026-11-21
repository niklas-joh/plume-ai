<?php
/**
 * Suggested privacy policy text for sites using Plume.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Plume's paragraph to the Settings > Privacy policy guide.
 *
 * @since NEXT_VERSION
 */
final class PrivacyPolicy {

	/**
	 * Plume's privacy policy. Not UTM-tagged: this text is copied into the site's own
	 * public privacy policy, not shown on a plugin screen.
	 *
	 * @since NEXT_VERSION
	 */
	public const POLICY_URL = PLUME_WEBSITE_URL . '/privacy-policy';

	/**
	 * Hook the privacy policy content.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'add_content' ] );
	}

	/**
	 * Register the suggested text with WordPress.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function add_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'Plume AI - Write and Design', wp_kses_post( wpautop( self::text(), false ) ) );
	}

	/**
	 * The suggested text, one translatable string per paragraph.
	 *
	 * The IP address sentence mirrors readme.txt, External services, but speaks of the site's
	 * server rather than "your" IP address, because this text is read by the site's visitors.
	 *
	 * @since NEXT_VERSION
	 * @return string Paragraphs separated by blank lines.
	 */
	public static function text(): string {
		$link = '<a href="' . esc_url( self::POLICY_URL ) . '">' . esc_html__( 'Plume privacy policy', 'plume' ) . '</a>';

		$paragraphs = [
			__( 'This site uses the Plume AI plugin. Content you submit to its AI features is sent to the AI provider chosen by the site owner and, when the site has no own API key, through the Plume managed service, which records usage statistics including this site\'s address unless the site owner turns off "Share managed-service request statistics".', 'plume' ),
			__( 'If the site owner has turned on "Share usage statistics with Plume", the plugin also sends Plume usage statistics linked to this site\'s address: versions, language and plan, which AI features and providers are used, whether AI requests succeed, and timings and token counts. No content, prompts, names, email addresses or other personal data of visitors or users are sent. The statistics are kept with Cloudflare for up to 90 days and copied daily to Plume\'s Google Cloud project in the EU, where they are kept for up to 2 years.', 'plume' ),
			__( 'The site\'s IP address is processed by Cloudflare to deliver each request and to limit abuse, and may appear in Cloudflare\'s short-lived request logs, but it is not recorded in the usage statistics.', 'plume' ),
			sprintf(
				/* translators: %s: link to the Plume privacy policy. */
				__( 'More information: %s', 'plume' ),
				$link
			),
		];
		return implode( "\n\n", $paragraphs );
	}
}

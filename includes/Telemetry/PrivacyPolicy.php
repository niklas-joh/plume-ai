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
	 * @since NEXT_VERSION
	 * @return string Paragraphs separated by blank lines.
	 */
	public static function text(): string {
		$paragraphs = [
			__( 'This site uses the Plume AI plugin. Content you submit to its AI features is sent to the AI provider chosen by the site owner and, when the site has no own API key, through the Plume managed service, which records usage statistics including this site\'s domain.', 'plume' ),
			__( 'If the site owner has turned on anonymous usage sharing, the plugin also sends Plume usage statistics: a random install ID; plugin, WordPress and PHP versions, site language and plan; which AI provider types are set up and which setup steps were completed; and for each AI request the feature, provider, model, success or a fixed error category, and timings. No content, prompts, names, email addresses or other personal data of visitors or users are sent. These statistics are not linked to the managed service\'s records.', 'plume' ),
			__( 'Turning sharing off stops sending and deletes the install ID. Statistics already sent are kept but can no longer be linked to this site.', 'plume' ),
		];
		return implode( "\n\n", $paragraphs );
	}
}

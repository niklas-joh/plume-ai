<?php
/**
 * Builds system prompts by merging site-wide and per-user voice preferences.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Voice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds AI system prompts by merging site-wide and per-user voice preferences.
 *
 * @since 1.0.0
 */
class VoiceInjector {

	private const SITE_OPTION = 'wp_ai_mind_site_voice';
	private const USER_META   = 'wp_ai_mind_voice';

	/**
	 * Build a system prompt combining voice preferences and a feature-specific instruction.
	 *
	 * @since 1.0.0
	 * @param string $feature_instruction Optional feature-specific instruction appended after the voice rules.
	 * @param int    $user_id             User ID whose meta overrides site-level values; 0 means no user override.
	 * @return string System prompt string, or empty string if no voice rules are configured.
	 */
	public function build_system_prompt( string $feature_instruction = '', int $user_id = 0 ): string {
		$voice = $this->get_merged_voice( $user_id );
		$parts = [];

		if ( ! empty( $voice['tone'] ) ) {
			$parts[] = 'Tone: ' . sanitize_text_field( $voice['tone'] );
		}
		if ( ! empty( $voice['style'] ) ) {
			$parts[] = 'Writing style: ' . sanitize_text_field( $voice['style'] );
		}
		if ( ! empty( $voice['language'] ) ) {
			$parts[] = 'Language: ' . sanitize_text_field( $voice['language'] );
		}
		if ( ! empty( $voice['audience'] ) ) {
			$parts[] = 'Target audience: ' . sanitize_text_field( $voice['audience'] );
		}
		if ( ! empty( $voice['avoid'] ) ) {
			$parts[] = 'Avoid: ' . sanitize_text_field( $voice['avoid'] );
		}
		if ( ! empty( $voice['extra'] ) ) {
			$parts[] = sanitize_textarea_field( $voice['extra'] );
		}

		$base = empty( $parts )
			? ''
			: "You are an AI writing assistant. Follow these guidelines:\n" . implode( "\n", $parts );

		if ( '' !== $feature_instruction ) {
			$base = trim( $base . "\n\n" . $feature_instruction );
		}

		return $base;
	}

	/**
	 * Merge site-level and per-user voice settings; user values take precedence when non-empty.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID; 0 returns only site-level values.
	 * @return array<string, string>
	 */
	private function get_merged_voice( int $user_id ): array {
		$site = get_option( self::SITE_OPTION, [] );
		$site = is_array( $site ) ? $site : [];

		if ( $user_id > 0 ) {
			$user_raw = get_user_meta( $user_id, self::USER_META, true );
			$user_raw = is_array( $user_raw ) ? $user_raw : [];
			// User values override site values only when non-empty.
			foreach ( $user_raw as $k => $v ) {
				if ( '' !== $v ) {
					$site[ $k ] = $v;
				}
			}
		}

		return $site;
	}
}

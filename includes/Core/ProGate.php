<?php
declare( strict_types=1 );

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden
namespace WP_AI_Mind\Core {
	// phpcs:enable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden

	/**
	 * Free/Pro gate. Single abstraction — swap the backend without touching callers.
	 *
	 * Backend: Freemius SDK (wam_fs()) when loaded; wp_options flag as fallback.
	 */
	class ProGate {

		private const OPTION_KEY = 'wp_ai_mind_licence_status';

		private static function is_licence_active(): bool {
			return 'active' === \get_option( self::OPTION_KEY, '' );
		}

		public static function is_pro(): bool {
			// When Freemius SDK is loaded, delegate to it.
			// wam_fs() is the Freemius bootstrap function defined in wp-ai-mind.php.
			if ( function_exists( 'wam_fs' ) ) {
				try {
					$result = \wam_fs()->can_use_premium_code__premium_only(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Freemius not yet initialised — fall through to option check.
					$result = self::is_licence_active();
				}
			} else {
				// Fallback: manual licence flag set during activation.
				$result = self::is_licence_active();
			}

			/**
			 * Filters the pro status of the plugin.
			 *
			 * Intended for local development only. Use a gitignored mu-plugin to
			 * override — never define this in production code.
			 *
			 * @param bool $result Whether the current install has a valid pro licence.
			 */
			return (bool) \apply_filters( 'wp_ai_mind_is_pro', $result );
		}

		/** Called from Freemius webhook / activation in P6. */
		public static function activate( string $licence_key ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			// Stub — full Freemius activation in P6.
			update_option( self::OPTION_KEY, 'active' );
			return true;
		}

		public static function deactivate(): void {
			delete_option( self::OPTION_KEY );
		}
	}
}

// phpcs:disable
namespace {
	// Global helper — all callers use this, never the class directly.
	if ( ! function_exists( 'wp_ai_mind_is_pro' ) ) {
		function wp_ai_mind_is_pro(): bool {
			return \WP_AI_Mind\Core\ProGate::is_pro();
		}
	}
}
// phpcs:enable

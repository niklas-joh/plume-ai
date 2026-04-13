<?php
/**
 * Plugin Name:       WP AI Mind
 * Plugin URI:        https://njohansson.eu/wp-ai-mind/
 * Description:       AI-powered content co-pilot for WordPress.
 * Version:           0.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Niklas Johansson
 * Author URI:        https://njohansson.eu
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-ai-mind
 * Domain Path:       /languages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// When the premium version is active alongside the free one, hand off basename
// so Freemius can manage the deactivation of the free version automatically.
if ( function_exists( 'wam_fs' ) ) {
	wam_fs()->set_basename( false, __FILE__ );
} else {

	// Composer autoloader — loads Freemius SDK and WP_AI_Mind classes via PSR-4.
	require_once __DIR__ . '/vendor/autoload.php';

	define( 'WP_AI_MIND_VERSION', '0.3.0' );
	define( 'WP_AI_MIND_FILE', __FILE__ );
	define( 'WP_AI_MIND_DIR', plugin_dir_path( __FILE__ ) );
	define( 'WP_AI_MIND_URL', plugin_dir_url( __FILE__ ) );
	define( 'WP_AI_MIND_BASENAME', plugin_basename( __FILE__ ) );
	define( 'WP_AI_MIND_HTTP_TIMEOUT', 60 ); // seconds — LLM calls can be slow

	// Custom PSR-4 autoloader (retained as fallback; Composer autoloader above
	// already covers WP_AI_Mind\ via composer.json autoload config).
	require_once WP_AI_MIND_DIR . 'includes/Core/Autoloader.php';
	WP_AI_Mind\Core\Autoloader::register();

	// ProGate defines the global wp_ai_mind_is_pro() helper — must load eagerly
	// because the autoloader only fires on class references, not function calls.
	require_once WP_AI_MIND_DIR . 'includes/Core/ProGate.php';

	// ---------------------------------------------------------------------------
	// Freemius SDK bootstrap.
	// Must run at file-load time, never inside a hook callback.
	// @see https://freemius.com/help/documentation/wordpress-sdk/integrating-freemius-sdk/
	// ---------------------------------------------------------------------------

	if ( ! function_exists( 'wam_fs' ) ) {
		/**
		 * Returns the shared Freemius instance for WP AI Mind.
		 *
		 * Function name intentionally short (wam = WP AI Mind); the name is
		 * registered with the Freemius dashboard and must not be changed.
		 *
		 * @phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		 */
		function wam_fs(): \Freemius { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
			global $wam_fs; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

			if ( ! isset( $wam_fs ) ) {
				$wam_fs = fs_dynamic_init( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
					array(
						'id'                  => '26475',
						'slug'                => 'wp-ai-mind',
						'type'                => 'plugin',
						'public_key'          => 'pk_f77289e9f2d538b05fbb6ef43f192',
						'is_premium'          => false,
						'premium_suffix'      => 'Pro',
						'has_premium_version' => true,
						'has_addons'          => false,
						'has_paid_plans'      => true,
						'is_org_compliant'    => true,
						// Automatically removed in the premium version.
						// Do not delete from the free / WP.org version.
						'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
						'trial'               => array(
							'days'               => 7,
							'is_require_payment' => true,
						),
						'menu'                => array(
							'slug'    => 'wp-ai-mind',
							'contact' => false,
							'support' => false,
						),
					)
				);
			}

			return $wam_fs;
		}

		// Initialise Freemius.
		wam_fs();

		// Signal that the SDK has been initialised.
		do_action( 'wam_fs_loaded' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	// Activation / deactivation hooks (must fire before plugins_loaded).
	register_activation_hook( WP_AI_MIND_FILE, [ 'WP_AI_Mind\Core\Plugin', 'activate' ] );
	register_deactivation_hook( WP_AI_MIND_FILE, [ 'WP_AI_Mind\Core\Plugin', 'deactivate' ] );

	// Boot.
	add_action( 'plugins_loaded', [ 'WP_AI_Mind\Core\Plugin', 'instance' ] );
}

<?php
/**
 * Plugin Name:       WP AI Mind
 * Plugin URI:        https://njohansson.eu/wp-ai-mind/
 * Description:       AI-powered content co-pilot for WordPress.
 * Version:           1.7.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Niklas Johansson
 * Author URI:        https://njohansson.eu
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-ai-mind
 * Domain Path:       /languages
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composer autoloader — loads WP_AI_Mind classes via PSR-4.
require_once __DIR__ . '/vendor/autoload.php';

define( 'WP_AI_MIND_VERSION', '1.7.0' );
define( 'WP_AI_MIND_FILE', __FILE__ );
define( 'WP_AI_MIND_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_MIND_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_AI_MIND_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP_AI_MIND_HTTP_TIMEOUT', 60 ); // Seconds — LLM calls can be slow.

// Custom PSR-4 autoloader — retained as a safety net for environments where
// the Composer vendor directory is absent (e.g. a manual plugin upload without
// running `composer install`). Composer's autoloader above takes precedence
// when available.
require_once WP_AI_MIND_DIR . 'includes/Core/Autoloader.php';
WP_AI_Mind\Core\Autoloader::register();

// Activation / deactivation hooks (must fire before plugins_loaded).
register_activation_hook( WP_AI_MIND_FILE, [ 'WP_AI_Mind\Core\Plugin', 'activate' ] );
register_deactivation_hook( WP_AI_MIND_FILE, [ 'WP_AI_Mind\Core\Plugin', 'deactivate' ] );

// Boot.
add_action( 'plugins_loaded', [ 'WP_AI_Mind\Core\Plugin', 'instance' ] );

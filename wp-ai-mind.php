<?php
/**
 * Plugin Name:       Stilus - Write and Design
 * Plugin URI:        https://njohansson.eu/stilus/
 * Description:       AI-powered content co-pilot for WordPress.
 * Version:           1.8.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Niklas Johansson
 * Author URI:        https://njohansson.eu
 * License:           GPL-2.0-or-later
 * Text Domain:       stilus
 * Domain Path:       /languages
 *
 * @package Stilus
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composer autoloader — loads Stilus classes via PSR-4.
require_once __DIR__ . '/vendor/autoload.php';

define( 'STILUS_VERSION', '1.8.0' );
define( 'STILUS_FILE', __FILE__ );
define( 'STILUS_DIR', plugin_dir_path( __FILE__ ) );
define( 'STILUS_URL', plugin_dir_url( __FILE__ ) );
define( 'STILUS_BASENAME', plugin_basename( __FILE__ ) );
define( 'STILUS_HTTP_TIMEOUT', 60 ); // Seconds — LLM calls can be slow.

// Custom PSR-4 autoloader — retained as a safety net for environments where
// the Composer vendor directory is absent (e.g. a manual plugin upload without
// running `composer install`). Composer's autoloader above takes precedence
// when available.
require_once STILUS_DIR . 'includes/Core/Autoloader.php';
Stilus\Core\Autoloader::register();

// Activation / deactivation hooks (must fire before plugins_loaded).
register_activation_hook( STILUS_FILE, [ 'Stilus\Core\Plugin', 'activate' ] );
register_deactivation_hook( STILUS_FILE, [ 'Stilus\Core\Plugin', 'deactivate' ] );

// Boot.
add_action( 'plugins_loaded', [ 'Stilus\Core\Plugin', 'instance' ] );

<?php
/**
 * Plugin Name:       Plume
 * Plugin URI:        https://wpaimind.com
 * Description:       AI-powered writing, image generation, and content design tools for WordPress.
 * Version:           1.10.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Niklas Johansson
 * Author URI:        https://github.com/niklas-joh
 * License:           GPL-2.0-or-later
 * Text Domain:       plume
 * Domain Path:       /languages
 *
 * @package Plume
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Composer autoloader — loads Plume classes via PSR-4.
require_once __DIR__ . '/vendor/autoload.php';

define( 'PLUME_VERSION', '1.10.0' );
define( 'PLUME_FILE', __FILE__ );
define( 'PLUME_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUME_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUME_BASENAME', plugin_basename( __FILE__ ) );
define( 'PLUME_HTTP_TIMEOUT', 60 ); // Seconds — LLM calls can be slow.
define( 'PLUME_WEBSITE_URL', 'https://wpaimind.com' );

// Custom PSR-4 autoloader — retained as a safety net for environments where
// the Composer vendor directory is absent (e.g. a manual plugin upload without
// running `composer install`). Composer's autoloader above takes precedence
// when available.
require_once PLUME_DIR . 'includes/Core/Autoloader.php';
Plume\Core\Autoloader::register();

// Activation / deactivation hooks (must fire before plugins_loaded).
register_activation_hook( PLUME_FILE, [ 'Plume\Core\Plugin', 'activate' ] );
register_deactivation_hook( PLUME_FILE, [ 'Plume\Core\Plugin', 'deactivate' ] );

// Boot.
add_action( 'plugins_loaded', [ 'Plume\Core\Plugin', 'instance' ] );

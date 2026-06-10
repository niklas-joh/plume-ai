<?php
/**
 * Bootstrap file for WordPress integration tests running inside the wp-env
 * tests-wordpress container.
 *
 * Boots a real WordPress instance so REST routes, database writes, and
 * capability checks can be exercised end-to-end without mocking.
 *
 * @package Plume\Tests\Integration
 */

declare( strict_types=1 );

// Plugin constants must exist before WordPress bootstraps.
if ( ! defined( 'PLUME_BASENAME' ) ) {
	define( 'PLUME_BASENAME', 'plume/plume.php' );
}
if ( ! defined( 'PLUME_HTTP_TIMEOUT' ) ) {
	define( 'PLUME_HTTP_TIMEOUT', 60 );
}

// WordPress test library path — wp-env sets this automatically in the
// tests-wordpress container via the WP_TESTS_DIR environment variable.
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fprintf( STDERR, "ERROR: WordPress test library not found at %s\n", $_tests_dir );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

// Load the plugin before WordPress finishes booting so all hooks are
// registered in time for the REST route registration on rest_api_init.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require_once dirname( __DIR__ ) . '/plume.php';
	}
);

require_once $_tests_dir . '/includes/bootstrap.php';

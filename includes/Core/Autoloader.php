<?php
/**
 * PSR-4 autoloader for the Stilus namespace, used as a Composer fallback.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4 autoloader for the Stilus namespace.
 *
 * Retained as a safety net for environments where the Composer vendor directory
 * is absent. Composer's autoloader takes precedence when available.
 *
 * @since 1.0.0
 */
class Autoloader {

	/**
	 * Whether the autoloader has already been registered via spl_autoload_register.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the autoloader with the SPL autoload stack.
	 *
	 * @since 1.0.0
	 * @return bool True if registration succeeded or was already done.
	 */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}
		self::$registered = spl_autoload_register( [ self::class, 'load' ] );
		return (bool) self::$registered;
	}

	/**
	 * Resolve a class name to its file path and require it.
	 *
	 * @since 1.0.0
	 * @param string $class_name Fully-qualified class name to load.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		$prefix = 'Stilus\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		// STILUS_DIR is set in the WordPress bootstrap (stilus.php).
		// In unit test context it is not defined, so fall back to plugin root.
		$base = defined( 'STILUS_DIR' ) ? STILUS_DIR : dirname( __DIR__, 2 ) . '/';
		$file = $base . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

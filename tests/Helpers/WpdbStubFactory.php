<?php
declare( strict_types=1 );

namespace Stilus\Tests\Helpers;

/**
 * Factory for the minimal $wpdb stub shared across the test suite.
 *
 * This stub satisfies UsageTracker::log_usage() without requiring a real database
 * connection. Individual tests that need specific $wpdb behaviour use Mockery instead.
 *
 * @since 1.4.1
 */
final class WpdbStubFactory {

	/**
	 * Creates a minimal $wpdb stub that satisfies log_usage() without crashing.
	 *
	 * @since 1.4.1
	 * @return object
	 */
	public static function create(): object {
		return new class() {
			public string $usermeta      = 'wp_usermeta';
			public int    $rows_affected = 1;
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function query( string $sql ): int { return 1; }
		};
	}
}

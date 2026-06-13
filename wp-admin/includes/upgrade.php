<?php
/**
 * Test-only stub for wp-admin/includes/upgrade.php.
 *
 * Schema::create_tables() require_once's this file. The real file ships with
 * WordPress and must not be committed here. This stub satisfies the require so
 * PHPUnit can test activation logic without a full WordPress install.
 *
 * Excluded from the plugin zip by bin/build-wporg.sh (rsync allow-list).
 */
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '', $execute = true ): array {
		return [];
	}
}

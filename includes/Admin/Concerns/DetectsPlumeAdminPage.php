<?php
/**
 * Shared Plume admin-page detection for admin notices.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin\Concerns;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides Plume admin-page detection to notice classes.
 *
 * Kept in one place so the `plume` page-slug convention only ever has to change
 * in a single location. Notice classes `use` this trait rather than depending on
 * one another's internals.
 *
 * @since 1.12.0
 */
trait DetectsPlumeAdminPage {

	/**
	 * Returns true when the current admin screen is a Plume plugin page.
	 *
	 * Limits notices to Plume pages so WP.org Guideline 11 is satisfied — plugin
	 * notices must not appear on unrelated admin screens.
	 *
	 * @since 1.12.0
	 * @return bool True when the URL carries a `page` param starting with 'plume'.
	 */
	private static function is_plume_admin_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection, no state change.
		$page = isset( $_GET['page'] ) ? \sanitize_key( \wp_unslash( $_GET['page'] ) ) : '';
		return \str_starts_with( $page, 'plume' );
	}
}

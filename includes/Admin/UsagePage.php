<?php
/**
 * Admin page rendering the token usage dashboard.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Stilus usage & cost admin page.
 *
 * Outputs a React mount point; assets are enqueued by UsageModule.
 */
class UsagePage {

	/**
	 * Output the page markup.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		echo '<div id="stilus-usage" class="stilus-page"></div>';
	}
}

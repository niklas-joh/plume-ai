<?php
/**
 * Admin page rendering the token usage dashboard.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Plume usage & cost admin page.
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
		echo '<div id="plume-usage" class="plume-page"></div>';
	}
}

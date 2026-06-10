<?php
/**
 * Admin page rendering the AI SEO metadata manager.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Plume SEO admin page.
 *
 * Outputs a React mount point; assets are enqueued by SeoModule.
 */
class SeoPage {

	/**
	 * Output the page markup.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		echo '<div id="plume-seo" class="plume-page"></div>';
	}
}

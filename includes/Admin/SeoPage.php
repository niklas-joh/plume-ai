<?php
/**
 * Admin page rendering the AI SEO metadata manager.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Stilus SEO admin page.
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
		echo '<div id="stilus-seo" class="stilus-page"></div>';
	}
}

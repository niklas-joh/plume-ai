<?php
/**
 * Admin page rendering the AI image generation interface.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Plume image-generation admin page.
 *
 * Outputs a React mount point; assets are enqueued by ImagesModule.
 */
class ImagesPage {

	/**
	 * Output the page markup.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function render(): void {
		echo '<div id="plume-images" class="plume-page"></div>';
	}
}

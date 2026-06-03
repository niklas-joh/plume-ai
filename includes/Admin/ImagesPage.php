<?php
/**
 * Admin page rendering the AI image generation interface.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Stilus image-generation admin page.
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
		echo '<div id="stilus-images" class="stilus-page"></div>';
	}
}

<?php
/**
 * Admin page rendering the AI SEO metadata manager.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

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
		echo '<div id="wp-ai-mind-seo" class="wp-ai-mind-page"></div>';
	}
}

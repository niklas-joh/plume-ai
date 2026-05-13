<?php
/**
 * Admin page rendering the token usage dashboard.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

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
		echo '<div id="wp-ai-mind-usage" class="wp-ai-mind-page"></div>';
	}
}

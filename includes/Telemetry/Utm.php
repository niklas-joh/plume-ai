<?php
/**
 * Adds UTM parameters to links from the plugin to the Plume website.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags links to PLUME_WEBSITE_URL so the website can tell which screen they came from.
 *
 * The tags carry no identifier and are added whatever the consent state. Links to any
 * other host (checkout, third parties) are returned unchanged.
 *
 * @since NEXT_VERSION
 */
final class Utm {

	/**
	 * Add utm_source=plugin, utm_medium and (optionally) utm_content to a website link.
	 *
	 * @since NEXT_VERSION
	 * @param string $url     Link URL.
	 * @param string $medium  Placement, for example 'dashboard'.
	 * @param string $content Optional link identifier within the placement.
	 * @return string Tagged URL, or the URL unchanged when it is not on the website's host.
	 */
	public static function tag( string $url, string $medium, string $content = '' ): string {
		$site_host = wp_parse_url( PLUME_WEBSITE_URL, PHP_URL_HOST );
		$link_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $site_host ) || ! is_string( $link_host ) || strtolower( $site_host ) !== strtolower( $link_host ) ) {
			return $url;
		}
		$args = [
			'utm_source' => 'plugin',
			'utm_medium' => sanitize_key( $medium ),
		];
		if ( '' !== $content ) {
			$args['utm_content'] = sanitize_key( $content );
		}
		return add_query_arg( $args, $url );
	}
}

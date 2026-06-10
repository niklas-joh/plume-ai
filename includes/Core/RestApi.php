<?php
/**
 * Shared REST API constants for all Stilus REST controllers.
 *
 * @package Stilus
 */

declare( strict_types=1 );

namespace Stilus\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared REST API constants.
 *
 * Controllers reference this instead of redeclaring the route namespace,
 * so a version bump (e.g. stilus/v2) happens in exactly one place.
 *
 * @since 1.9.0
 */
final class RestApi {

	public const API_NAMESPACE = 'stilus/v1';

	/**
	 * Prevent instantiation — constants-only holder.
	 *
	 * @since 1.9.0
	 */
	private function __construct() {}
}

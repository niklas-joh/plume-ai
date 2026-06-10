<?php
/**
 * Shared REST API constants for all Plume REST controllers.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared REST API constants.
 *
 * Controllers reference this instead of redeclaring the route namespace,
 * so a version bump (e.g. plume/v2) happens in exactly one place.
 *
 * @since 1.9.0
 */
final class RestApi {

	public const API_NAMESPACE = 'plume/v1';

	/**
	 * Prevent instantiation — constants-only holder.
	 *
	 * @since 1.9.0
	 */
	private function __construct() {}
}

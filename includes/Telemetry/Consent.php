<?php
/**
 * Stores the site owner's choice about sharing usage statistics.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the usage-sharing consent option.
 *
 * The option is autoloaded because Telemetry::refresh() reads it on every request.
 *
 * @since NEXT_VERSION
 */
final class Consent {

	/**
	 * No choice has been made yet.
	 *
	 * @since NEXT_VERSION
	 */
	public const UNSET = 'unset';

	/**
	 * The site owner agreed to share usage statistics.
	 *
	 * @since NEXT_VERSION
	 */
	public const YES = 'yes';

	/**
	 * The site owner declined, or withdrew consent.
	 *
	 * @since NEXT_VERSION
	 */
	public const NO = 'no';

	/**
	 * Where a choice may be made.
	 *
	 * @since NEXT_VERSION
	 * @var string[]
	 */
	public const SOURCES = [ 'onboarding', 'settings' ];

	/**
	 * Return the stored choice.
	 *
	 * @since NEXT_VERSION
	 * @return string One of UNSET, YES or NO.
	 */
	public static function get(): string {
		$value = get_option( Telemetry::OPTION_CONSENT, self::UNSET );
		return in_array( $value, [ self::YES, self::NO ], true ) ? (string) $value : self::UNSET;
	}

	/**
	 * Record a choice.
	 *
	 * Saying yes (when not already yes) creates the random install ID, starts the hourly
	 * send job and queues `consent_given` and a first `heartbeat`. Saying no deletes the
	 * install ID and any queued events and stops the job; nothing is sent. Withdrawal
	 * works whether or not telemetry is available on this site.
	 *
	 * @since NEXT_VERSION
	 * @param string $value  YES or NO. UNSET is never written.
	 * @param string $source One of SOURCES.
	 * @return bool False for an invalid value or source.
	 */
	public static function set( string $value, string $source ): bool {
		if ( ! in_array( $value, [ self::YES, self::NO ], true ) || ! in_array( $source, self::SOURCES, true ) ) {
			return false;
		}

		if ( self::NO === $value ) {
			update_option( Telemetry::OPTION_CONSENT, self::NO, true );
			delete_option( Telemetry::OPTION_INSTALL_ID );
			Telemetry::clear_queue();
			Telemetry::unschedule();
			Telemetry::refresh();
			return true;
		}

		if ( self::YES === self::get() ) {
			return true;
		}

		update_option( Telemetry::OPTION_CONSENT, self::YES, true );
		if ( ! EventSchema::is_uuid4( (string) get_option( Telemetry::OPTION_INSTALL_ID, '' ) ) ) {
			update_option( Telemetry::OPTION_INSTALL_ID, wp_generate_uuid4(), false );
		}
		// A request that booted before an earlier withdrawal can write its events back
		// after the queue was deleted. Clearing here stops those stale events being sent
		// under the new install ID.
		Telemetry::clear_queue();
		Telemetry::refresh();
		if ( Telemetry::is_active() ) {
			Telemetry::record( 'consent_given', [ 'source' => $source ] );
			Telemetry::record( 'heartbeat', Telemetry::heartbeat_props( [] ) );
			Telemetry::schedule();
		}
		return true;
	}
}

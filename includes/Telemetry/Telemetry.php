<?php
/**
 * Opt-in product usage events.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\Settings\ProviderSettings;
use Plume\Tiers\TierConfig;
use Plume\Tiers\TierManager;

/**
 * Records usage events in memory, queues them in an option and sends them from WP-Cron.
 *
 * A queue and a cron job are used instead of sending at the end of the request so that
 * a web request never makes an outbound HTTP call for telemetry: a slow or unreachable
 * Worker can never delay a page. Events carry their exact time and the daily heartbeat
 * carries the site address (SiteKey), so the statistics can be combined with the managed
 * service's records for the same site.
 *
 * Nothing is recorded, queued or sent unless the site owner opted in (Consent::YES) and
 * telemetry is available (see is_available()). The `active` flag is computed once per
 * request by refresh(); while it is false the provider layer makes no WordPress calls
 * for telemetry.
 *
 * The queue is best effort: two requests that persist at the same moment can lose or
 * duplicate a request's events (read-modify-write on one option), and a failed send
 * drops that batch.
 *
 * @since NEXT_VERSION
 */
final class Telemetry {

	/**
	 * Consent option (autoloaded: read on every request).
	 *
	 * @since NEXT_VERSION
	 */
	public const OPTION_CONSENT = 'plume_telemetry_consent';

	/**
	 * Random install ID option (not autoloaded).
	 *
	 * @since NEXT_VERSION
	 */
	public const OPTION_INSTALL_ID = 'plume_install_id';

	/**
	 * Queue and timers: { queue: list<array>, last_ai_at: int, last_heartbeat_at: int } (not autoloaded).
	 *
	 * @since NEXT_VERSION
	 */
	public const OPTION_STATE = 'plume_telemetry_state';

	/**
	 * First activation time, Unix seconds (not autoloaded).
	 *
	 * @since NEXT_VERSION
	 */
	public const OPTION_ACTIVATED_AT = 'plume_activated_at';

	/**
	 * Hourly send job.
	 *
	 * @since NEXT_VERSION
	 */
	public const CRON_HOOK = 'plume_telemetry_send';

	/**
	 * Worker path, appended to TierConfig::get_proxy_url().
	 *
	 * @since NEXT_VERSION
	 */
	public const ENDPOINT = '/v1/events';

	/**
	 * Batch envelope schema version.
	 *
	 * @since NEXT_VERSION
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Most events kept in the queue; the oldest are dropped first.
	 *
	 * @since NEXT_VERSION
	 */
	public const MAX_QUEUE = 100;

	/**
	 * Events per POST, chosen so one batch always fits the Worker's 16 KB body limit.
	 *
	 * @since NEXT_VERSION
	 */
	public const BATCH_SIZE = 25;

	/**
	 * Whether this request may record events.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	/**
	 * Events recorded during this request, persisted on shutdown.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $buffer = [];

	/**
	 * Hook the queue, the send job and its self-healing schedule check.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function register(): void {
		self::refresh();
		add_action( 'shutdown', [ self::class, 'persist' ], 20 );
		add_action( self::CRON_HOOK, [ self::class, 'send' ] );
		add_action( 'admin_init', [ self::class, 'maybe_reschedule' ] );
	}

	/**
	 * Recompute the active flag from the consent option and availability.
	 *
	 * Consent is checked first so sites that have not opted in never reach
	 * is_available(). The buffer is emptied when telemetry becomes inactive.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function refresh(): void {
		self::$active = ( Consent::YES === Consent::get() ) && self::is_available();
		if ( ! self::$active ) {
			self::$buffer = [];
		}
	}

	/**
	 * Whether telemetry may run on this site at all.
	 *
	 * Filterable with `plume_telemetry_enabled`. The default is false for local and
	 * development environments that still point at the production Worker, so test
	 * traffic never reaches production statistics.
	 *
	 * The result is read at `plugins_loaded`, so add the filter from a plugin or
	 * mu-plugin: a theme's functions.php loads too late to affect sending.
	 *
	 * @since NEXT_VERSION
	 * @return bool
	 */
	public static function is_available(): bool {
		$default = self::default_enabled( wp_get_environment_type(), TierConfig::get_proxy_url() );
		/**
		 * Filters whether usage sharing is available on this site.
		 *
		 * Returning false hides the consent prompt and disables the setting. A site that
		 * already consented can still withdraw.
		 *
		 * @since NEXT_VERSION
		 * @param bool $enabled Default availability.
		 */
		return (bool) apply_filters( 'plume_telemetry_enabled', $default );
	}

	/**
	 * Default availability for an environment type and proxy URL.
	 *
	 * @since NEXT_VERSION
	 * @param string $environment Value of wp_get_environment_type().
	 * @param string $proxy_url   The proxy base URL in use.
	 * @return bool False only for local or development sites using the production Worker.
	 */
	public static function default_enabled( string $environment, string $proxy_url ): bool {
		$is_dev_site   = in_array( $environment, [ 'local', 'development' ], true );
		$is_production = rtrim( TierConfig::DEFAULT_PROXY_URL, '/' ) === rtrim( $proxy_url, '/' );
		return ! ( $is_dev_site && $is_production );
	}

	/**
	 * Whether events are being recorded in this request (in-memory flag only).
	 *
	 * @since NEXT_VERSION
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::$active;
	}

	/**
	 * A random ID that groups the AI requests of one user action.
	 *
	 * @since NEXT_VERSION
	 * @return string A v4 UUID, or '' while inactive.
	 */
	public static function new_turn_id(): string {
		return self::$active ? wp_generate_uuid4() : '';
	}

	/**
	 * Record an event in this request's buffer.
	 *
	 * Does nothing while inactive or for an unknown event. Properties are cleaned against
	 * EventSchema.
	 *
	 * @since NEXT_VERSION
	 * @param string               $event Event name from EventSchema::SPEC.
	 * @param array<string, mixed> $props Event properties.
	 * @return void
	 */
	public static function record( string $event, array $props = [] ): void {
		if ( ! self::$active ) {
			return;
		}
		$item = self::make_event( $event, $props );
		if ( null === $item ) {
			return;
		}
		self::$buffer[] = $item;
		if ( count( self::$buffer ) > self::MAX_QUEUE ) {
			self::$buffer = array_slice( self::$buffer, -self::MAX_QUEUE );
		}
	}

	/**
	 * Append this request's buffer to the queue option (runs on shutdown).
	 *
	 * At most one get_option and one update_option, and only when events were recorded.
	 * Concurrent requests can overwrite each other's additions; this is accepted.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function persist(): void {
		if ( empty( self::$buffer ) ) {
			return;
		}
		$buffer       = self::$buffer;
		self::$buffer = [];
		try {
			$state          = self::get_state();
			$state['queue'] = self::cap( array_merge( $state['queue'], $buffer ) );
			$state          = self::stamp_timers( $state, $buffer );
			update_option( self::OPTION_STATE, $state, false );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Telemetry must never break a request.
		}
	}

	/**
	 * Send the queue to the Worker (hourly cron).
	 *
	 * While inactive it deletes any queue left behind and unschedules itself. Otherwise it
	 * adds a heartbeat when the last one is a day old, empties the queue in one write and
	 * posts it in batches, stopping at the first failed batch. Unsent events are dropped.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function send(): void {
		if ( ! self::$active ) {
			self::clear_queue();
			self::unschedule();
			return;
		}
		try {
			$state        = self::get_state();
			$queue        = array_merge( $state['queue'], self::$buffer );
			$state        = self::stamp_timers( $state, self::$buffer );
			self::$buffer = [];

			$now = time();
			if ( $now - $state['last_heartbeat_at'] >= DAY_IN_SECONDS ) {
				$heartbeat = self::make_event( 'heartbeat', self::heartbeat_props( $state ) );
				if ( null !== $heartbeat ) {
					$queue[] = $heartbeat;
				}
				$state['last_heartbeat_at'] = $now;
			}

			$state['queue'] = [];
			update_option( self::OPTION_STATE, $state, false );

			$queue = self::cap( $queue );
			if ( empty( $queue ) ) {
				return;
			}
			$install_id = self::install_id();
			if ( '' === $install_id ) {
				return;
			}
			foreach ( array_chunk( $queue, self::BATCH_SIZE ) as $batch ) {
				if ( ! self::post( $batch, $install_id, true ) ) {
					break;
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Telemetry must never break cron.
		}
	}

	/**
	 * Properties for a heartbeat event.
	 *
	 * Provider types are listed only when a key or URL is set; keys are never read out.
	 * The site key is left out when home_url() cannot be normalised, so the Worker never
	 * stores a blank one.
	 *
	 * @since NEXT_VERSION
	 * @param array<string, mixed> $state Telemetry state (for last_ai_at); may be empty.
	 * @return array<string, string|int>
	 */
	public static function heartbeat_props( array $state ): array {
		$now      = time();
		$settings = new ProviderSettings();
		$types    = [];
		foreach ( EventSchema::PROVIDERS as $type ) {
			// Ollama has no key; a configured server URL counts as set up.
			$is_set = 'ollama' === $type
				? '' !== (string) get_option( 'plume_ollama_url', '' )
				: $settings->has_key( $type );
			if ( $is_set ) {
				$types[] = $type;
			}
		}

		$wp_version = '';
		if ( 1 === preg_match( '/^(\d+)\.(\d+)/', (string) get_bloginfo( 'version' ), $m ) ) {
			$wp_version = $m[1] . '.' . $m[2];
		}

		$activated_at = (int) get_option( self::OPTION_ACTIVATED_AT, 0 );
		$last_ai_at   = (int) ( $state['last_ai_at'] ?? 0 );

		$props = [
			'tier'                  => TierManager::get_user_tier(),
			'providers'             => implode( ',', $types ),
			'wp_version'            => $wp_version,
			'php_version'           => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
			'locale'                => (string) get_locale(),
			'days_since_activation' => $activated_at > 0 ? intdiv( max( 0, $now - $activated_at ), DAY_IN_SECONDS ) : -1,
			'days_since_last_ai'    => $last_ai_at > 0 ? intdiv( max( 0, $now - $last_ai_at ), DAY_IN_SECONDS ) : -1,
		];

		$site_key = SiteKey::current();
		if ( '' !== $site_key ) {
			$props['site_key'] = $site_key;
		}
		return $props;
	}

	/**
	 * Schedule the hourly send job if it is not scheduled yet.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the hourly send job.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Bring the schedule in line with the active flag (admin_init).
	 *
	 * Heals both directions: schedules when active and missing, unschedules when
	 * inactive and present (for example after the filter turned telemetry off).
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function maybe_reschedule(): void {
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );
		if ( self::$active && ! $scheduled ) {
			self::schedule();
		} elseif ( ! self::$active && $scheduled ) {
			self::unschedule();
		}
	}

	/**
	 * Delete the buffer and the queue option.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function clear_queue(): void {
		self::$buffer = [];
		delete_option( self::OPTION_STATE );
	}

	/**
	 * Plugin deactivation: send a single `deactivated` event, then clear the queue and the job.
	 *
	 * The send is non-blocking and only happens while active. Events still queued are dropped.
	 *
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function on_deactivate(): void {
		try {
			if ( self::$active ) {
				$event      = self::make_event( 'deactivated', [] );
				$install_id = self::install_id();
				if ( null !== $event && '' !== $install_id ) {
					self::post( [ $event ], $install_id, false );
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Deactivation must never fail because of telemetry.
		}
		self::clear_queue();
		self::unschedule();
	}

	/**
	 * Reset the in-memory state.
	 *
	 * @internal Test seam.
	 * @since NEXT_VERSION
	 * @return void
	 */
	public static function reset(): void {
		self::$active = false;
		self::$buffer = [];
	}

	/**
	 * Build a queue item: name, exact ts and clean properties.
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $props Raw properties.
	 * @return array<string, mixed>|null Null for an unknown event.
	 */
	private static function make_event( string $event, array $props ): ?array {
		$clean = EventSchema::sanitise( $event, $props );
		if ( null === $clean ) {
			return null;
		}
		return array_merge(
			[
				'name' => $event,
				'ts'   => time(),
			],
			$clean
		);
	}

	/**
	 * Round a Unix time down to the hour (only for last_ai_at, which is used in whole days).
	 *
	 * @param int $time Unix seconds.
	 * @return int
	 */
	private static function hour( int $time ): int {
		return $time - ( $time % HOUR_IN_SECONDS );
	}

	/**
	 * Keep the newest MAX_QUEUE items.
	 *
	 * @param array<int, array<string, mixed>> $queue Queue items.
	 * @return array<int, array<string, mixed>>
	 */
	private static function cap( array $queue ): array {
		return count( $queue ) > self::MAX_QUEUE ? array_values( array_slice( $queue, -self::MAX_QUEUE ) ) : array_values( $queue );
	}

	/**
	 * Update last_ai_at / last_heartbeat_at for the events being added.
	 *
	 * @param array{queue: array<int, array<string, mixed>>, last_ai_at: int, last_heartbeat_at: int} $state  Telemetry state.
	 * @param array<int, array<string, mixed>>                                                        $events Events being queued.
	 * @return array{queue: array<int, array<string, mixed>>, last_ai_at: int, last_heartbeat_at: int}
	 */
	private static function stamp_timers( array $state, array $events ): array {
		$names = array_column( $events, 'name' );
		if ( in_array( 'ai_request', $names, true ) ) {
			$state['last_ai_at'] = self::hour( time() );
		}
		if ( in_array( 'heartbeat', $names, true ) ) {
			$state['last_heartbeat_at'] = time();
		}
		return $state;
	}

	/**
	 * Read the state option with defaults filled in.
	 *
	 * @return array{queue: array<int, array<string, mixed>>, last_ai_at: int, last_heartbeat_at: int}
	 */
	private static function get_state(): array {
		$raw = get_option( self::OPTION_STATE, [] );
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'queue'             => isset( $raw['queue'] ) && is_array( $raw['queue'] ) ? array_values( $raw['queue'] ) : [],
			'last_ai_at'        => (int) ( $raw['last_ai_at'] ?? 0 ),
			'last_heartbeat_at' => (int) ( $raw['last_heartbeat_at'] ?? 0 ),
		];
	}

	/**
	 * Return the stored install ID, or '' when there is none.
	 *
	 * Read lazily (only when sending) so consenting sites do not pay for an extra
	 * non-autoloaded option query on every request. A missing ID is never recreated
	 * here: this request's active flag was computed at boot, so a withdrawal made
	 * meanwhile by another request (which deletes the ID) must not be undone by
	 * writing a fresh one. Only Consent::set( 'yes' ) creates the ID.
	 *
	 * @return string Lower-case v4 UUID, or ''.
	 */
	private static function install_id(): string {
		$id = (string) get_option( self::OPTION_INSTALL_ID, '' );
		return EventSchema::is_uuid4( $id ) ? strtolower( $id ) : '';
	}

	/**
	 * POST one batch to the Worker.
	 *
	 * @param array<int, array<string, mixed>> $events     Queue items.
	 * @param string                           $install_id Install ID.
	 * @param bool                             $blocking   Whether to wait for the response.
	 * @return bool False when the request failed at transport level (WP_Error).
	 */
	private static function post( array $events, string $install_id, bool $blocking ): bool {
		$body = wp_json_encode(
			[
				'schema'         => self::SCHEMA_VERSION,
				'install_id'     => $install_id,
				'plugin_version' => PLUME_VERSION,
				'events'         => array_values( $events ),
			]
		);
		if ( false === $body ) {
			return false;
		}
		$response = wp_remote_post(
			TierConfig::get_proxy_url() . self::ENDPOINT,
			[
				'blocking'    => $blocking,
				'timeout'     => $blocking ? 5 : 1,
				'redirection' => 0,
				'user-agent'  => 'Plume/' . PLUME_VERSION,
				'headers'     => [ 'Content-Type' => 'application/json' ],
				'body'        => $body,
			]
		);
		// Only a transport failure stops the run: an HTTP error status (for example a 400
		// for one malformed batch) returns quickly and should not drop the other batches.
		return ! is_wp_error( $response );
	}
}

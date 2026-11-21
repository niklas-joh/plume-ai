<?php
/**
 * In-memory WordPress stubs for telemetry tests.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Tests\Helpers;

use Brain\Monkey\Functions;
use Plume\Telemetry\Telemetry;

if ( ! defined( 'PLUME_VERSION' ) ) {
	define( 'PLUME_VERSION', '0.0.0-test' );
}

/**
 * Backs the Options API, WP-Cron and wp_remote_post() with arrays so tests can assert
 * on what telemetry stored, scheduled and sent. Call install() after Monkey\setUp().
 */
final class TelemetryHarness {

	public const INSTALL_ID = '3f2b6c1e-8d4a-4b7f-9a1c-2e5d7f9b0c13';

	/** @var array<string, mixed> */
	public array $options = [];

	/** @var array<string, bool> Autoload flag passed to the last write of each option. */
	public array $autoload = [];

	/** @var array<string, int> */
	public array $scheduled = [];

	/** @var array<int, array{url: string, args: array}> */
	public array $posts = [];

	/** @var int Number of update_option() calls for the state option. */
	public int $state_writes = 0;

	/** @var int Number of get_option() calls for the state option. */
	public int $state_reads = 0;

	/** @var mixed Value wp_remote_post() returns (or a callable to compute it). */
	public mixed $post_result = [ 'response' => [ 'code' => 202 ] ];

	private int $uuid_counter = 0;

	/**
	 * Install the stubs.
	 *
	 * @param array<string, mixed> $options     Initial options.
	 * @param string               $environment wp_get_environment_type() value.
	 * @return self
	 */
	public static function install( array $options = [], string $environment = 'production' ): self {
		$h          = new self();
		$h->options = $options;

		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) use ( $h ) {
				if ( Telemetry::OPTION_STATE === $key ) {
					++$h->state_reads;
				}
				return array_key_exists( $key, $h->options ) ? $h->options[ $key ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) use ( $h ) {
				if ( Telemetry::OPTION_STATE === $key ) {
					++$h->state_writes;
				}
				$h->options[ $key ] = $value;
				if ( null !== $autoload ) {
					$h->autoload[ $key ] = (bool) $autoload;
				}
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $key, $value = '', $deprecated = '', $autoload = 'yes' ) use ( $h ) {
				if ( array_key_exists( $key, $h->options ) ) {
					return false;
				}
				$h->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( $h ) {
				unset( $h->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( $environment );
		Functions\when( 'wp_generate_uuid4' )->alias(
			function () use ( $h ) {
				++$h->uuid_counter;
				return sprintf( '00000000-0000-4000-8000-%012d', $h->uuid_counter );
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias( fn( $hook ) => $h->scheduled[ $hook ] ?? false );
		Functions\when( 'wp_schedule_event' )->alias(
			function ( $ts, $recurrence, $hook ) use ( $h ) {
				$h->scheduled[ $hook ] = $ts;
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) use ( $h ) {
				unset( $h->scheduled[ $hook ] );
				return 0;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $h ) {
				$h->posts[] = [
					'url'  => $url,
					'args' => $args,
				];
				return is_callable( $h->post_result ) ? ( $h->post_result )( $url, $args ) : $h->post_result;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( fn( $data ) => json_encode( $data ) );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_bloginfo' )->justReturn( '6.7.1' );
		Functions\when( 'get_locale' )->justReturn( 'en_GB' );

		return $h;
	}

	/**
	 * Stub the URL helpers Utm::tag() and UpgradeRedirect::url() use.
	 *
	 * @return void
	 */
	public static function stub_url_functions(): void {
		Functions\when( 'wp_parse_url' )->alias( fn( $url, $part = -1 ) => parse_url( $url, $part ) );
		Functions\when( 'add_query_arg' )->alias(
			function ( array $args, string $url ) {
				$parts = parse_url( $url );
				$query = [];
				parse_str( $parts['query'] ?? '', $query );
				$query = array_merge( $query, $args );
				return $parts['scheme'] . '://' . $parts['host'] . ( $parts['path'] ?? '' ) . '?' . http_build_query( $query );
			}
		);
	}

	/**
	 * Mark consent as given (with an install ID) and recompute the active flag.
	 *
	 * @return void
	 */
	public function consent_yes(): void {
		$this->options[ Telemetry::OPTION_CONSENT ]    = 'yes';
		$this->options[ Telemetry::OPTION_INSTALL_ID ] = self::INSTALL_ID;
		Telemetry::refresh();
	}

	/**
	 * Events in the stored queue.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function queue(): array {
		return $this->options[ Telemetry::OPTION_STATE ]['queue'] ?? [];
	}

	/**
	 * Persist the buffer and return the stored queue.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function flush(): array {
		Telemetry::persist();
		return $this->queue();
	}

	/**
	 * Decoded bodies of every POST made.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sent_bodies(): array {
		return array_map( fn( $p ) => json_decode( $p['args']['body'], true ), $this->posts );
	}
}

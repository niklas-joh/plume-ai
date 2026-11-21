<?php
/**
 * Allowlist of usage events and their fields.
 *
 * @package Plume
 */

declare( strict_types=1 );

namespace Plume\Telemetry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The event allowlist shared with the Worker, and the sanitiser that enforces it.
 *
 * SPEC must stay identical to the `events` object in plume-proxy/src/event-schema.json.
 * The plugin zip excludes plume-proxy/, so the data is duplicated here and a PHPUnit
 * contract test (EventSchemaTest) fails when the two drift apart.
 *
 * @since NEXT_VERSION
 */
final class EventSchema {

	/**
	 * Error codes shared by `error_code` and `first_error_code`.
	 *
	 * @since NEXT_VERSION
	 */
	private const ERROR_ENUM = [
		'type'   => 'enum',
		'values' => ErrorCodes::LIST,
	];

	/**
	 * Event name => field name => field spec.
	 *
	 * @since NEXT_VERSION
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public const SPEC = [
		'consent_given'   => [
			'source' => [
				'type'   => 'enum',
				'values' => [ 'onboarding', 'settings' ],
			],
		],
		'onboarding_step' => [
			'step'   => [
				'type'   => 'enum',
				'values' => [ 'welcome', 'providers', 'done' ],
			],
			'action' => [
				'type'   => 'enum',
				'values' => [ 'viewed', 'completed' ],
			],
		],
		'onboarding_done' => [
			'route' => [
				'type'   => 'enum',
				'values' => [ 'managed', 'own_key', 'skipped', 'upgrade' ],
			],
		],
		'ai_request'      => [
			'feature'          => [
				'type'   => 'enum',
				'values' => [ 'chat', 'generator', 'seo', 'images', 'other' ],
			],
			'provider'         => [
				'type'   => 'enum',
				'values' => [ 'claude', 'openai', 'gemini', 'ollama' ],
			],
			'model'            => [
				'type'      => 'pattern',
				'regex'     => '^[A-Za-z0-9._:/-]{1,64}$',
				'max_bytes' => 64,
			],
			'route'            => [
				'type'   => 'enum',
				'values' => [ 'proxy', 'byok', 'ollama' ],
			],
			'outcome'          => [
				'type'   => 'enum',
				'values' => [ 'ok', 'error' ],
			],
			'error_code'       => self::ERROR_ENUM,
			'first_error_code' => self::ERROR_ENUM,
			'http_status'      => [
				'type'    => 'int',
				'min'     => 0,
				'max'     => 599,
				'default' => 0,
			],
			'latency_ms'       => [
				'type'    => 'int',
				'min'     => 0,
				'max'     => 600000,
				'default' => 0,
			],
			'input_tokens'     => [
				'type'    => 'int',
				'min'     => 0,
				'max'     => 10000000,
				'default' => 0,
			],
			'output_tokens'    => [
				'type'    => 'int',
				'min'     => 0,
				'max'     => 10000000,
				'default' => 0,
			],
			'image_count'      => [
				'type'    => 'int',
				'min'     => 0,
				'max'     => 10,
				'default' => 0,
			],
			'attempts'         => [
				'type'    => 'int',
				'min'     => 1,
				'max'     => 10,
				'default' => 1,
			],
			'turn_id'          => [ 'type' => 'uuid4' ],
		],
		'upgrade_click'   => [
			'placement' => [
				'type'   => 'enum',
				'values' => [ 'onboarding', 'dashboard_banner', 'out_of_credits', 'upgrade_page' ],
			],
			'screen'    => [
				'type'   => 'enum',
				'values' => [ 'dashboard', 'chat', 'editor', 'images', 'generator', 'seo', 'upgrade' ],
			],
			'plan'      => [
				'type'   => 'enum',
				'values' => [ 'pricing', 'monthly', 'annual' ],
			],
		],
		'heartbeat'       => [
			'tier'                  => [
				'type'   => 'enum',
				'values' => [ 'free', 'pro_managed', 'pro_byok' ],
			],
			'providers'             => [ 'type' => 'providers' ],
			'wp_version'            => [
				'type'      => 'pattern',
				'regex'     => '^\d{1,2}\.\d{1,2}$',
				'max_bytes' => 5,
			],
			'php_version'           => [
				'type'      => 'pattern',
				'regex'     => '^\d{1,2}\.\d{1,2}$',
				'max_bytes' => 5,
			],
			'locale'                => [
				'type'      => 'pattern',
				'regex'     => '^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]{1,10})?$',
				'max_bytes' => 16,
			],
			'site_key'              => [
				'type'      => 'site_key',
				'max_bytes' => 96,
			],
			'days_since_activation' => [
				'type'    => 'int',
				'min'     => -1,
				'max'     => 36500,
				'default' => -1,
			],
			'days_since_last_ai'    => [
				'type'    => 'int',
				'min'     => -1,
				'max'     => 36500,
				'default' => -1,
			],
		],
		'deactivated'     => [],
	];

	/**
	 * Events the browser may send through POST /plume/v1/telemetry/event, mapped to the
	 * capability the current user needs for each.
	 *
	 * @since NEXT_VERSION
	 * @var array<string, string>
	 */
	public const CLIENT_EVENTS = [
		'onboarding_step' => 'manage_options',
		'upgrade_click'   => 'edit_posts',
	];

	/**
	 * Provider types the `providers` field type accepts.
	 *
	 * @since NEXT_VERSION
	 * @var string[]
	 */
	public const PROVIDERS = [ 'claude', 'openai', 'gemini', 'ollama' ];

	/**
	 * Clean an event's properties against the allowlist.
	 *
	 * Unknown keys are dropped. A known key with an invalid value becomes '' (string
	 * types) or the field default (int types). Keys that were not supplied are left out.
	 *
	 * @since NEXT_VERSION
	 * @param string               $event Event name.
	 * @param array<string, mixed> $props Raw properties.
	 * @return array<string, string|int>|null Clean properties, or null for an unknown event.
	 */
	public static function sanitise( string $event, array $props ): ?array {
		if ( ! isset( self::SPEC[ $event ] ) ) {
			return null;
		}
		$clean = [];
		foreach ( self::SPEC[ $event ] as $field => $spec ) {
			if ( ! array_key_exists( $field, $props ) ) {
				continue;
			}
			$clean[ $field ] = self::sanitise_value( $spec, $props[ $field ] );
		}
		return $clean;
	}

	/**
	 * Whether a string is a version 4 UUID (any letter case).
	 *
	 * @since NEXT_VERSION
	 * @param string $value Candidate value.
	 * @return bool
	 */
	public static function is_uuid4( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value );
	}

	/**
	 * Clean a single value against its field spec.
	 *
	 * @since NEXT_VERSION
	 * @param array<string, mixed> $spec  Field spec.
	 * @param mixed                $value Raw value.
	 * @return string|int
	 */
	private static function sanitise_value( array $spec, mixed $value ): string|int {
		switch ( $spec['type'] ) {
			case 'enum':
				return is_string( $value ) && in_array( $value, $spec['values'], true ) ? $value : '';

			case 'int':
				if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
					$value = (int) $value;
				}
				if ( ! is_int( $value ) ) {
					return $spec['default'];
				}
				return max( $spec['min'], min( $spec['max'], $value ) );

			case 'pattern':
				if ( ! is_string( $value ) || strlen( $value ) > $spec['max_bytes'] ) {
					return '';
				}
				return 1 === preg_match( '~' . $spec['regex'] . '~D', $value ) ? $value : '';

			case 'uuid4':
				return is_string( $value ) && self::is_uuid4( $value ) ? strtolower( $value ) : '';

			case 'providers':
				if ( ! is_string( $value ) ) {
					return '';
				}
				$list = array_values( array_unique( array_intersect( array_map( 'trim', explode( ',', $value ) ), self::PROVIDERS ) ) );
				sort( $list );
				return implode( ',', $list );

			case 'site_key':
				// Only the canonical form is kept, so the value always equals the
				// managed service's plume_metrics.index1 for the same site.
				if ( ! is_string( $value ) || strlen( $value ) > $spec['max_bytes'] ) {
					return '';
				}
				return SiteKey::is_valid( $value ) ? $value : '';
		}
		return '';
	}
}

<?php
/**
 * Which providers and models the managed Worker serves, as reported by the Worker.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Providers;

use Plume\Proxy\SiteRegistration;
use Plume\Tiers\WorkerConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the managed catalogue (enabled providers, model allow-lists, default models)
 * from the Worker's GET /v1/config and caches it in a per-tier transient.
 *
 * The Worker is the source of truth (#1020, #1006, #998): which providers are served
 * and which models each one offers is decided there, so the chat dropdown, the
 * "Plugin default" label and the settings screen follow a KV change without a plugin
 * release. FALLBACK_ENABLED exists only for sites that cannot ask: an unregistered
 * site or an unreachable Worker. It fails closed to Claude because that is the only
 * provider the Worker is guaranteed to serve.
 *
 * Fallback chain when reading the Worker (backwards compatible in either deploy order):
 *
 * | Situation                                    | enabled_providers        | models                  | default_models   |
 * |----------------------------------------------|--------------------------|-------------------------|------------------|
 * | New Worker, valid fields                     | Worker value             | Worker value            | Worker value     |
 * | Old Worker (no `enabled_providers` key)      | every PROXY_CAPABLE      | none (provider's own)   | Worker value     |
 * | Unreachable, non-200, tier mismatch, invalid | FALLBACK_ENABLED         | none (provider's own)   | none (constants) |
 * | Unregistered site (no token)                 | FALLBACK_ENABLED         | none                    | none             |
 * | pro_byok tier                                | every PROXY_CAPABLE      | none                    | none             |
 *
 * @since 2.1.0
 */
final class ManagedCatalogue {

	/**
	 * Transient lifetime, in seconds, for a live catalogue.
	 *
	 * Short, because a KV override is an operational lever that should show up soon.
	 *
	 * @since 2.1.0
	 */
	public const LIVE_TTL = HOUR_IN_SECONDS;

	/**
	 * Transient lifetime, in seconds, for the "fetch failed" fallback entry.
	 *
	 * Short, so an unreachable Worker costs at most one timeout per tier per five
	 * minutes rather than one per request, yet recovery is quick.
	 *
	 * @since 2.1.0
	 */
	public const FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient key prefix; the normalised tier slug is appended.
	 *
	 * @since 2.1.0
	 */
	private const TRANSIENT_PREFIX = 'plume_managed_catalogue_';

	/**
	 * Most model ids kept per provider; a sanity bound on Worker data.
	 *
	 * @since 2.1.0
	 */
	private const MAX_MODELS_PER_PROVIDER = 20;

	/**
	 * Providers enabled when the Worker cannot be asked.
	 *
	 * Mirrors DEFAULT_ENABLED_PROVIDERS in plume-proxy/src/index.ts and is pinned by
	 * tests carrying the same literal on both sides, so keep the two in step.
	 *
	 * TEMPORARY(#1020): Claude-only while OpenAI and Gemini are unavailable.
	 * On removal: return every provider (all of ProviderFactory::PROXY_CAPABLE) here
	 * and update the parity tests on both sides together with the Worker default.
	 *
	 * @since 2.1.0
	 * @var string[]
	 */
	public const FALLBACK_ENABLED = [ 'claude' ];

	/**
	 * Return the catalogue for a tier, using the transient cache.
	 *
	 * Never throws and never returns a partial shape. An unregistered site fetches and
	 * caches nothing, so it picks up the live catalogue as soon as it registers.
	 *
	 * @since 2.1.0
	 * @param string $tier Tier slug.
	 * @return array{enabled_providers: string[], models: array<string, string[]>, default_models: array<string, string>}
	 */
	public static function get( string $tier ): array {
		if ( 'pro_byok' === $tier ) {
			// The Worker rejects BYOK requests, so there is nothing to filter.
			return [
				'enabled_providers' => ProviderFactory::PROXY_CAPABLE,
				'models'            => [],
				'default_models'    => [],
			];
		}

		$tier   = 'pro_managed' === $tier ? 'pro_managed' : 'free';
		$key    = self::TRANSIENT_PREFIX . $tier;
		$cached = get_transient( $key );
		if ( self::is_valid_shape( $cached ) ) {
			return $cached;
		}

		if ( '' === SiteRegistration::get_site_token() ) {
			return self::fallback();
		}

		$live    = self::parse( WorkerConfig::fetch( $tier ) );
		$catalog = $live ?? self::fallback();

		set_transient( $key, $catalog, null === $live ? self::FAILURE_TTL : self::LIVE_TTL );
		return $catalog;
	}

	/**
	 * Whether the Worker serves a provider on a tier.
	 *
	 * @since 2.1.0
	 * @param string $provider Provider slug.
	 * @param string $tier     Tier slug.
	 * @return bool
	 */
	public static function is_enabled( string $provider, string $tier ): bool {
		return in_array( $provider, self::get( $tier )['enabled_providers'], true );
	}

	/**
	 * Model ids the Worker allows for a provider on a tier.
	 *
	 * @since 2.1.0
	 * @param string $provider Provider slug.
	 * @param string $tier     Tier slug.
	 * @return string[]|null Null when the Worker gave no list, so callers use the
	 *                       provider's own get_models().
	 */
	public static function models( string $provider, string $tier ): ?array {
		return self::get( $tier )['models'][ $provider ] ?? null;
	}

	/**
	 * Build the id => label map of managed models for a provider.
	 *
	 * Label precedence: the provider's own label, then ManagedDefaults::label(), then
	 * the raw id (the Worker sends no display names).
	 *
	 * @since 2.1.0
	 * @param string                $provider        Provider slug.
	 * @param string                $tier            Tier slug.
	 * @param array<string, string> $provider_models The provider's own id => label map.
	 * @return array<string, string> Model id => label; the provider's own map when the
	 *                               Worker gave no list.
	 */
	public static function labelled_models( string $provider, string $tier, array $provider_models ): array {
		$ids = self::models( $provider, $tier );
		if ( null === $ids ) {
			return $provider_models;
		}

		$labelled = [];
		foreach ( $ids as $id ) {
			$labelled[ $id ] = $provider_models[ $id ] ?? ManagedDefaults::label( $id ) ?? $id;
		}
		return $labelled;
	}

	/**
	 * The catalogue used when the Worker cannot be asked or answered badly.
	 *
	 * @since 2.1.0
	 * @return array{enabled_providers: string[], models: array<string, string[]>, default_models: array<string, string>}
	 */
	private static function fallback(): array {
		return [
			'enabled_providers' => self::FALLBACK_ENABLED,
			'models'            => [],
			'default_models'    => [],
		];
	}

	/**
	 * Whether a cached value is a complete catalogue entry.
	 *
	 * @since 2.1.0
	 * @param mixed $value Value read from the transient.
	 * @return bool
	 */
	private static function is_valid_shape( $value ): bool {
		return is_array( $value )
			&& is_array( $value['enabled_providers'] ?? null )
			&& is_array( $value['models'] ?? null )
			&& is_array( $value['default_models'] ?? null );
	}

	/**
	 * Validate a Worker response into a catalogue entry.
	 *
	 * @since 2.1.0
	 * @param array<string, mixed>|null $body Decoded /v1/config body, or null on failure.
	 * @return array{enabled_providers: string[], models: array<string, string[]>, default_models: array<string, string>}|null
	 *         Null when the body is missing or `enabled_providers` is present but not a list.
	 */
	private static function parse( ?array $body ): ?array {
		if ( null === $body ) {
			return null;
		}

		// An absent key means an old Worker (today's behaviour: every provider is routed);
		// a present key of the wrong type is a broken body, not an old Worker.
		if ( ! array_key_exists( 'enabled_providers', $body ) ) {
			return [
				'enabled_providers' => ProviderFactory::PROXY_CAPABLE,
				'models'            => [],
				'default_models'    => self::sanitise_default_models( $body['default_models'] ?? null ),
			];
		}
		if ( ! is_array( $body['enabled_providers'] ) ) {
			return null;
		}

		$enabled = [ 'claude' ];
		foreach ( ProviderFactory::PROXY_CAPABLE as $provider ) {
			if ( 'claude' !== $provider && in_array( $provider, $body['enabled_providers'], true ) ) {
				$enabled[] = $provider;
			}
		}

		return [
			'enabled_providers' => $enabled,
			'models'            => self::sanitise_models( $body['models'] ?? null, $enabled ),
			'default_models'    => self::sanitise_default_models( $body['default_models'] ?? null ),
		];
	}

	/**
	 * Keep only id-shaped model ids, for enabled providers, capped per provider.
	 *
	 * @since 2.1.0
	 * @param mixed    $models  The `models` value from the Worker response.
	 * @param string[] $enabled Enabled provider slugs.
	 * @return array<string, string[]> Non-empty valid lists only.
	 */
	private static function sanitise_models( $models, array $enabled ): array {
		if ( ! is_array( $models ) ) {
			return [];
		}

		$valid = [];
		foreach ( $enabled as $provider ) {
			$ids = $models[ $provider ] ?? null;
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$kept = [];
			foreach ( $ids as $id ) {
				if ( is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $id ) ) {
					$kept[] = $id;
				}
			}
			$kept = array_slice( array_values( array_unique( $kept ) ), 0, self::MAX_MODELS_PER_PROVIDER );
			if ( [] !== $kept ) {
				$valid[ $provider ] = $kept;
			}
		}
		return $valid;
	}

	/**
	 * Keep only known providers whose model id looks like a model id.
	 *
	 * @since 2.1.0
	 * @param mixed $default_models The `default_models` value from the Worker response.
	 * @return array<string, string> Valid entries only.
	 */
	private static function sanitise_default_models( $default_models ): array {
		if ( ! is_array( $default_models ) ) {
			return [];
		}

		$valid = [];
		foreach ( ProviderFactory::PROXY_CAPABLE as $provider ) {
			$id = $default_models[ $provider ] ?? null;
			if ( is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $id ) ) {
				$valid[ $provider ] = $id;
			}
		}
		return $valid;
	}
}

<?php
/**
 * Default models used by the managed proxy when a request names no model.
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
 * Resolves the managed Worker's default model per provider and tier.
 *
 * Used only to label the "Plugin default" choice in the chat UI. The Worker is the
 * source of truth: model_id() reads the live `default_models` from GET /v1/config
 * (via WorkerConfig, cached in a per-tier transient), so a KV `config:models`
 * override on the Worker is reflected here. The FREE and PRO constants below are
 * only the offline fallback, for unregistered sites and an unreachable Worker; they
 * are pinned by tests carrying the same literals as DEFAULT_MODEL_BY_TIER in
 * plume-proxy/src/index.ts, so keep the two in step.
 *
 * Labels live here rather than on the provider classes because some managed-only
 * models (for example gpt-4.1-nano) are deliberately absent from a provider's own
 * model list, which only offers models usable with the user's own key. The Worker
 * sends no display names; unknown ids are shown as the raw id by the caller.
 *
 * @since 2.0.0
 */
final class ManagedDefaults {

	/**
	 * Transient lifetime, in seconds, for live defaults.
	 *
	 * Short, because a KV override is an operational lever that should show up soon.
	 *
	 * @since 2.0.0
	 */
	public const LIVE_TTL = HOUR_IN_SECONDS;

	/**
	 * Transient lifetime, in seconds, for the empty "fetch failed" sentinel.
	 *
	 * Short, so an unreachable Worker costs at most one timeout per tier per five
	 * minutes rather than one per request, yet recovery is quick.
	 *
	 * @since 2.0.0
	 */
	public const FAILURE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient key prefix; the normalised tier slug is appended.
	 *
	 * @since 2.0.0
	 */
	private const TRANSIENT_PREFIX = 'plume_default_models_';

	/**
	 * Providers the Worker reports defaults for.
	 *
	 * @since 2.0.0
	 * @var string[]
	 */
	private const PROVIDERS = [ 'claude', 'openai', 'gemini' ];

	/**
	 * Default model id per provider for the free tier.
	 *
	 * @since 2.0.0
	 * @var array<string, string>
	 */
	private const FREE = [
		'claude' => 'claude-haiku-4-5-20251001',
		'openai' => 'gpt-4.1-nano',
		'gemini' => 'gemini-3.1-flash-lite',
	];

	/**
	 * Default model id per provider for the pro tiers.
	 *
	 * @since 2.0.0
	 * @var array<string, string>
	 */
	private const PRO = [
		'claude' => 'claude-sonnet-4-6',
		'openai' => 'gpt-4.1',
		'gemini' => 'gemini-3.6-flash',
	];

	/**
	 * Display label per managed model id.
	 *
	 * @since 2.0.0
	 * @var array<string, string>
	 */
	private const LABELS = [
		'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
		'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
		'gpt-4.1-nano'              => 'GPT-4.1 nano',
		'gpt-4.1'                   => 'GPT-4.1',
		'gemini-3.1-flash-lite'     => 'Gemini 3.1 Flash Lite',
		'gemini-3.6-flash'          => 'Gemini 3.6 Flash',
	];

	/**
	 * Return the model id the managed Worker uses when no model is requested.
	 *
	 * Pro BYOK uses the pro constants without any lookup (the Worker rejects BYOK
	 * requests, so that label is only a best guess). For every other tier the live
	 * value from the Worker wins, per provider; a missing or invalid live value
	 * falls back to the constant for that provider only. Pro Managed is looked up as
	 * itself; free and any unrecognised tier share the free defaults.
	 *
	 * @since 2.0.0
	 * @param string $provider Provider slug: 'claude', 'openai' or 'gemini'.
	 * @param string $tier     Tier slug.
	 * @return string|null Model id, or null when the provider has no managed route.
	 */
	public static function model_id( string $provider, string $tier ): ?string {
		if ( 'pro_byok' === $tier ) {
			return self::PRO[ $provider ] ?? null;
		}

		$tier = 'pro_managed' === $tier ? 'pro_managed' : 'free';
		$live = self::live_defaults( $tier );
		if ( isset( $live[ $provider ] ) ) {
			return $live[ $provider ];
		}

		$map = 'pro_managed' === $tier ? self::PRO : self::FREE;
		return $map[ $provider ] ?? null;
	}

	/**
	 * Return the display label for a managed model id.
	 *
	 * @since 2.0.0
	 * @param string $model_id Model id returned by model_id().
	 * @return string|null Label, or null when the id is not known.
	 */
	public static function label( string $model_id ): ?string {
		return self::LABELS[ $model_id ] ?? null;
	}

	/**
	 * Return the live default model per provider for a tier, using the transient cache.
	 *
	 * A cached empty array is the "fetch failed" sentinel. An unregistered site
	 * (no token) fetches and caches nothing, so it picks up live values as soon as
	 * it registers.
	 *
	 * @since 2.0.0
	 * @param string $tier Normalised tier slug: 'free' or 'pro_managed'.
	 * @return array<string, string> Validated model id per provider; may be empty.
	 */
	private static function live_defaults( string $tier ): array {
		$key    = self::TRANSIENT_PREFIX . $tier;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( '' === SiteRegistration::get_site_token() ) {
			return [];
		}

		$body = WorkerConfig::fetch( $tier );
		$live = null === $body ? [] : self::sanitise_default_models( $body['default_models'] ?? null );

		set_transient( $key, $live, [] === $live ? self::FAILURE_TTL : self::LIVE_TTL );
		return $live;
	}

	/**
	 * Keep only known providers whose model id looks like a model id.
	 *
	 * @since 2.0.0
	 * @param mixed $default_models The `default_models` value from the Worker response.
	 * @return array<string, string> Valid entries only.
	 */
	private static function sanitise_default_models( $default_models ): array {
		if ( ! is_array( $default_models ) ) {
			return [];
		}

		$valid = [];
		foreach ( self::PROVIDERS as $provider ) {
			$id = $default_models[ $provider ] ?? null;
			if ( is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $id ) ) {
				$valid[ $provider ] = $id;
			}
		}
		return $valid;
	}
}

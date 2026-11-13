<?php
/**
 * Default models used by the managed proxy when a request names no model.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the managed Worker's default model per provider and tier.
 *
 * Used only to label the "Plugin default" choice in the chat UI. The Worker is the
 * source of truth: model_id() reads the live `default_models` from GET /v1/config
 * (via ManagedCatalogue, cached in a per-tier transient), so a KV `config:models`
 * override on the Worker is reflected here. For a provider the Worker does not serve,
 * `default_models` reports the model that actually answers (#1020). The FREE and PRO constants below are
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
 * @since 2.1.0 Live values come from ManagedCatalogue, which also caches the
 *                     Worker's enabled providers and model lists.
 */
final class ManagedDefaults {

	/**
	 * Transient lifetime, in seconds, for live defaults.
	 *
	 * Kept for backwards compatibility; the cache now lives in ManagedCatalogue.
	 *
	 * @since 2.0.0
	 * @since 2.1.0 Alias of ManagedCatalogue::LIVE_TTL.
	 */
	public const LIVE_TTL = ManagedCatalogue::LIVE_TTL;

	/**
	 * Transient lifetime, in seconds, for the empty "fetch failed" sentinel.
	 *
	 * Kept for backwards compatibility; the cache now lives in ManagedCatalogue.
	 *
	 * @since 2.0.0
	 * @since 2.1.0 Alias of ManagedCatalogue::FAILURE_TTL.
	 */
	public const FAILURE_TTL = ManagedCatalogue::FAILURE_TTL;

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
	 * Return the live default model per provider for a tier.
	 *
	 * Delegates to the catalogue, which owns the transient cache. An empty array
	 * means no live value is available (unregistered site or unreachable Worker).
	 *
	 * @since 2.0.0
	 * @since 2.1.0 Reads ManagedCatalogue instead of a transient of its own.
	 * @param string $tier Normalised tier slug: 'free' or 'pro_managed'.
	 * @return array<string, string> Validated model id per provider; may be empty.
	 */
	private static function live_defaults( string $tier ): array {
		return ManagedCatalogue::get( $tier )['default_models'];
	}
}

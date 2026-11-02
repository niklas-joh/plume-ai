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
 * Mirrors the managed Worker's default model per provider and tier.
 *
 * Used only to label the "Plugin default" choice in the chat UI. The Worker is the
 * source of truth: keep DEFAULT_MODEL_BY_TIER in plume-proxy/src/index.ts in step
 * (both sides are pinned by tests carrying the same literals). The Worker catalogue
 * can be overridden in KV at runtime, so this is a best-effort mirror; a
 * Worker-driven /v1/config would remove the duplication.
 *
 * Labels live here rather than on the provider classes because some managed-only
 * models (for example gpt-4.1-nano) are deliberately absent from a provider's own
 * model list, which only offers models usable with the user's own key.
 *
 * @since NEXT_VERSION
 */
final class ManagedDefaults {

	/**
	 * Default model id per provider for the free tier.
	 *
	 * @since NEXT_VERSION
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
	 * @since NEXT_VERSION
	 * @var array<string, string>
	 */
	private const PRO = [
		'claude' => 'claude-sonnet-4-6',
		'openai' => 'gpt-4.1',
		'gemini' => 'gemini-3.5-flash',
	];

	/**
	 * Display label per managed model id.
	 *
	 * @since NEXT_VERSION
	 * @var array<string, string>
	 */
	private const LABELS = [
		'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
		'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
		'gpt-4.1-nano'              => 'GPT-4.1 nano',
		'gpt-4.1'                   => 'GPT-4.1',
		'gemini-3.1-flash-lite'     => 'Gemini 3.1 Flash Lite',
		'gemini-3.5-flash'          => 'Gemini 3.5 Flash',
	];

	/**
	 * Return the model id the managed Worker uses when no model is requested.
	 *
	 * Pro Managed and Pro BYOK (without a key it routes through the proxy) share the
	 * pro defaults; free and any unrecognised tier use the free defaults.
	 *
	 * @since NEXT_VERSION
	 * @param string $provider Provider slug: 'claude', 'openai' or 'gemini'.
	 * @param string $tier     Tier slug.
	 * @return string|null Model id, or null when the provider has no managed route.
	 */
	public static function model_id( string $provider, string $tier ): ?string {
		$map = in_array( $tier, [ 'pro_managed', 'pro_byok' ], true ) ? self::PRO : self::FREE;
		return $map[ $provider ] ?? null;
	}

	/**
	 * Return the display label for a managed model id.
	 *
	 * @since NEXT_VERSION
	 * @param string $model_id Model id returned by model_id().
	 * @return string|null Label, or null when the id is not known.
	 */
	public static function label( string $model_id ): ?string {
		return self::LABELS[ $model_id ] ?? null;
	}
}

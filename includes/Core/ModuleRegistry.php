<?php
/**
 * Registry that tracks enabled/disabled state for all AI modules.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry that tracks enabled/disabled state for all AI modules.
 *
 * State is persisted in the `wp_ai_mind_modules` option and merged with
 * hard-coded defaults so new modules are on by default without a migration.
 *
 * @since 1.0.0
 */
class ModuleRegistry {

	private const OPTION_KEY = 'wp_ai_mind_modules';

	/** Modules that are on by default (no user action required). */
	private const DEFAULTS = [
		'chat'            => true,
		'text_rewrite'    => true,
		'summaries'       => true,
		'seo'             => false,
		'images'          => false,
		'generator'       => true,
		'frontend_widget' => false,
		'usage'           => true,
	];

	/**
	 * Current module state merged from defaults and the saved option.
	 *
	 * @var array<string, bool>
	 */
	private array $state;

	/**
	 * Load saved module state from the database.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$saved       = get_option( self::OPTION_KEY, [] );
		$this->state = array_merge( self::DEFAULTS, (array) $saved );
	}

	/**
	 * Check whether a module is currently enabled.
	 *
	 * @since 1.0.0
	 * @param string $module Module slug (e.g. 'chat', 'seo').
	 * @return bool
	 */
	public function is_enabled( string $module ): bool {
		return (bool) ( $this->state[ $module ] ?? false );
	}

	/**
	 * Return the full module state map.
	 *
	 * @since 1.0.0
	 * @return array<string, bool>
	 */
	public function get_all(): array {
		return $this->state;
	}

	/**
	 * Enable or disable a module and persist the new state.
	 *
	 * @since 1.0.0
	 * @param string $module  Module slug.
	 * @param bool   $enabled Whether the module should be enabled.
	 * @return void
	 */
	public function set( string $module, bool $enabled ): void {
		$this->state[ $module ] = $enabled;
		update_option( self::OPTION_KEY, $this->state );
	}
}

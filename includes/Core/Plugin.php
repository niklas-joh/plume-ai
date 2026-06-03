<?php
/**
 * Plugin bootstrap singleton — wires all hooks and owns the module registry.
 *
 * @package Stilus
 */

declare( strict_types=1 );
namespace Stilus\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stilus\DB\Schema;
use Stilus\Proxy\SiteRegistration;
use Stilus\Tiers\TierManager;

/**
 * Plugin bootstrap singleton — wires hooks and owns the module registry.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Module enabled/disabled state registry.
	 *
	 * @var ModuleRegistry
	 */
	private ModuleRegistry $modules;

	/**
	 * Initialise module registry and wire WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->modules = new ModuleRegistry();
		$this->init_hooks();
	}

	/**
	 * Return (or create) the single Plugin instance.
	 *
	 * @since 1.0.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Prevent cloning to enforce the singleton invariant.
	 *
	 * @since 1.0.0
	 */
	private function __clone() {}

	/**
	 * Block unserialisation to enforce the singleton invariant.
	 *
	 * @since 1.0.0
	 * @throws \RuntimeException Always.
	 * @return void
	 */
	public function __wakeup(): void {
		throw new \RuntimeException( 'Cannot unserialise singleton.' );
	}

	/**
	 * Reset the singleton — for use in unit tests only so tests do not leak state.
	 *
	 * @internal
	 * @since 1.0.0
	 * @return void
	 */
	public static function reset_instance(): void {
		self::$instance = null;
	}

	/**
	 * Register all WordPress action hooks required by the plugin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function init_hooks(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'admin_init', [ SiteRegistration::class, 'maybe_register' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'stilus_trial_check', [ \Stilus\Tiers\TierManager::class, 'maybe_demote_expired_trials' ] );
		add_action( 'stilus_register_menu', [ \Stilus\Admin\AdminMenu::class, 'register' ] );
		add_action( 'stilus_register_rest_routes', [ \Stilus\Admin\OnboardingRestController::class, 'register_routes' ] );
		add_action( 'stilus_register_rest_routes', [ \Stilus\Admin\TestKeyRestController::class, 'register_routes' ] );
		add_action( 'stilus_register_rest_routes', [ \Stilus\Admin\ActivationVerifyRestController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ \Stilus\Payments\TierUpdateWebhookController::class, 'register' ] );
		\Stilus\Admin\TierStatusPage::register_hooks();
		\Stilus\Admin\UsageWidget::register_hooks();
		\Stilus\Admin\ActivationNotice::register();
		\Stilus\Admin\TierSyncBackfillNotice::register();
		if ( defined( 'STILUS_DEV_KEY' ) ) {
			\Stilus\Admin\DevToolsPage::register_hooks();
			add_action( 'stilus_register_rest_routes', [ \Stilus\Admin\DevToolsRestController::class, 'register_routes' ] );
		}
		if ( $this->modules->is_enabled( 'chat' ) ) {
			add_action( 'plugins_loaded', [ \Stilus\Modules\Chat\ChatModule::class, 'register' ], 20 );
			\Stilus\Modules\Editor\EditorModule::register();
		}
		if ( $this->modules->is_enabled( 'generator' ) ) {
			\Stilus\Modules\Generator\GeneratorModule::register();
		}
		if ( $this->modules->is_enabled( 'frontend_widget' ) ) {
			\Stilus\Modules\Frontend\FrontendWidgetModule::register();
		}
		if ( $this->modules->is_enabled( 'usage' ) ) {
			\Stilus\Modules\Usage\UsageModule::register();
		}
		// SEO and Images are always registered so their admin pages enqueue assets;
		// the Pro gate is enforced inside each React app.
		\Stilus\Modules\Seo\SeoModule::register();
		\Stilus\Modules\Images\ImagesModule::register();
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'stilus',
			false,
			dirname( STILUS_BASENAME ) . '/languages'
		);
	}

	/**
	 * Dispatch the admin menu registration action.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_admin_menu(): void {
		// Registered fully in Admin\AdminMenu — hooked in P3.
		do_action( 'stilus_register_menu' );
	}

	/**
	 * Dispatch the REST routes registration action.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_rest_routes(): void {
		do_action( 'stilus_register_rest_routes' );
	}

	// ── Activation / deactivation ─────────────────────────────────────────────

	/**
	 * Run on plugin activation: create DB tables and schedule cron events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate(): void {
		Schema::create_tables();
		self::maybe_migrate_from_wp_ai_mind();
		update_option( 'stilus_just_activated', true );
		if ( ! wp_next_scheduled( 'stilus_trial_check' ) ) {
			wp_schedule_event( time(), 'daily', 'stilus_trial_check' );
		}
		self::backfill_site_tier_option();
		flush_rewrite_rules();
	}

	/**
	 * One-time migration of wp_ai_mind_* options to stilus_* equivalents.
	 *
	 * Runs on the first activate() call after the plugin is renamed from WP AI Mind
	 * to Stilus. Skipped on fresh installs and on repeat activations via the
	 * stilus_options_migrated flag. Each option is only copied when the new key does
	 * not yet exist — existing stilus_* values are never overwritten.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	private static function maybe_migrate_from_wp_ai_mind(): void {
		if ( get_option( 'stilus_options_migrated', false ) ) {
			return;
		}

		// Remove orphaned cron task left behind by the old plugin name.
		wp_clear_scheduled_hook( 'wp_ai_mind_trial_check' );

		// Pairs: [ old_key, new_key ]. Copied only when new key is absent.
		$pairs = [
			[ 'wp_ai_mind_provider_keys', 'stilus_provider_keys' ],
			[ 'wp_ai_mind_default_provider', 'stilus_default_provider' ],
			[ 'wp_ai_mind_image_provider', 'stilus_image_provider' ],
			[ 'wp_ai_mind_site_voice', 'stilus_site_voice' ],
			[ 'wp_ai_mind_modules', 'stilus_modules' ],
			[ 'wp_ai_mind_ollama_url', 'stilus_ollama_url' ],
			[ 'wp_ai_mind_allowed_post_types', 'stilus_allowed_post_types' ],
			[ 'wp_ai_mind_enable_write_tools', 'stilus_enable_write_tools' ],
			[ 'wp_ai_mind_backfill_done', 'stilus_backfill_done' ],
		];

		foreach ( $pairs as [ $old, $new ] ) {
			// Skip if the new option already exists — do not overwrite user changes.
			if ( false !== get_option( $new, false ) ) {
				continue;
			}
			$value = get_option( $old, false );
			if ( false !== $value ) {
				update_option( $new, $value, false );
			}
		}

		update_option( 'stilus_options_migrated', true, false );
	}

	/**
	 * One-time migration to seed the site-tier option from a paid user's meta.
	 *
	 * Pre-1.9.0 the paid tier was stored only as per-user meta, which broke
	 * logged-out callers, cron, and CLI. On upgrade we promote that meta value
	 * to the site option so resolution stays correct.
	 *
	 * A `stilus_backfill_done` marker is written after the first run so that
	 * repeated activate/deactivate cycles and fresh installs never re-execute
	 * the `get_users()` query.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	private static function backfill_site_tier_option(): void {
		// Already migrated — skip without touching the DB.
		if ( get_option( 'stilus_backfill_done', false ) ) {
			return;
		}

		if ( false !== get_option( TierManager::SITE_OPTION, false ) ) {
			update_option( 'stilus_backfill_done', true, false );
			return;
		}

		$users = get_users(
			[
				'meta_key'   => TierManager::META_KEY,
				'meta_value' => [ 'pro_managed', 'pro_byok' ],
				'fields'     => 'ID',
				'number'     => 1,
			]
		);
		if ( empty( $users ) ) {
			update_option( 'stilus_backfill_done', true, false );
			return;
		}

		$tier = (string) get_user_meta( (int) $users[0], TierManager::META_KEY, true );
		TierManager::set_site_tier( $tier );
		update_option( 'stilus_backfill_done', true, false );
	}

	/**
	 * Run on plugin deactivation: clear scheduled cron events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'stilus_trial_check' );
		// Also clear the legacy hook name that may still be in the cron table on upgraded sites.
		wp_clear_scheduled_hook( 'wp_ai_mind_trial_check' );
		flush_rewrite_rules();
	}

	// ── Accessors ─────────────────────────────────────────────────────────────

	/**
	 * Return the module registry for this plugin instance.
	 *
	 * @since 1.0.0
	 * @return ModuleRegistry
	 */
	public function modules(): ModuleRegistry {
		return $this->modules;
	}
}

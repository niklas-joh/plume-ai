<?php
/**
 * Plugin bootstrap singleton — wires all hooks and owns the module registry.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Plume\DB\Schema;
use Plume\Proxy\SiteRegistration;
use Plume\Tiers\TierManager;

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
		add_action( 'plume_trial_check', [ \Plume\Tiers\TierManager::class, 'maybe_demote_expired_trials' ] );
		add_action( 'plume_register_menu', [ \Plume\Admin\AdminMenu::class, 'register' ] );
		add_action( 'plume_register_rest_routes', [ \Plume\Admin\OnboardingRestController::class, 'register_routes' ] );
		add_action( 'plume_register_rest_routes', [ \Plume\Admin\TestKeyRestController::class, 'register_routes' ] );
		add_action( 'plume_register_rest_routes', [ \Plume\Admin\ActivationVerifyRestController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ \Plume\Payments\TierUpdateWebhookController::class, 'register' ] );
		\Plume\Admin\TierStatusPage::register_hooks();
		\Plume\Admin\UsageWidget::register_hooks();
		\Plume\Admin\ActivationNotice::register();
		\Plume\Admin\TierSyncBackfillNotice::register();
		if ( defined( 'PLUME_DEV_KEY' ) ) {
			\Plume\Admin\DevToolsPage::register_hooks();
			add_action( 'plume_register_rest_routes', [ \Plume\Admin\DevToolsRestController::class, 'register_routes' ] );
		}
		if ( $this->modules->is_enabled( 'chat' ) ) {
			add_action( 'plugins_loaded', [ \Plume\Modules\Chat\ChatModule::class, 'register' ], 20 );
			\Plume\Modules\Editor\EditorModule::register();
		}
		if ( $this->modules->is_enabled( 'generator' ) ) {
			\Plume\Modules\Generator\GeneratorModule::register();
		}
		if ( $this->modules->is_enabled( 'frontend_widget' ) ) {
			\Plume\Modules\Frontend\FrontendWidgetModule::register();
		}
		if ( $this->modules->is_enabled( 'usage' ) ) {
			\Plume\Modules\Usage\UsageModule::register();
		}
		// SEO and Images are always registered so their admin pages enqueue assets;
		// the Pro gate is enforced inside each React app.
		\Plume\Modules\Seo\SeoModule::register();
		\Plume\Modules\Images\ImagesModule::register();
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'plume',
			false,
			dirname( PLUME_BASENAME ) . '/languages'
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
		do_action( 'plume_register_menu' );
	}

	/**
	 * Dispatch the REST routes registration action.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_rest_routes(): void {
		do_action( 'plume_register_rest_routes' );
	}

	// ── Activation / deactivation ─────────────────────────────────────────────

	/**
	 * Run on plugin activation: create DB tables, seed default options, and schedule cron events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function activate(): void {
		Schema::create_tables();
		update_option( 'plume_just_activated', true );
		// add_option() is a no-op when the option already exists, so an admin who
		// has explicitly disabled write tools will not have their preference reset.
		add_option( 'plume_enable_write_tools', true );
		if ( ! wp_next_scheduled( 'plume_trial_check' ) ) {
			wp_schedule_event( time(), 'daily', 'plume_trial_check' );
		}
		self::backfill_site_tier_option();
		flush_rewrite_rules();
	}

	/**
	 * One-time migration to seed the site-tier option from a paid user's meta.
	 *
	 * Pre-1.9.0 the paid tier was stored only as per-user meta, which broke
	 * logged-out callers, cron, and CLI. On upgrade we promote that meta value
	 * to the site option so resolution stays correct.
	 *
	 * A `plume_backfill_done` marker is written after the first run so that
	 * repeated activate/deactivate cycles and fresh installs never re-execute
	 * the `get_users()` query.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	private static function backfill_site_tier_option(): void {
		// Already migrated — skip without touching the DB.
		if ( get_option( 'plume_backfill_done', false ) ) {
			return;
		}

		if ( false !== get_option( TierManager::SITE_OPTION, false ) ) {
			update_option( 'plume_backfill_done', true, false );
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
			update_option( 'plume_backfill_done', true, false );
			return;
		}

		$tier = (string) get_user_meta( (int) $users[0], TierManager::META_KEY, true );
		TierManager::set_site_tier( $tier );
		update_option( 'plume_backfill_done', true, false );
	}

	/**
	 * Run on plugin deactivation: clear scheduled cron events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'plume_trial_check' );
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

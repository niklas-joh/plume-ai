<?php
/**
 * Plugin bootstrap singleton — wires all hooks and owns the module registry.
 *
 * @package WP_AI_Mind
 */

declare( strict_types=1 );
namespace WP_AI_Mind\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_AI_Mind\DB\Schema;
use WP_AI_Mind\Proxy\NJ_Site_Registration;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

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
		add_action( 'admin_init', [ NJ_Site_Registration::class, 'maybe_register' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'wp_ai_mind_trial_check', [ \WP_AI_Mind\Tiers\NJ_Tier_Manager::class, 'maybe_demote_expired_trials' ] );
		add_action( 'wp_ai_mind_register_menu', [ \WP_AI_Mind\Admin\AdminMenu::class, 'register' ] );
		add_action( 'wp_ai_mind_register_rest_routes', [ \WP_AI_Mind\Admin\OnboardingRestController::class, 'register_routes' ] );
		add_action( 'wp_ai_mind_register_rest_routes', [ \WP_AI_Mind\Admin\TestKeyRestController::class, 'register_routes' ] );
		add_action( 'wp_ai_mind_register_rest_routes', [ \WP_AI_Mind\Admin\ActivationVerifyRestController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ \WP_AI_Mind\Payments\TierUpdateWebhookController::class, 'register' ] );
		\WP_AI_Mind\Admin\NJ_Tier_Status_Page::register_hooks();
		\WP_AI_Mind\Admin\NJ_Api_Key_Settings::register_hooks();
		\WP_AI_Mind\Admin\NJ_Usage_Widget::register_hooks();
		\WP_AI_Mind\Admin\ActivationNotice::register();
		\WP_AI_Mind\Admin\TierSyncBackfillNotice::register();
		if ( $this->modules->is_enabled( 'chat' ) ) {
			add_action( 'plugins_loaded', [ \WP_AI_Mind\Modules\Chat\ChatModule::class, 'register' ], 20 );
			\WP_AI_Mind\Modules\Editor\EditorModule::register();
		}
		if ( $this->modules->is_enabled( 'generator' ) ) {
			\WP_AI_Mind\Modules\Generator\GeneratorModule::register();
		}
		if ( $this->modules->is_enabled( 'frontend_widget' ) ) {
			\WP_AI_Mind\Modules\Frontend\FrontendWidgetModule::register();
		}
		if ( $this->modules->is_enabled( 'usage' ) ) {
			\WP_AI_Mind\Modules\Usage\UsageModule::register();
		}
		// SEO and Images are always registered so their admin pages enqueue assets;
		// the Pro gate is enforced inside each React app.
		\WP_AI_Mind\Modules\Seo\SeoModule::register();
		\WP_AI_Mind\Modules\Images\ImagesModule::register();
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-ai-mind',
			false,
			dirname( WP_AI_MIND_BASENAME ) . '/languages'
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
		do_action( 'wp_ai_mind_register_menu' );
	}

	/**
	 * Dispatch the REST routes registration action.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_rest_routes(): void {
		do_action( 'wp_ai_mind_register_rest_routes' );
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
		update_option( 'wp_ai_mind_just_activated', true );
		if ( ! wp_next_scheduled( 'wp_ai_mind_trial_check' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_ai_mind_trial_check' );
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
	 * A `wp_ai_mind_backfill_done` marker is written after the first run so that
	 * repeated activate/deactivate cycles and fresh installs never re-execute
	 * the `get_users()` query.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	private static function backfill_site_tier_option(): void {
		// Already migrated — skip without touching the DB.
		if ( get_option( 'wp_ai_mind_backfill_done', false ) ) {
			return;
		}

		if ( false !== get_option( NJ_Tier_Manager::SITE_OPTION, false ) ) {
			update_option( 'wp_ai_mind_backfill_done', true, false );
			return;
		}

		$users = get_users(
			[
				'meta_key'   => NJ_Tier_Manager::META_KEY,
				'meta_value' => [ 'pro_managed', 'pro_byok' ],
				'fields'     => 'ID',
				'number'     => 1,
			]
		);
		if ( empty( $users ) ) {
			update_option( 'wp_ai_mind_backfill_done', true, false );
			return;
		}

		$tier = (string) get_user_meta( (int) $users[0], NJ_Tier_Manager::META_KEY, true );
		NJ_Tier_Manager::set_site_tier( $tier );
		update_option( 'wp_ai_mind_backfill_done', true, false );
	}

	/**
	 * Run on plugin deactivation: clear scheduled cron events.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function deactivate(): void {
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

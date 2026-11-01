<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiJobBoard
 */

namespace Dev3\AiJobBoard;

use Dev3\AiJobBoard\Core3\Core\Assets;
use Dev3\AiJobBoard\Core3\Core\Jobs;
use Dev3\AiJobBoard\Core3\Core\Privacy;
use Dev3\AiJobBoard\Core3\Core\Schema;
use Dev3\AiJobBoard\Data\Alerts;
use Dev3\AiJobBoard\Data\Listings;
use Dev3\AiJobBoard\Data\Post_Types;
use Dev3\AiJobBoard\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3jb_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation: tables, roles, post types, default pages, cron, rewrite rules.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3JB_DB_VERSION );
		Post_Types::register();
		Post_Types::add_roles();
		Data\Files::protect_dir();
		Front\Frontend::create_pages();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		flush_rewrite_rules();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( 'dev3jb_license_check' ); // Licence check from builds before licensing was removed.
		flush_rewrite_rules();
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		add_filter( 'dev3jb_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3jb_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3jb_schema_sql', array( Tables::class, 'sql' ), 10, 2 );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( __CLASS__, 'textdomain' ), 0 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3JB_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Ai\Prompts_Library::class, 'register' ), 1 );
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_woo_compat' ) );
		Privacy::init();
		add_action( 'init', array( Privacy_Data::class, 'init' ) );
		Post_Types::init();
		Listings::init();
		Emails\Mailer::init();
		Billing\Checkout::init();
		Front\Frontend::init();
		// Background job types must be registered on every request (the runner looks them up).
		Jobs::register( Ai\Matcher::JOB, array( Ai\Matcher::class, 'run_chunk' ) );
		Jobs::register( Ai\Matcher::INDEX_JOB, array( Ai\Matcher::class, 'index_chunk' ) );
		add_action( 'init', array( __CLASS__, 'admin_assets' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Translations load on init (WP 6.7 warns when they load earlier).
	 */
	public static function textdomain() {
		load_plugin_textdomain( 'dev3-ai-job-board', false, dirname( plugin_basename( DEV3JB_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the admin bundle once.
	 */
	public static function admin_assets() {
		if ( is_admin() && ! wp_script_is( Assets::handle( 'admin' ), 'registered' ) ) {
			Assets::register( array( 'admin' ) );
		}
	}

	/**
	 * Daily housekeeping: usage/cache pruning, job expiry and alert digests.
	 */
	public static function daily() {
		Schema::prune_usage();
		Listings::check_expiry();
		if ( Settings::get( 'alerts_enabled', true ) ) {
			Alerts::send_digests();
		}
	}

	/**
	 * WooCommerce (optional, for package checkout): HPOS + block checkout compatible.
	 */
	public static function declare_woo_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3JB_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3JB_FILE, true );
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
		( new Rest\Public_Controller() )->register_routes();
		( new Rest\Employer_Controller() )->register_routes();
		( new Rest\Candidate_Controller() )->register_routes();
	}
}

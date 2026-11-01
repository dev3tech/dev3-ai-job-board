<?php
/**
 * Test dependencies: WooCommerce (optional package checkout), a global HTTP guard and shared helpers.
 *
 * @package Dev3\AiJobBoard
 */

tests_add_filter(
	'dev3jb_test_dependencies',
	static function ( $deps ) {
		$deps[] = file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php';
		return $deps;
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			define( 'WC_REMOVE_ALL_DATA', true );
			WC_Install::install();
			if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer' ) ) {
				wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
			}
			global $wpdb;
			foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ) as $dev3jb_table ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $dev3jb_table ) ) ) {
					$wpdb->query( "DELETE FROM {$wpdb->prefix}{$dev3jb_table}" ); // phpcs:ignore
				}
			}
			$GLOBALS['wp_roles'] = null; // phpcs:ignore
			wp_roles();
		}
	}
);

// Core job tests assume WP-Cron, not Action Scheduler (bundled with WooCommerce).
tests_add_filter( 'dev3jb_jobs_use_action_scheduler', '__return_false' );
tests_add_filter( 'dev3jb_retry_delay_ms', '__return_zero' );
tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'wordpress@example.org';
	}
);

require_once __DIR__ . '/helpers.php';

// No real network in tests: anything not answered by a test's own mock is refused.
tests_add_filter( 'pre_http_request', array( 'Dev3jb_Http', 'handle' ), 99, 3 );

<?php
/**
 * Plugin Name:       DEV3 AI Job Board & Resume Matcher for WordPress
 * Plugin URI:        https://dev3.tech/item/dev3-ai-job-board
 * Description:       A complete job board with front-end submission, paid packages, employer and candidate dashboards, and DEV3 AI resume parsing and match scores.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-job-board
 * Domain Path:       /languages
 *
 * @package Dev3\AiJobBoard
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3JB_VERSION', '1.0.0' );
define( 'DEV3JB_FILE', __FILE__ );
define( 'DEV3JB_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3JB_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3JB_SLUG', 'dev3-ai-job-board' );
define( 'DEV3JB_NAME', 'DEV3 AI Job Board & Resume Matcher for WordPress' );
define( 'DEV3JB_MIN_PHP', '7.4' );
define( 'DEV3JB_MIN_WP', '6.0' );
define( 'DEV3JB_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3JB_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiJobBoard\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3JB_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiJobBoard\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiJobBoard\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiJobBoard\Plugin', 'instance' ) );

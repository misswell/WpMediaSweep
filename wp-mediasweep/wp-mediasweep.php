<?php
/**
 * Plugin Name:       MediaSweep
 * Plugin URI:        https://github.com/misswell/WpMediaSweep
 * Description:       A local-first WordPress media optimization and cleanup tool. Scan, compress, analyze references and safely clean up unused media — all processed locally, never uploaded to third parties.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            misswell
 * Author URI:        https://github.com/misswell
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mediasweep
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MSW_VERSION', '0.1.0' );
define( 'MSW_PLUGIN_FILE', __FILE__ );
define( 'MSW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MSW_DB_VERSION', '1' );

require_once MSW_PLUGIN_DIR . 'includes/class-logger.php';
require_once MSW_PLUGIN_DIR . 'includes/class-database.php';
require_once MSW_PLUGIN_DIR . 'includes/class-settings.php';
require_once MSW_PLUGIN_DIR . 'includes/class-task-manager.php';
require_once MSW_PLUGIN_DIR . 'engine/octo-engine/class-octo-engine.php';
require_once MSW_PLUGIN_DIR . 'includes/class-scanner.php';
require_once MSW_PLUGIN_DIR . 'includes/class-compressor.php';
require_once MSW_PLUGIN_DIR . 'includes/class-reference-detector.php';
require_once MSW_PLUGIN_DIR . 'includes/class-cleaner.php';
require_once MSW_PLUGIN_DIR . 'includes/class-cron.php';
require_once MSW_PLUGIN_DIR . 'includes/class-plugin.php';
require_once MSW_PLUGIN_DIR . 'admin/class-admin.php';

register_activation_hook( __FILE__, array( 'MSW_Database', 'activate' ) );

/**
 * Boot the plugin.
 */
function msw() {
	return MSW_Plugin::instance();
}

add_action( 'plugins_loaded', 'msw' );

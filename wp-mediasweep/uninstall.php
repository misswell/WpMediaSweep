<?php
/**
 * Uninstall handler. Removes tables and options.
 *
 * Safety: .ms-original backup files and the uploads/ms-trash directory are
 * intentionally KEPT — they are the only way to recover originals after
 * uninstall. Delete them manually if you no longer need them.
 *
 * @package MediaSweep
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Direct table access: the plugin classes are not loaded during uninstall.
global $wpdb;

$tables = array(
	$wpdb->prefix . 'ms_images',
	$wpdb->prefix . 'ms_references',
	$wpdb->prefix . 'ms_tasks',
	$wpdb->prefix . 'ms_logs',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
}

delete_option( 'msw_db_version' );
delete_option( 'msw_activated_at' );
delete_option( 'msw_settings' );
delete_option( 'msw_scan_dirs' );
delete_option( 'msw_theme_files_cache' );
delete_option( 'msw_plugin_files_cache' );

wp_clear_scheduled_hook( 'msw_tick' );
wp_clear_scheduled_hook( 'msw_cleanup_trash' );

<?php
/**
 * One-shot production activator for MediaSweep (runs the activation hook and
 * registers the plugin in active_plugins, idempotent).
 */

require '/home/wwwroot/blog.liuguofeng.com/wp-load.php';

$plugin = 'wp-mediasweep/wp-mediasweep.php';

// Already loaded?
if ( defined( 'MSW_VERSION' ) ) {
	echo "PLUGIN ALREADY LOADED v" . MSW_VERSION . "\n";
} else {
	echo "NOT ACTIVE YET\n";
}

$active = get_option( 'active_plugins', array() );
if ( in_array( $plugin, $active, true ) ) {
	echo "ALREADY IN active_plugins\n";
} else {
	// Run the activation hook (creates tables, schedules cron).
	$result = activate_plugin( $plugin );
	if ( is_wp_error( $result ) ) {
		echo "ACTIVATE_HOOK_ERROR: " . $result->get_error_message() . "\n";
	}
	$active[] = $plugin;
	update_option( 'active_plugins', $active );
	echo "ACTIVATED\n";
}

// Verify: re-check state after activation.
echo "active_plugins contains: " . ( in_array( $plugin, get_option( 'active_plugins', array() ), true ) ? "YES\n" : "NO\n" );
echo "db_version option: " . get_option( 'msw_db_version' ) . "\n";

global $wpdb;
foreach ( array( 'ms_images', 'ms_references', 'ms_tasks', 'ms_logs' ) as $t ) {
	echo $t . ': ' . ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) ) ? 'OK' : 'MISSING' ) . "\n";
}

echo 'tick scheduled: ' . ( wp_next_scheduled( 'msw_tick' ) ? 'YES' : 'NO' ) . "\n";
echo 'purge scheduled: ' . ( wp_next_scheduled( 'msw_cleanup_trash' ) ? 'YES' : 'NO' ) . "\n";
echo 'backup-cleanup scheduled: ' . ( wp_next_scheduled( 'msw_cleanup_backups' ) ? 'YES' : 'NO' ) . "\n";

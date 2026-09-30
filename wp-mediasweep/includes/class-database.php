<?php
/**
 * Database layer: custom tables for image index, references, tasks and logs.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Database {

	const IMAGES     = 'ms_images';
	const REFERENCES = 'ms_references';
	const TASKS      = 'ms_tasks';
	const LOGS       = 'ms_logs';

	/**
	 * Full table name helper.
	 *
	 * @param string $table Short table name.
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Create tables on activation.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$images  = self::table( self::IMAGES );
		$refs    = self::table( self::REFERENCES );
		$tasks   = self::table( self::TASKS );
		$logs    = self::table( self::LOGS );

		$sql = "CREATE TABLE {$images} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			file_path text NOT NULL,
			file_rel_path varchar(500) NOT NULL DEFAULT '',
			file_name varchar(255) NOT NULL DEFAULT '',
			mime_type varchar(100) NOT NULL DEFAULT '',
			width int(11) NOT NULL DEFAULT 0,
			height int(11) NOT NULL DEFAULT 0,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			compressed tinyint(1) NOT NULL DEFAULT 0,
			compressed_at datetime NULL,
			original_size bigint(20) unsigned NOT NULL DEFAULT 0,
			compressed_size bigint(20) unsigned NOT NULL DEFAULT 0,
			compression_ratio float NOT NULL DEFAULT 0,
			reference_status varchar(20) NOT NULL DEFAULT 'unknown',
			reference_count int(11) NOT NULL DEFAULT 0,
			analyzed_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY file_name (file_name(100)),
			KEY compressed (compressed),
			KEY reference_status (reference_status),
			KEY file_rel_path (file_rel_path(191))
		) $charset;
		CREATE TABLE {$refs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			reference_type varchar(40) NOT NULL DEFAULT '',
			reference_id bigint(20) NOT NULL DEFAULT 0,
			source varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY image_id (image_id),
			KEY reference_type (reference_type)
		) $charset;
		CREATE TABLE {$tasks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(40) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'queued',
			total bigint(20) unsigned NOT NULL DEFAULT 0,
			processed bigint(20) unsigned NOT NULL DEFAULT 0,
			failed bigint(20) unsigned NOT NULL DEFAULT 0,
			task_cursor longtext NULL,
			task_options longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY type (type)
		) $charset;
		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(10) NOT NULL DEFAULT 'info',
			context varchar(64) NOT NULL DEFAULT '',
			message text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY created_at (created_at)
		) $charset;";

		dbDelta( $sql );

		update_option( 'msw_db_version', MSW_DB_VERSION );
		update_option( 'msw_activated_at', gmdate( 'Y-m-d H:i:s' ) );

		MSW_Logger::info( 'plugin', 'MediaSweep activated (db version ' . MSW_DB_VERSION . ').' );
	}

	/**
	 * Maybe upgrade tables when db version changes.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'msw_db_version' ) !== MSW_DB_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Drop everything. Only called from uninstall.php.
	 */
	public static function uninstall() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( self::IMAGES ) ); // phpcs:ignore
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( self::REFERENCES ) ); // phpcs:ignore
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( self::TASKS ) ); // phpcs:ignore
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( self::LOGS ) ); // phpcs:ignore

		delete_option( 'msw_db_version' );
		delete_option( 'msw_activated_at' );
		delete_option( 'msw_settings' );
		delete_option( 'msw_scan_dirs' );
		delete_option( 'msw_theme_files_cache' );
		delete_option( 'msw_plugin_files_cache' );

		wp_clear_scheduled_hook( 'msw_tick' );
		wp_clear_scheduled_hook( 'msw_cleanup_trash' );
	}
}

<?php
/**
 * Operation logger. Every state-changing operation gets a row in wp_ms_logs.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Logger {

	/**
	 * Write a log entry.
	 *
	 * @param string $level   info|warn|error.
	 * @param string $context Short context tag, e.g. "compress".
	 * @param string $message Human readable message.
	 * @return void
	 */
	public static function log( $level, $context, $message ) {
		global $wpdb;

		$wpdb->insert(
			MSW_Database::table( MSW_Database::LOGS ),
			array(
				'level'      => substr( $level, 0, 10 ),
				'context'    => substr( $context, 0, 64 ),
				'message'    => $message,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		// Prune occasionally to keep the table bounded.
		if ( 0 === mt_rand( 1, 50 ) ) {
			self::prune();
		}
	}

	public static function info( $context, $message ) {
		self::log( 'info', $context, $message );
	}

	public static function warn( $context, $message ) {
		self::log( 'warn', $context, $message );
	}

	public static function error( $context, $message ) {
		self::log( 'error', $context, $message );
	}

	/**
	 * Keep the newest 5000 entries.
	 */
	public static function prune() {
		global $wpdb;
		$table = MSW_Database::table( MSW_Database::LOGS );
		$keep  = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET 4999" );
		if ( $keep ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", $keep ) ); // phpcs:ignore
		}
	}

	/**
	 * Recent log entries.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		$table = MSW_Database::table( MSW_Database::LOGS );
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", min( 500, max( 1, $limit ) ) ),
			ARRAY_A
		);
	}

	/**
	 * Clear all logs.
	 */
	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . MSW_Database::table( MSW_Database::LOGS ) ); // phpcs:ignore
	}
}

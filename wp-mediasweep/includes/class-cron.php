<?php
/**
 * Cron scheduling: task ticker (every minute) and daily trash purge.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Cron {

	const TICK_HOOK   = 'msw_tick';
	const PURGE_HOOK  = 'msw_cleanup_trash';
	const BACKUP_HOOK = 'msw_cleanup_backups';

	public static function init() {
		add_action( self::TICK_HOOK, array( 'MSW_Task_Manager', 'tick' ) );
		add_action( self::PURGE_HOOK, array( 'MSW_Cleaner', 'purge_expired' ) );
		add_action( self::BACKUP_HOOK, array( 'MSW_Cleaner', 'purge_expired_backups' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );

		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_event( time() + 60, 'minutely', self::TICK_HOOK );
		}
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::PURGE_HOOK );
		}
		if ( ! wp_next_scheduled( self::BACKUP_HOOK ) ) {
			wp_schedule_event( time() + 600, 'daily', self::BACKUP_HOOK );
		}
	}

	/**
	 * Add a "minutely" schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		if ( ! isset( $schedules['minutely'] ) ) {
			$schedules['minutely'] = array(
				'interval' => 60,
				'display'  => 'Once per minute (MediaSweep)',
			);
		}
		return $schedules;
	}

	/**
	 * Remove schedules on deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		wp_clear_scheduled_hook( self::PURGE_HOOK );
	}
}

<?php
/**
 * Async task manager. All heavy work (scan / reference scan / compress) runs as
 * resumable tasks stepped in batches — never loaded in one go.
 *
 * Task row = { type, status, total, processed, failed, cursor(json), options(json) }
 * Handlers must be idempotent and driven by cursor.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Task_Manager {

	/** @var array<string, callable> type => handler( task_row ) : bool more_work */
	protected static $handlers = array();

	/**
	 * Register a task type handler.
	 *
	 * @param string   $type    Task type.
	 * @param callable $handler function( array $task ): bool — true if there is more work left.
	 */
	public static function register( $type, $handler ) {
		self::$handlers[ $type ] = $handler;
	}

	/**
	 * Create a task.
	 *
	 * @param string $type    Task type.
	 * @param array  $options Task options (stored as JSON).
	 * @param int    $total   Expected total items (0 = unknown yet).
	 * @return array Task row.
	 */
	public static function create( $type, $options = array(), $total = 0 ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			MSW_Database::table( MSW_Database::TASKS ),
			array(
				'type'         => $type,
				'status'       => 'queued',
				'total'        => $total,
				'processed'    => 0,
				'failed'       => 0,
				'task_cursor'  => wp_json_encode( array() ),
				'task_options' => wp_json_encode( $options ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		$task = self::get( $wpdb->insert_id );
		MSW_Logger::info( 'task', sprintf( 'Task #%d created (%s).', $task['id'], $type ) );

		return $task;
	}

	/**
	 * Fetch one task as array.
	 *
	 * @param int $id Task id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MSW_Database::table( MSW_Database::TASKS ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Cast JSON columns.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	protected static function hydrate( $row ) {
		$row['cursor']  = json_decode( (string) ( $row['task_cursor'] ?? '' ), true );
		$row['options'] = json_decode( (string) ( $row['task_options'] ?? '' ), true );
		if ( ! is_array( $row['cursor'] ) ) {
			$row['cursor'] = array();
		}
		if ( ! is_array( $row['options'] ) ) {
			$row['options'] = array();
		}
		return $row;
	}

	/**
	 * List tasks.
	 *
	 * @param int $limit Max rows.
	 * @return array[]
	 */
	public static function all( $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . MSW_Database::table( MSW_Database::TASKS ) . ' ORDER BY id DESC LIMIT %d', min( 200, max( 1, $limit ) ) ),
			ARRAY_A
		);
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Currently active (queued/running) task, oldest first.
	 *
	 * @param string|null $type Limit to type.
	 * @return array|null
	 */
	public static function active( $type = null ) {
		global $wpdb;
		$table = MSW_Database::table( MSW_Database::TASKS );
		$sql   = "SELECT * FROM {$table} WHERE status IN ('queued','running')";
		if ( $type ) {
			$sql .= $wpdb->prepare( ' AND type = %s', $type );
		}
		$sql .= ' ORDER BY id ASC LIMIT 1';
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Update scalar fields.
	 *
	 * @param int   $id     Task id.
	 * @param array $fields status|total|processed|failed.
	 */
	public static function update( $id, $fields ) {
		global $wpdb;

		$allowed = array_intersect_key(
			$fields,
			array_flip( array( 'status', 'total', 'processed', 'failed' ) )
		);
		if ( ! $allowed ) {
			return;
		}
		$allowed['updated_at'] = current_time( 'mysql', true );

		$format = array();
		foreach ( $allowed as $key => $value ) {
			$format[] = 'updated_at' === $key ? '%s' : '%s';
		}
		$wpdb->update( MSW_Database::table( MSW_Database::TASKS ), $allowed, array( 'id' => $id ), $format, array( '%d' ) );
	}

	/**
	 * Persist cursor (JSON array).
	 *
	 * @param int   $id     Task id.
	 * @param array $cursor Cursor data.
	 */
	public static function set_cursor( $id, $cursor ) {
		global $wpdb;
		$wpdb->update(
			MSW_Database::table( MSW_Database::TASKS ),
			array(
				'task_cursor' => wp_json_encode( is_array( $cursor ) ? $cursor : array() ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function pause( $id ) {
		$task = self::get( $id );
		if ( $task && in_array( $task['status'], array( 'queued', 'running' ), true ) ) {
			self::update( $id, array( 'status' => 'paused' ) );
			MSW_Logger::info( 'task', sprintf( 'Task #%d paused.', $id ) );
		}
	}

	public static function resume( $id ) {
		$task = self::get( $id );
		if ( $task && 'paused' === $task['status'] ) {
			self::update( $id, array( 'status' => 'queued' ) );
			MSW_Logger::info( 'task', sprintf( 'Task #%d resumed.', $id ) );
		}
	}

	public static function cancel( $id ) {
		$task = self::get( $id );
		if ( $task && in_array( $task['status'], array( 'queued', 'running', 'paused' ), true ) ) {
			self::update( $id, array( 'status' => 'cancelled' ) );
			MSW_Logger::info( 'task', sprintf( 'Task #%d cancelled.', $id ) );
		}
	}

	/**
	 * Advance the oldest active task one step. Called by cron + REST tick.
	 *
	 * Steps are time-boxed (settings.time_budget) so a step never times out PHP.
	 *
	 * @return bool True if a task ran and may still have work.
	 */
	public static function tick() {
		// Recover stale running tasks (crashed mid-step): requeue, handlers are idempotent.
		self::recover_stale();

		$task = self::active();
		if ( ! $task ) {
			return false;
		}

		if ( ! isset( self::$handlers[ $task['type'] ] ) || ! is_callable( self::$handlers[ $task['type'] ] ) ) {
			self::update( $task['id'], array( 'status' => 'failed' ) );
			MSW_Logger::error( 'task', sprintf( 'Task #%d has no handler for type "%s".', $task['id'], $task['type'] ) );
			return false;
		}

		self::update( $task['id'], array( 'status' => 'running' ) );

		$budget = (int) MSW_Settings::get( 'time_budget', 20 );
		$start  = microtime( true );

		try {
			$more = call_user_func( self::$handlers[ $task['type'] ], $task, $budget );
		} catch ( Exception $e ) {
			MSW_Logger::error( 'task', sprintf( 'Task #%d crashed: %s', $task['id'], $e->getMessage() ) );
			self::update( $task['id'], array( 'status' => 'queued' ) );
			return false;
		}

		$task = self::get( $task['id'] );

		if ( false === $more ) {
			$status = 'completed';
			if ( $task && $task['failed'] > 0 && 0 === $task['processed'] ) {
				$status = 'failed';
			}
			self::update( $task['id'], array( 'status' => $status ) );
			MSW_Logger::info(
				'task',
				sprintf( 'Task #%d (%s) finished: %d processed, %d failed.', $task['id'], $task['type'], $task['processed'], $task['failed'] )
			);
			return false;
		}

		// Pause check: user may have paused while the handler was running.
		$fresh = self::get( $task['id'] );
		if ( $fresh && 'paused' === $fresh['status'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Requeue running tasks untouched for 10 minutes (crash recovery).
	 */
	protected static function recover_stale() {
		global $wpdb;
		$table = MSW_Database::table( MSW_Database::TASKS );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$stale  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'running' AND updated_at < %s", $cutoff ),
			ARRAY_A
		);
		foreach ( (array) $stale as $row ) {
			self::update( (int) $row['id'], array( 'status' => 'queued' ) );
			MSW_Logger::warn( 'task', sprintf( 'Task #%d was stale and requeued.', (int) $row['id'] ) );
		}
	}

	/**
	 * Run a loop of ticks within one request (used by REST tick endpoint).
	 *
	 * @param int $max_seconds Wall-clock budget.
	 * @return array Summary.
	 */
	public static function run_loop( $max_seconds = 25 ) {
		$start  = microtime( true );
		$steps  = 0;

		while ( microtime( true ) - $start < $max_seconds ) {
			if ( ! self::tick() ) {
				break;
			}
			$steps++;
		}

		$task   = self::active();
		$latest = self::all( 1 );

		return array(
			'steps'      => $steps,
			'elapsed'    => round( microtime( true ) - $start, 2 ),
			'active'     => $task,
			'latest'     => $latest ? $latest[0] : null,
		);
	}
}

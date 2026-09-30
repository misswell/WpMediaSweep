<?php
/**
 * REST: task control + tick loop.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Rest_Tasks {

	/**
	 * GET /tasks
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_tasks( $request ) {
		return rest_ensure_response(
			array( 'tasks' => MSW_Task_Manager::all( (int) $request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 30 ) )
		);
	}

	/**
	 * POST /tasks/{id}/{pause|resume|cancel}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function control( $request ) {
		$id     = (int) $request['id'];
		$action = (string) $request['action'];

		$task = MSW_Task_Manager::get( $id );
		if ( ! $task ) {
			return new WP_Error( 'msw_rest', 'Task not found.', array( 'status' => 404 ) );
		}

		if ( 'pause' === $action ) {
			MSW_Task_Manager::pause( $id );
		} elseif ( 'resume' === $action ) {
			MSW_Task_Manager::resume( $id );
		} else {
			MSW_Task_Manager::cancel( $id );
		}

		return rest_ensure_response( array( 'task' => MSW_Task_Manager::get( $id ) ) );
	}

	/**
	 * POST /tick — drive task steps from the UI (works even when WP-Cron
	 * is disabled). Loops for ~20s, then reports state; the UI re-ticks.
	 *
	 * @return WP_REST_Response
	 */
	public static function tick() {
		$summary = MSW_Task_Manager::run_loop( 20 );

		if ( ! empty( $summary['latest'] ) && is_array( $summary['latest'] ) ) {
			unset( $summary['latest']['cursor'], $summary['latest']['options'] );
		}
		if ( ! empty( $summary['active'] ) && is_array( $summary['active'] ) ) {
			unset( $summary['active']['cursor'], $summary['active']['options'] );
		}

		return rest_ensure_response( $summary );
	}
}

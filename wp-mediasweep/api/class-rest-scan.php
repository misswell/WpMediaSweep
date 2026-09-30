<?php
/**
 * REST: scan / reference-scan task control.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Rest_Scan {

	/**
	 * POST /scan — start (or report the active) media scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function start_scan( $request ) {
		$task = MSW_Scanner::start();

		// Give the loop an immediate kick so progress starts now.
		MSW_Task_Manager::tick();

		return rest_ensure_response( array( 'task' => $task ) );
	}

	/**
	 * POST /scan/references — start (or report the active) reference scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function start_reference_scan( $request ) {
		$task = MSW_Reference_Detector::start();

		MSW_Task_Manager::tick();

		return rest_ensure_response( array( 'task' => $task ) );
	}
}

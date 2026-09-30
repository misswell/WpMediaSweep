<?php
/**
 * REST: settings + logs.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Rest_Settings {

	/**
	 * GET /settings
	 *
	 * @return WP_REST_Response
	 */
	public static function get() {
		$settings = MSW_Settings::all();
		$settings['backends'] = array(
			'imagick' => MSW_Backend_Imagick::available(),
			'gd'      => MSW_Backend_GD::available(),
		);
		return rest_ensure_response( array( 'settings' => $settings ) );
	}

	/**
	 * POST /settings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function update( $request ) {
		$updated = MSW_Settings::update( (array) $request->get_json_params() );
		return rest_ensure_response( array( 'settings' => $updated ) );
	}

	/**
	 * GET /logs
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function logs( $request ) {
		return rest_ensure_response(
			array( 'logs' => MSW_Logger::recent( (int) $request->get_param( 'limit' ) ) )
		);
	}

	/**
	 * DELETE /logs
	 *
	 * @return WP_REST_Response
	 */
	public static function clear_logs() {
		MSW_Logger::clear();
		return rest_ensure_response( array( 'cleared' => true ) );
	}
}

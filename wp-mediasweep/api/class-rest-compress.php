<?php
/**
 * REST: compression endpoints.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Rest_Compress {

	/**
	 * POST /compress
	 *  { id: 123 }              — compress one image synchronously
	 *  { ids: [1,2,3] }         — batch task for selected images
	 *  { all: true }            — batch task for every uncompressed image
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function compress( $request ) {
		$id    = (int) $request->get_param( 'id' );
		$ids   = (array) $request->get_param( 'ids' );
		$force = (bool) $request->get_param( 'force' );

		if ( $id ) {
			$result = MSW_Compressor::compress_image( $id, $force );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( array( 'result' => $result ) );
		}

		global $wpdb;
		$table   = MSW_Database::table( MSW_Database::IMAGES );
		$options = array( 'all' => 1 );
		$total   = 0;

		if ( $ids ) {
			$ids            = array_values( array_filter( array_map( 'intval', $ids ) ) );
			$options['ids'] = $ids;
			unset( $options['all'] );
			$total = count( $ids );
		} else {
			// No ids given: compress every uncompressed image.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE compressed = 0" ); // phpcs:ignore
		}

		if ( ! $total ) {
			return rest_ensure_response(
				array(
					'task'  => null,
					'note'  => 'Nothing to compress.',
				)
			);
		}

		$task = MSW_Task_Manager::create( 'compress', $options, $total );
		MSW_Task_Manager::tick();

		return rest_ensure_response( array( 'task' => $task, 'total' => $total ) );
	}
}

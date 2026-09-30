<?php
/**
 * REST: image listing, references, restore, trash.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Rest_Images {

	/**
	 * GET /images — paginated index with filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function list_images( $request ) {
		global $wpdb;

		$table   = MSW_Database::table( MSW_Database::IMAGES );
		$where   = array( '1=1' );
		$prepare = array();

		$status = $request->get_param( 'status' );
		if ( $status && 'all' !== $status ) {
			$where[]   = 'reference_status = %s';
			$prepare[] = $status;
		}

		$compressed = $request->get_param( 'compressed' );
		if ( null !== $compressed && '' !== $compressed && 'all' !== $compressed ) {
			$where[]   = 'compressed = %d';
			$prepare[] = (int) $compressed;
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			$where[]   = '( file_name LIKE %s OR file_rel_path LIKE %s )';
			$prepare[] = '%' . $wpdb->esc_like( $search ) . '%';
			$prepare[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// Size variants are hidden by default; they are managed through their parent.
		$thumbs = $request->get_param( 'thumbnails' );
		if ( 'all' !== $thumbs ) {
			$where[] = 'is_thumbnail = 0';
		}

		$where_sql = implode( ' AND ', $where );
		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$per_page  = min( 100, max( 10, (int) $request->get_param( 'per_page' ) ) );
		$offset    = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $prepare ) // phpcs:ignore
		);

		$prepare[] = $per_page;
		$prepare[] = $offset;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + static where fragments.
				"SELECT id, attachment_id, file_name, file_rel_path, mime_type, width, height, file_size,
						md5_hash, is_thumbnail, parent_file_id,
						compressed, compressed_at, original_size, compressed_size, compression_ratio,
						reference_status, reference_count
				FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				$prepare
			),
			ARRAY_A
		);

		foreach ( (array) $rows as &$row ) {
			$row['id']                = (int) $row['id'];
			$row['attachment_id']     = (int) $row['attachment_id'];
			$row['width']             = (int) $row['width'];
			$row['height']            = (int) $row['height'];
			$row['file_size']         = (int) $row['file_size'];
			$row['is_thumbnail']      = (int) $row['is_thumbnail'];
			$row['parent_file_id']    = (int) $row['parent_file_id'];
			$row['compressed']        = (int) $row['compressed'];
			$row['original_size']     = (int) $row['original_size'];
			$row['compressed_size']   = (int) $row['compressed_size'];
			$row['compression_ratio'] = (float) $row['compression_ratio'];
			$row['reference_count']   = (int) $row['reference_count'];
			$row['risk_level']        = self::risk_level( $row );
			$row['thumbnail_url']     = self::thumbnail_url( $row );
			$row['edit_url']          = $row['attachment_id']
				? admin_url( 'post.php?post=' . $row['attachment_id'] . '&action=edit' )
				: '';
		}

		return rest_ensure_response(
			array(
				'items'    => array_values( (array) $rows ),
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Deletion risk grade for one indexed file.
	 *
	 *   keep      — referenced (red: do not delete)
	 *   cautious  — no references found, but an attachment/size variant exists
	 *   safe      — no attachment record at all (orphan file on disk)
	 *
	 * @param array $row Image row.
	 * @return string
	 */
	public static function risk_level( $row ) {
		if ( ! empty( $row['is_thumbnail'] ) ) {
			return 'follow'; // Managed through the parent image.
		}

		switch ( $row['reference_status'] ) {
			case 'used':
				return 'keep';
			case 'maybe':
			case 'unused':
				return 'cautious'; // Attachment exists but is not referenced (may be used outside scans).
			case 'orphan':
				return 'safe'; // No media library record; safe once reviewed.
		}
		return 'cautious';
	}

	/**
	 * Thumbnail URL for the media grid (uses the native attachment API).
	 *
	 * @param array $row Image row.
	 * @return string
	 */
	protected static function thumbnail_url( $row ) {
		if ( ! empty( $row['attachment_id'] ) ) {
			$src = wp_get_attachment_image_src( (int) $row['attachment_id'], array( 150, 150 ) );
			if ( $src ) {
				return $src[0];
			}
		}
		return '';
	}

	/**
	 * GET /images/{id}/references
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function references( $request ) {
		global $wpdb;

		$image_id = (int) $request['id'];
		$refs     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . MSW_Database::table( MSW_Database::REFERENCES ) . ' WHERE image_id = %d ORDER BY reference_type, id',
				$image_id
			),
			ARRAY_A
		);

		foreach ( (array) $refs as &$ref ) {
			$ref['reference_id'] = (int) $ref['reference_id'];
			$ref['image_id']     = (int) $ref['image_id'];
			$ref['label']        = self::reference_label( $ref );
			if ( $ref['reference_id'] ) {
				$ref['url'] = get_edit_post_link( $ref['reference_id'], 'raw' );
			} else {
				$ref['url'] = '';
			}
		}

		return rest_ensure_response( array( 'references' => array_values( (array) $refs ) ) );
	}

	/**
	 * POST /images/{id}/analyze — rescan references for one image.
	 */
	public static function analyze( $request ) {
		$result = MSW_Reference_Detector::analyze_image( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'status'     => $result['status'],
				'references' => $result['references'],
			)
		);
	}

	/**
	 * POST /images/{id}/restore — bring back the original bytes.
	 */
	public static function restore( $request ) {
		$result = MSW_Compressor::restore_image( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'restored' => true ) );
	}

	/**
	 * POST /delete — move selected images to the trash.
	 */
	public static function trash( $request ) {
		$ids = (array) $request->get_param( 'ids' );
		if ( ! $ids ) {
			return new WP_Error( 'msw_rest', 'No image ids given.' );
		}

		$summary = MSW_Cleaner::trash_images( $ids );
		return rest_ensure_response( $summary );
	}

	/**
	 * GET /trash
	 */
	public static function trash_list() {
		return rest_ensure_response( array( 'entries' => MSW_Cleaner::list_trash() ) );
	}

	/**
	 * POST /trash/{token}/restore
	 */
	public static function trash_restore( $request ) {
		$result = MSW_Cleaner::restore_trashed( (string) $request['token'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'restored' => true ) );
	}

	/**
	 * Human label for a reference row.
	 *
	 * @param array $ref Reference row.
	 * @return string
	 */
	protected static function reference_label( $ref ) {
		switch ( $ref['reference_type'] ) {
			case 'post_content':
			case 'gutenberg':
			case 'post_content_maybe':
				$title = $ref['source'];
				if ( $ref['reference_id'] ) {
					$post = get_post( $ref['reference_id'] );
					if ( $post ) {
						$title = $post->post_title ? $post->post_title : sprintf( '#%d (%s)', $post->ID, $post->post_type );
					}
				}
				return $title;
			case 'featured':
				return sprintf( 'Featured image for post #%d', $ref['reference_id'] );
			case 'woocommerce':
				return sprintf( 'WooCommerce gallery of product #%d', $ref['reference_id'] );
			case 'elementor':
				return sprintf( 'Elementor page #%d', $ref['reference_id'] );
			case 'theme':
				return 'Theme file: ' . $ref['source'];
			case 'plugin':
				return 'Plugin file: ' . $ref['source'];
		}
		return (string) $ref['source'];
	}
}

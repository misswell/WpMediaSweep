<?php
/**
 * Compression pipeline: backup -> compress -> validate -> replace.
 *
 * Safety rules:
 *  - originals are backed up next to the file as "<name>.ms-original" before any write
 *  - replacement only happens when the engine accepted the output (valid image,
 *    same pixel size, strictly smaller)
 *  - restore swaps the backup back at any time
 *  - native attachment rows are never modified (pixel size is unchanged)
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Compressor {

	const BACKUP_SUFFIX = '.ms-original';

	/**
	 * Register the batch task handler.
	 */
	public static function register_handler() {
		MSW_Task_Manager::register( 'compress', array( __CLASS__, 'run_task' ) );
	}

	/**
	 * Compress one indexed image now (synchronous, used by REST single actions).
	 *
	 * @param int  $image_id wp_ms_images id.
	 * @param bool $force    Recompress even if marked compressed.
	 * @return array|WP_Error Result summary.
	 */
	public static function compress_image( $image_id, $force = false ) {
		return MSW_Files::with_lock( 'image-' . (int) $image_id, function () use ( $image_id, $force ) {
			return self::compress_image_locked( $image_id, $force );
		} );
	}

	protected static function compress_image_locked( $image_id, $force ) {
		global $wpdb;

		$image = self::get_image( $image_id );
		if ( ! $image ) {
			return new WP_Error( 'msw_compress', 'Image not found in index.' );
		}

		if ( 'active' !== $image['status'] ) {
			return new WP_Error( 'msw_compress', 'Image is not active (trashed or removed).' );
		}

		if ( $image['compressed'] && ! $force ) {
			return new WP_Error( 'msw_already_compressed', 'Image already compressed.' );
		}

		$path = $image['file_path'];
		if ( ! is_file( $path ) || ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) {
			return new WP_Error( 'msw_compress', 'File missing or not writable: ' . $path );
		}
		if ( ! MSW_Files::within_uploads( $path ) ) {
			return new WP_Error( 'msw_compress', 'Refusing to touch a file outside the uploads directory.' );
		}

		$options = array(
			'jpeg_quality' => (int) MSW_Settings::get( 'jpeg_quality', 85 ),
			'webp_quality' => (int) MSW_Settings::get( 'webp_quality', 80 ),
			'avif_quality' => (int) MSW_Settings::get( 'avif_quality', 65 ),
			'png_lossless' => (bool) MSW_Settings::get( 'png_lossless', true ),
		);

		$original_size = (int) filesize( $path );

		// 1. Backup (restore point until the engine result is accepted).
		$backup = $path . self::BACKUP_SUFFIX;
		if ( MSW_Settings::get( 'keep_originals', true ) ) {
			if ( ! MSW_Files::within_uploads( $backup ) || is_link( $backup ) ) {
				return new WP_Error( 'msw_compress', 'Unsafe backup path; compression aborted.' );
			}
			if ( file_exists( $backup ) ) {
				if ( ! is_file( $backup ) || ! is_readable( $backup ) || filesize( $backup ) <= 0 ) {
					return new WP_Error( 'msw_compress', 'Existing backup is unreadable or empty: ' . $backup );
				}
			} elseif ( ! @copy( $path, $backup ) ) {
				@unlink( $backup );
				return new WP_Error( 'msw_compress', 'Cannot create backup file. Check directory permissions and available disk space: ' . $backup );
			}
		}

		// 2. Compress to temp output.
		$result = MSW_Octo_Engine::compress( $path, $options );

		if ( empty( $result['success'] ) ) {
			// Nothing was replaced: original untouched, remove the engine temp file.
			if ( ! empty( $result['output_file'] ) && is_file( $result['output_file'] ) ) {
				@unlink( $result['output_file'] );
			}
			MSW_Logger::warn( 'compress', sprintf( 'Compress failed for #%d (%s): %s', $image_id, $image['file_name'], $result['error'] ) );

			return new WP_Error( 'msw_compress', $result['error'] ? $result['error'] : 'Engine failed.' );
		}

		// 3. Replace original with the accepted temp output.
		if ( ! MSW_Files::within_uploads( $result['output_file'] ) ) {
			@unlink( $result['output_file'] );
			return new WP_Error( 'msw_compress', 'Engine output escaped the uploads directory; aborted.' );
		}
		if ( ! @rename( $result['output_file'], $path ) ) {
			if ( is_file( $result['output_file'] ) ) {
				@unlink( $result['output_file'] );
			}
			MSW_Logger::error( 'compress', sprintf( 'Cannot replace file for #%d.', $image_id ) );
			return new WP_Error( 'msw_compress', 'Cannot replace original file.' );
		}
		clearstatcache( true, $path );
		@chmod( $path, fileperms( $path ) & 0666 | 0644 );

		// 4. Bookkeeping.
		$compressed_size = (int) $result['compressed_size'];
		$ratio           = $original_size > 0 ? round( ( 1 - $compressed_size / $original_size ) * 100, 1 ) : 0;

		$wpdb->update(
			MSW_Database::table( MSW_Database::IMAGES ),
			array(
				'compressed'       => 1,
				'compressed_at'    => current_time( 'mysql', true ),
				'original_size'    => $original_size,
				'compressed_size'  => $compressed_size,
				'compression_ratio'=> $ratio,
				'file_size'        => $compressed_size,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => $image_id ),
			array( '%d', '%s', '%d', '%d', '%f', '%d', '%s' ),
			array( '%d' )
		);

		MSW_Logger::info(
			'compress',
			sprintf(
				'Compressed #%d %s via %s: %s -> %s (%.1f%% saved).',
				$image_id,
				$image['file_name'],
				$result['backend'],
				size_format( $original_size ),
				size_format( $compressed_size ),
				$ratio
			)
		);

		return array(
			'success'         => true,
			'image_id'        => $image_id,
			'original_size'   => $original_size,
			'compressed_size' => $compressed_size,
			'ratio'           => $ratio,
			'backend'         => $result['backend'],
		);
	}

	/**
	 * Restore the backup of one image.
	 *
	 * @param int $image_id wp_ms_images id.
	 * @return true|WP_Error
	 */
	public static function restore_image( $image_id ) {
		return MSW_Files::with_lock( 'image-' . (int) $image_id, function () use ( $image_id ) {
			return self::restore_image_locked( $image_id );
		} );
	}

	protected static function restore_image_locked( $image_id ) {
		global $wpdb;

		$image = self::get_image( $image_id );
		if ( ! $image ) {
			return new WP_Error( 'msw_restore', 'Image not found in index.' );
		}

		$backup = $image['file_path'] . self::BACKUP_SUFFIX;
		if ( ! MSW_Files::within_uploads( $backup ) || ! MSW_Files::within_uploads( $image['file_path'] ) ) {
			return new WP_Error( 'msw_restore', 'Refusing to touch a file outside the uploads directory.' );
		}
		if ( ! is_file( $backup ) ) {
			return new WP_Error( 'msw_restore', 'No backup file found.' );
		}
		$target = $image['file_path'];
		$tmp = dirname( $target ) . '/.ms-restore-' . uniqid( '', true );
		if ( ! MSW_Files::within_uploads( $tmp ) || ! @copy( $backup, $tmp ) ) {
			@unlink( $tmp );
			return new WP_Error( 'msw_restore', 'Cannot restore original file.' );
		}
		if ( ! @rename( $tmp, $target ) ) {
			@unlink( $tmp );
			return new WP_Error( 'msw_restore', 'Cannot replace file during restore; backup retained.' );
		}
		@chmod( $target, 0644 );
		@unlink( $backup );
		clearstatcache( true, $target );

		$size = (int) filesize( $image['file_path'] );
		$wpdb->update(
			MSW_Database::table( MSW_Database::IMAGES ),
			array(
				'compressed'        => 0,
				'compressed_at'     => null,
				'original_size'     => 0,
				'compressed_size'   => 0,
				'compression_ratio' => 0,
				'file_size'         => $size,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $image_id ),
			array( '%d', '%s', '%d', '%d', '%f', '%d', '%s' ),
			array( '%d' )
		);

		MSW_Logger::info( 'restore', sprintf( 'Restored original for #%d (%s).', $image_id, $image['file_name'] ) );

		return true;
	}

	/**
	 * Batch compress task: walk uncompressed images by id, oldest first.
	 *
	 * @param array $task   Task row.
	 * @param int   $budget Seconds for this tick.
	 * @return bool True while work remains.
	 */
	public static function run_task( $task, $budget ) {
		global $wpdb;

		$cursor = wp_parse_args(
			is_array( $task['cursor'] ) ? $task['cursor'] : array(),
			array( 'last_id' => 0, 'selection_offset' => 0, 'processed' => 0, 'failed' => 0, 'saved' => 0 )
		);

		$table = MSW_Database::table( MSW_Database::IMAGES );
		$batch = (int) MSW_Settings::get( 'batch_size', 200 );
		$start = microtime( true );
		$ids   = array();

		// Optional id subset (user selection); otherwise every uncompressed image.
		$selected = isset( $task['options']['ids'] ) && is_array( $task['options']['ids'] )
			? array_map( 'intval', $task['options']['ids'] )
			: null;
		if ( null !== $selected ) {
			$selected = array_slice( $selected, (int) $cursor['selection_offset'] );
		}

		while ( microtime( true ) - $start < $budget ) {
			if ( null !== $selected ) {
				$ids = array_splice( $selected, 0, $batch );
				if ( ! $ids ) {
					break;
				}
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, file_name FROM {$table} WHERE id IN ({$placeholders}) AND compressed = 0 AND is_thumbnail = 0 AND status = 'active'", ...$ids ), // phpcs:ignore
					ARRAY_A
				);
			} else {
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, file_name FROM {$table} WHERE compressed = 0 AND is_thumbnail = 0 AND status = 'active' AND id > %d ORDER BY id ASC LIMIT %d", $cursor['last_id'], $batch ),
					ARRAY_A
				);
			}

			if ( ! $rows ) {
				if ( null !== $selected ) {
					$cursor['selection_offset'] += count( $ids );
					MSW_Task_Manager::set_cursor( $task['id'], $cursor );
					continue;
				}
				break;
			}

			foreach ( $rows as $row ) {
				$result = self::compress_image( (int) $row['id'] );
				$cursor['last_id'] = (int) $row['id'];

				if ( is_wp_error( $result ) ) {
					// "Already compressed" is not a failure; count it as processed.
					if ( 'msw_already_compressed' === $result->get_error_code() ) {
						$cursor['processed']++;
					} else {
						$cursor['failed']++;
					}
				} else {
					$cursor['processed']++;
					$cursor['saved'] += (int) $result['original_size'] - (int) $result['compressed_size'];
				}

				MSW_Task_Manager::update(
					$task['id'],
					array(
						'processed' => $cursor['processed'],
						'failed'    => $cursor['failed'],
					)
				);
				MSW_Task_Manager::set_cursor( $task['id'], $cursor );
			}
			if ( null !== $selected ) {
				$cursor['selection_offset'] += count( $ids );
				MSW_Task_Manager::set_cursor( $task['id'], $cursor );
			}

			// Keep memory tight when a selection array was provided.
			if ( null !== $selected && empty( $selected ) ) {
				break;
			}
			if ( null !== $selected ) {
				continue; // Keep consuming the selection list.
			}
			if ( count( $rows ) < $batch ) {
				break;
			}
		}

		if ( null !== $selected && empty( $selected ) ) {
			MSW_Logger::info(
				'compress',
				sprintf( 'Batch compress finished: %d processed, %d failed, %s saved.', $cursor['processed'], $cursor['failed'], size_format( $cursor['saved'] ) )
			);
			return false;
		}

		if ( null === $selected ) {
			$remaining = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE compressed = 0 AND is_thumbnail = 0 AND status = 'active' AND id > %d", $cursor['last_id'] )
			);
			if ( 0 === $remaining ) {
				MSW_Logger::info(
					'compress',
					sprintf( 'Batch compress finished: %d processed, %d failed, %s saved.', $cursor['processed'], $cursor['failed'], size_format( $cursor['saved'] ) )
				);
				return false;
			}
		}

		return true;
	}

	/**
	 * One indexed image row.
	 *
	 * @param int $image_id Image id.
	 * @return array|null
	 */
	protected static function get_image( $image_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MSW_Database::table( MSW_Database::IMAGES ) . ' WHERE id = %d', $image_id ),
			ARRAY_A
		);
	}
}

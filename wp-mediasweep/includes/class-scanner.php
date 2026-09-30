<?php
/**
 * Media scanner. Indexes every image under wp-content/uploads in batches,
 * matching files to attachments by their relative uploads path.
 *
 * Task phases (driven by cursor, always resumable):
 *   scan  — walk upload dirs, one file row per image (attachment_id = 0)
 *   match — page through wp_postmeta._wp_attached_file by meta_id and set
 *           attachment_id where meta_value equals the file's relative path
 *           (exact match — no per-file LIKE scans)
 *
 * Step functions return true = "work continues", false = "phase/task finished".
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Scanner {

	const IMAGE_EXTS    = array( 'jpg', 'jpeg', 'png', 'webp', 'avif' );
	const THUMB_PATTERN = '/-\d+x\d+\.(jpg|jpeg|png|webp|avif)$/i';
	const MAX_DIRS      = 10000;

	/**
	 * Start (or resume) a scan task.
	 *
	 * @return array Task row.
	 */
	public static function start() {
		$task = MSW_Task_Manager::active( 'scan' );
		if ( $task ) {
			return $task;
		}

		$task = MSW_Task_Manager::create(
			'scan',
			array(
				'root' => self::uploads_basedir(),
			)
		);

		MSW_Logger::info( 'scan', 'Scan task started.' );

		return $task;
	}

	/**
	 * Register the task handler.
	 */
	public static function register_handler() {
		MSW_Task_Manager::register( 'scan', array( __CLASS__, 'run_task' ) );
	}

	/**
	 * Task handler. Returns true while there is work left.
	 *
	 * @param array $task   Task row.
	 * @param int   $budget Seconds for this tick.
	 * @return bool
	 */
	public static function run_task( $task, $budget ) {
		$root = isset( $task['options']['root'] ) ? $task['options']['root'] : self::uploads_basedir();
		if ( ! $root || ! is_dir( $root ) ) {
			MSW_Logger::error( 'scan', 'Uploads directory not found.' );
			return false;
		}

		$cursor = wp_parse_args(
			is_array( $task['cursor'] ) ? $task['cursor'] : array(),
			array(
				'phase'         => 'scan',
				'dirs'          => array(),
				'dir_index'     => 0,
				'offset'        => 0,
				'files_done'    => 0,
				'skipped'       => 0,
				'thumb_skipped' => 0,
				'last_meta_id'  => 0,
				'matched'       => 0,
			)
		);

		// Phase bootstrap: enumerate directories once (cheap, far fewer than files).
		if ( 'scan' === $cursor['phase'] && empty( $cursor['dirs'] ) ) {
			$cursor['dirs'] = self::collect_dirs( $root );
			MSW_Logger::info( 'scan', sprintf( 'Found %d directories under uploads.', count( $cursor['dirs'] ) ) );
		}

		$batch = (int) MSW_Settings::get( 'batch_size', 200 );
		$start = microtime( true );

		while ( microtime( true ) - $start < $budget ) {
			if ( 'scan' === $cursor['phase'] ) {
				$more = self::scan_step( $cursor, $root, $batch );

				MSW_Task_Manager::set_cursor( $task['id'], $cursor );
				MSW_Task_Manager::update( $task['id'], array( 'processed' => $cursor['files_done'] ) );

				if ( ! $more ) {
					// scan_step switched the phase to "match"; keep going this tick.
					continue;
				}
			} else {
				$more = self::match_step( $cursor, $batch );

				MSW_Task_Manager::set_cursor( $task['id'], $cursor );
				MSW_Task_Manager::update( $task['id'], array( 'processed' => $cursor['matched'] ) );

				if ( ! $more ) {
					MSW_Logger::info(
						'scan',
						sprintf(
							'Scan complete: %d files indexed, %d attachments matched, %d thumbnails skipped.',
							$cursor['files_done'],
							$cursor['matched'],
							$cursor['thumb_skipped']
						)
					);
					return false; // Task finished.
				}
			}
		}

		return true; // Time budget spent, next tick resumes from cursor.
	}

	/**
	 * Process one batch of files in the current directory.
	 *
	 * @param array  $cursor Cursor (updated in place).
	 * @param string $root   Uploads basedir.
	 * @param int    $batch  Batch size.
	 * @return bool True while the scan phase continues.
	 */
	protected static function scan_step( &$cursor, $root, $batch ) {
		if ( $cursor['dir_index'] >= count( $cursor['dirs'] ) ) {
			$cursor['phase']  = 'match';
			$cursor['offset'] = 0;
			MSW_Logger::info( 'scan', sprintf( 'File walk done (%d files). Matching attachments…', $cursor['files_done'] ) );
			return false;
		}

		$dir  = $cursor['dirs'][ $cursor['dir_index'] ];
		$rels = self::list_images_in_dir( $dir, $root, $cursor );

		$files = array_slice( $rels, $cursor['offset'], $batch );
		$end   = $cursor['offset'] + count( $files );

		if ( $files ) {
			self::index_files( $files, $root, $cursor );
		}

		if ( $end >= count( $rels ) ) {
			$cursor['dir_index']++;
			$cursor['offset'] = 0;
		} else {
			$cursor['offset'] = $end;
		}

		return true;
	}

	/**
	 * Match one page of attachments to indexed files (exact rel-path join,
	 * paged by meta_id so rows added mid-run cannot shift the cursor).
	 *
	 * @param array $cursor Cursor (updated in place).
	 * @param int   $batch  Batch size.
	 * @return bool True while the match phase continues.
	 */
	protected static function match_step( &$cursor, $batch ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT post_id, meta_value, meta_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d',
				'_wp_attached_file',
				$cursor['last_meta_id'],
				$batch
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			self::prune_missing_files();
			return false;
		}

		$images = MSW_Database::table( MSW_Database::IMAGES );

		foreach ( $rows as $row ) {
			$changed = $wpdb->update(
				$images,
				array(
					'attachment_id' => (int) $row['post_id'],
					'updated_at'    => current_time( 'mysql', true ),
				),
				array( 'file_rel_path' => $row['meta_value'] ),
				array( '%d', '%s' ),
				array( '%s' )
			);
			if ( $changed ) {
				$cursor['matched']++;
			}
			$cursor['last_meta_id'] = (int) $row['meta_id'];
		}

		return true;
	}

	/**
	 * Insert or refresh rows for a set of relative file paths.
	 *
	 * @param string[] $rels   Relative paths (already filtered to real images).
	 * @param string   $root   Uploads basedir.
	 * @param array    $cursor Cursor (updated in place).
	 */
	protected static function index_files( $rels, $root, &$cursor ) {
		global $wpdb;

		$table = MSW_Database::table( MSW_Database::IMAGES );
		$now   = current_time( 'mysql', true );

		$placeholders = implode( ',', array_fill( 0, count( $rels ), '%s' ) );
		$existing     = $wpdb->get_col(
			$wpdb->prepare( "SELECT file_rel_path FROM {$table} WHERE file_rel_path IN ({$placeholders})", $rels ) // phpcs:ignore
		);
		$existing = array_flip( (array) $existing );

		foreach ( $rels as $rel ) {
			$path = $root . '/' . $rel;
			$size = @filesize( $path );
			$info = @getimagesize( $path );
			$mime = '';
			if ( is_array( $info ) && ! empty( $info['mime'] ) ) {
				$mime = $info['mime'];
			} elseif ( function_exists( 'finfo_open' ) ) {
				$finfo = finfo_open( FILEINFO_MIME_TYPE );
				if ( $finfo ) {
					$mime = (string) finfo_file( $finfo, $path );
					finfo_close( $finfo );
				}
			}

			$data = array(
				'file_path'     => $path,
				'file_rel_path' => $rel,
				'file_name'     => basename( $rel ),
				'mime_type'     => $mime,
				'width'         => is_array( $info ) ? (int) $info[0] : 0,
				'height'        => is_array( $info ) ? (int) $info[1] : 0,
				'file_size'     => $size ? (int) $size : 0,
				'updated_at'    => $now,
			);
			$format = array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s' );

			if ( isset( $existing[ $rel ] ) ) {
				$wpdb->update( $table, $data, array( 'file_rel_path' => $rel ), $format, array( '%s' ) );
			} else {
				$data['created_at'] = $now;
				$format[]           = '%s';
				$wpdb->insert( $table, $data, $format );
			}

			$cursor['files_done']++;
		}
	}

	/**
	 * Files in one directory, filtered to supported images, relative to uploads.
	 * Hidden files, backups (.ms-original), temp files and -WxH thumbnails are skipped.
	 *
	 * @param string $dir    Absolute dir.
	 * @param string $root   Uploads root.
	 * @param array  $cursor Cursor (thumbnail skip counter updated in place).
	 * @return string[] Sorted relative paths.
	 */
	protected static function list_images_in_dir( $dir, $root, &$cursor ) {
		$out = array();

		$entries = @scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return $out;
		}

		foreach ( $entries as $entry ) {
			if ( '' === $entry || '.' === $entry[0] || '..' === $entry ) {
				continue;
			}

			$path = $dir . '/' . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}

			$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::IMAGE_EXTS, true ) ) {
				continue;
			}

			// Native thumbnails are regenerated by WordPress, not index targets.
			if ( preg_match( self::THUMB_PATTERN, $entry ) ) {
				$cursor['thumb_skipped']++;
				continue;
			}

			$rel = ltrim( substr( $path, strlen( $root ) ), '/' );
			if ( $rel ) {
				$out[] = $rel;
			}
		}

		sort( $out );
		return $out;
	}

	/**
	 * Recursively list directories under $root (bounded).
	 *
	 * @param string $root Root dir.
	 * @return string[] Absolute dir paths, root first.
	 */
	protected static function collect_dirs( $root ) {
		$dirs  = array( $root );
		$queue = array( $root );

		while ( $queue && count( $dirs ) < self::MAX_DIRS ) {
			$current = array_shift( $queue );
			$entries = @scandir( $current );
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '' === $entry || '.' === $entry[0] || '..' === $entry ) {
					continue;
				}
				$path = $current . '/' . $entry;
				if ( is_dir( $path ) ) {
					$dirs[]  = $path;
					$queue[] = $path;
					if ( count( $dirs ) >= self::MAX_DIRS ) {
						MSW_Logger::warn( 'scan', 'Directory limit reached; deeper directories are not scanned.' );
						break 2;
					}
				}
			}
		}

		return $dirs;
	}

	/**
	 * Drop index rows whose file vanished from disk.
	 */
	protected static function prune_missing_files() {
		global $wpdb;

		$table = MSW_Database::table( MSW_Database::IMAGES );
		$ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE file_size = 0 LIMIT 500" ); // phpcs:ignore

		foreach ( (array) $ids as $id ) {
			$path = $wpdb->get_var( $wpdb->prepare( "SELECT file_path FROM {$table} WHERE id = %d", $id ) );
			if ( $path && ! is_file( $path ) ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
			}
		}
	}

	/**
	 * Uploads basedir.
	 *
	 * @return string
	 */
	public static function uploads_basedir() {
		$uploads = wp_get_upload_dir();
		return isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
	}
}

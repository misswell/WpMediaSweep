<?php
/**
 * Cleanup manager. Unused media is never deleted outright:
 *
 *   candidate -> user confirms -> trashed (WP native post trash + files moved to
 *   uploads/ms-trash/<token>/ with a manifest) -> auto purge after N days.
 *
 * Everything is reversible until the purge removes it.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Cleaner {

	/**
	 * Trash dir under uploads.
	 *
	 * @return string
	 */
	public static function trash_dir() {
		return MSW_Scanner::uploads_basedir() . '/ms-trash';
	}

	/**
	 * Move images to the trash. WordPress attachments are trashed natively
	 * (recoverable) and files are relocated with a manifest.
	 *
	 * @param int[] $image_ids wp_ms_images ids.
	 * @return array Summary { trashed, failed, errors }.
	 */
	public static function trash_images( $image_ids ) {
		global $wpdb;

		$summary = array( 'trashed' => 0, 'failed' => 0, 'errors' => array() );
		$ids     = array_filter( array_map( 'intval', (array) $image_ids ) );

		foreach ( $ids as $image_id ) {
			$image = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . MSW_Database::table( MSW_Database::IMAGES ) . ' WHERE id = %d', $image_id ),
				ARRAY_A
			);

			if ( ! $image ) {
				$summary['failed']++;
				$summary['errors'][] = sprintf( 'Image #%d not found.', $image_id );
				continue;
			}

			$result = self::trash_single( $image );
			if ( is_wp_error( $result ) ) {
				$summary['failed']++;
				$summary['errors'][] = sprintf( '%s: %s', $image['file_name'], $result->get_error_message() );
				continue;
			}

			$summary['trashed']++;

			// Keep the index row with a lifecycle status; the manifest holds the
			// file truth. Restore flips the status back to active.
			$wpdb->update(
				MSW_Database::table( MSW_Database::IMAGES ),
				array(
					'status'     => 'trash',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $image_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			MSW_Logger::info( 'cleanup', sprintf( 'Trashed %s (image #%d).', $image['file_name'], $image_id ) );
		}

		return $summary;
	}

	/**
	 * Trash one image: attachment -> post trash, files -> ms-trash/<token>/.
	 *
	 * @param array $image Image row.
	 * @return true|WP_Error
	 */
	protected static function trash_single( $image ) {
		$path = $image['file_path'];
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'msw_cleanup', 'File not found on disk.' );
		}
		if ( ! MSW_Files::within_uploads( $path ) ) {
			return new WP_Error( 'msw_cleanup', 'Refusing to move a file outside the uploads directory.' );
		}

		$uploads_root = MSW_Scanner::uploads_basedir();
		$rel          = $image['file_rel_path'];
		$token        = $image['attachment_id'] > 0
			? 'att-' . $image['attachment_id']
			: 'file-' . substr( md5( $rel ), 0, 12 );

		$dest_dir = self::trash_dir() . '/' . $token . '/' . dirname( $rel );
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'msw_cleanup', 'Cannot create trash directory.' );
		}

		$manifest = array(
			'image_id'      => (int) $image['id'],
			'attachment_id' => (int) $image['attachment_id'],
			'rel_path'      => $rel,
			'trashed_at'    => current_time( 'mysql', true ),
			'files'         => array(),
		);

		// Collect every physical file belonging to this image.
		$files = self::related_files( $path );
		foreach ( $files as $file ) {
			$file_rel = ltrim( substr( $file, strlen( $uploads_root ) ), '/' );
			$target   = self::trash_dir() . '/' . $token . '/' . $file_rel;

			if ( ! @rename( $file, $target ) ) {
				return new WP_Error( 'msw_cleanup', 'Cannot move ' . basename( $file ) . ' to trash.' );
			}

			$manifest['files'][] = array(
				'from' => $file_rel,
				'to'   => basename( $target ),
			);
		}

		// Trash the native attachment (recoverable via wp_untrash_post).
		if ( $image['attachment_id'] > 0 ) {
			$attachment = get_post( (int) $image['attachment_id'] );
			if ( $attachment && 'trash' !== $attachment->post_status ) {
				wp_trash_post( (int) $image['attachment_id'] );
			}
		}

		file_put_contents(
			self::trash_dir() . '/' . $token . '/manifest.json',
			wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		return true;
	}

	/**
	 * Restore a trashed image by token.
	 *
	 * @param string $token Trash token (directory name).
	 * @return true|WP_Error
	 */
	public static function restore_trashed( $token ) {
		$token = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $token );
		$entry = self::trash_dir() . '/' . $token;

		$manifest_path = $entry . '/manifest.json';
		if ( ! is_dir( $entry ) || ! is_file( $manifest_path ) ) {
			return new WP_Error( 'msw_cleanup', 'Trash entry not found.' );
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['rel_path'] ) ) {
			return new WP_Error( 'msw_cleanup', 'Invalid manifest.' );
		}

		$uploads_root = MSW_Scanner::uploads_basedir();

		// Move files back.
		foreach ( (array) $manifest['files'] as $file ) {
			$from   = $entry . '/' . $file['from'];
			$target = $uploads_root . '/' . $file['from'];

			$dir = dirname( $target );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			if ( is_file( $from ) ) {
				@rename( $from, $target );
			}
		}

		// Remove the now (hopefully) empty trash entry; leftovers are purged later.
		@unlink( $manifest_path );
		@rmdir( $entry );

		// Restore the attachment from WordPress trash.
		$att_id = isset( $manifest['attachment_id'] ) ? (int) $manifest['attachment_id'] : 0;
		if ( $att_id ) {
			$attachment = get_post( $att_id );
			if ( $attachment && 'trash' === $attachment->post_status ) {
				wp_untrash_post( $att_id );
			}
		}

		// Flip the index row back to active.
		if ( ! empty( $manifest['image_id'] ) ) {
			global $wpdb;
			$wpdb->update(
				MSW_Database::table( MSW_Database::IMAGES ),
				array(
					'status'     => 'active',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $manifest['image_id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// Re-index the restored file on the next scan; log it.
		MSW_Logger::info( 'cleanup', sprintf( 'Restored %s from trash (token %s).', $manifest['rel_path'], $token ) );

		return true;
	}

	/**
	 * Purge .ms-original backups older than the configured retention window.
	 * Runs daily via cron; retention 0 (default) keeps them forever.
	 *
	 * @return int Number of backup files removed.
	 */
	public static function purge_expired_backups() {
		global $wpdb;

		$retention = (int) MSW_Settings::get( 'backup_retention', 0 );
		if ( $retention <= 0 ) {
			return 0;
		}

		$cutoff = time() - $retention * DAY_IN_SECONDS;
		$table  = MSW_Database::table( MSW_Database::IMAGES );
		$last   = 0;
		$batch  = 500;
		$purged = 0;

		while ( true ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, file_path, file_name FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $last, $batch ),
				ARRAY_A
			);

			if ( ! $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				$last   = (int) $row['id'];
				$backup = $row['file_path'] . MSW_Compressor::BACKUP_SUFFIX;

				if ( ! is_file( $backup ) ) {
					continue;
				}

				$mtime = @filemtime( $backup );
				if ( $mtime && $mtime < $cutoff && MSW_Files::within_uploads( $backup ) ) {
					if ( @unlink( $backup ) ) {
						$purged++;
						MSW_Logger::info( 'cleanup', sprintf( 'Removed expired backup for %s (retention %d days).', $row['file_name'], $retention ) );
					}
				}
			}
		}

		return $purged;
	}

	/**
	 * List trash entries for the UI.
	 *
	 * @return array[]
	 */
	public static function list_trash() {
		$entries = array();
		$base    = self::trash_dir();

		if ( ! is_dir( $base ) ) {
			return $entries;
		}

		foreach ( (array) scandir( $base ) as $token ) {
			if ( '' === $token || '.' === $token[0] || '..' === $token ) {
				continue;
			}
			$manifest_path = $base . '/' . $token . '/manifest.json';
			if ( ! is_file( $manifest_path ) ) {
				continue;
			}
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
			if ( ! is_array( $manifest ) ) {
				continue;
			}
			$manifest['token'] = $token;
			$entries[]         = $manifest;
		}

		usort(
			$entries,
			function ( $a, $b ) {
				return strcmp( $b['trashed_at'], $a['trashed_at'] );
			}
		);

		return $entries;
	}

	/**
	 * Purge trash entries older than the retention window. Runs daily via cron.
	 *
	 * @return int Entries purged.
	 */
	public static function purge_expired() {
		$retention = (int) MSW_Settings::get( 'trash_retention', 30 );
		$cutoff    = strtotime( current_time( 'mysql', true ) . ' -' . $retention . ' days' );
		$purged    = 0;

		foreach ( self::list_trash() as $entry ) {
			$trashed_at = isset( $entry['trashed_at'] ) ? strtotime( $entry['trashed_at'] ) : 0;
			if ( ! $trashed_at || $trashed_at > $cutoff ) {
				continue;
			}

			// Permanently delete the attachment record.
			if ( ! empty( $entry['attachment_id'] ) ) {
				$attachment = get_post( (int) $entry['attachment_id'] );
				if ( $attachment ) {
					wp_delete_post( (int) $entry['attachment_id'], true );
				}
			}

			// Drop the trashed index rows for good (originals + their size variants).
			if ( ! empty( $entry['image_id'] ) ) {
				$wpdb->query(
					$wpdb->prepare(
						'DELETE FROM ' . MSW_Database::table( MSW_Database::IMAGES ) . ' WHERE id = %d OR parent_file_id = %d', // phpcs:ignore
						(int) $entry['image_id'],
						(int) $entry['image_id']
					)
				);
				$wpdb->delete(
					MSW_Database::table( MSW_Database::REFERENCES ),
					array( 'image_id' => (int) $entry['image_id'] ),
					array( '%d' )
				);
			}

			// Remove files.
			$dir = self::trash_dir() . '/' . $entry['token'];
			self::rrmdir( $dir );

			MSW_Logger::info( 'cleanup', sprintf( 'Purged trash entry %s (%s).', $entry['token'], $entry['rel_path'] ) );
			$purged++;
		}

		return $purged;
	}

	/**
	 * All physical files tied to an image: main file, backup, generated sizes.
	 *
	 * @param string $path Main file path.
	 * @return string[]
	 */
	protected static function related_files( $path ) {
		$files = array( $path );
		$dir   = dirname( $path );
		$name  = basename( $path );
		$ext   = pathinfo( $name, PATHINFO_EXTENSION );
		$stem  = $name !== '' ? substr( $name, 0, -strlen( $ext ) - 1 ) : $name;

		$backup = $path . MSW_Compressor::BACKUP_SUFFIX;
		if ( is_file( $backup ) ) {
			$files[] = $backup;
		}

		// WordPress generated sizes: <stem>-WxH.<ext>.
		foreach ( (array) glob( $dir . '/' . $stem . '-*[0-9]x[0-9]*.' . $ext ) as $thumb ) {
			if ( is_string( $thumb ) && is_file( $thumb ) ) {
				$files[] = $thumb;
			}
		}

		return $files;
	}

	/**
	 * Recursive directory removal (bounded to the trash root).
	 *
	 * @param string $dir Directory inside ms-trash.
	 */
	protected static function rrmdir( $dir ) {
		$root = rtrim( self::trash_dir(), '/' );
		$dir  = rtrim( (string) $dir, '/' );

		if ( 0 !== strpos( $dir, $root . '/' ) ) {
			return; // Never touch anything outside the trash root.
		}

		$queue = array( $dir );
		while ( $queue ) {
			$current = array_pop( $queue );
			$entries = @scandir( $current );
			if ( ! is_array( $entries ) ) {
				continue;
			}
			$empty = true;
			foreach ( $entries as $entry ) {
				if ( '' === $entry || '.' === $entry[0] || '..' === $entry ) {
					continue;
				}
				$path = $current . '/' . $entry;
				if ( is_dir( $path ) ) {
					$queue[] = $path;
					$empty   = false;
				} else {
					@unlink( $path );
				}
			}
			if ( $empty ) {
				@rmdir( $current );
			}
		}
		@rmdir( $dir );
	}
}

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

			$result = MSW_Files::with_lock( 'image-' . (int) $image_id, function () use ( $image_id ) {
				global $wpdb;
				$fresh = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MSW_Database::table( MSW_Database::IMAGES ) . ' WHERE id = %d', $image_id ), ARRAY_A );
				if ( ! $fresh || 'active' !== $fresh['status'] ) {
					return new WP_Error( 'msw_cleanup', 'Image is not active.' );
				}
				$result = self::trash_single( $fresh );
				if ( ! is_wp_error( $result ) ) {
					$wpdb->update( MSW_Database::table( MSW_Database::IMAGES ), array( 'status' => 'trash', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $image_id ), array( '%s', '%s' ), array( '%d' ) );
				}
				return $result;
			} );
			if ( is_wp_error( $result ) ) {
				$summary['failed']++;
				$summary['errors'][] = sprintf( '%s: %s', $image['file_name'], $result->get_error_message() );
				continue;
			}

			$summary['trashed']++;

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
		$manifest_path = self::trash_dir() . '/' . $token . '/manifest.json';
		if ( ! MSW_Files::within_uploads( $dest_dir ) || ! MSW_Files::within_uploads( $manifest_path ) || file_exists( $manifest_path ) ) {
			return new WP_Error( 'msw_cleanup', 'Unsafe or existing trash entry; restore it before retrying.' );
		}
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'msw_cleanup', 'Cannot create trash directory.' );
		}

		$manifest = array(
			'image_id'      => (int) $image['id'],
			'attachment_id' => (int) $image['attachment_id'],
			'rel_path'      => $rel,
			'trashed_at'    => current_time( 'mysql', true ),
			'state'         => 'moving',
			'files'         => array(),
		);

		// Collect every physical file belonging to this image.
		$files = self::related_files( $path );
		foreach ( $files as $file ) {
			$file_rel = ltrim( substr( $file, strlen( $uploads_root ) ), '/' );
			$target   = self::trash_dir() . '/' . $token . '/' . $file_rel;

			if ( ! MSW_Files::within_uploads( $file ) || ! MSW_Files::within_uploads( $target ) || file_exists( $target ) || is_link( $target ) ) {
				return new WP_Error( 'msw_cleanup', 'Unsafe or occupied trash destination.' );
			}
			$hash = @hash_file( 'sha256', $file );
			if ( ! $hash ) {
				return new WP_Error( 'msw_cleanup', 'Cannot read file before moving it to trash.' );
			}
			$manifest['files'][] = array(
				'from' => $file_rel,
				'to'   => basename( $target ),
				'sha256' => $hash,
			);
		}

		// Save the entire recovery plan before the first move, including crash recovery.
		if ( ! self::write_manifest( $manifest_path, $manifest ) ) {
			return new WP_Error( 'msw_cleanup', 'Cannot save trash recovery manifest.' );
		}
		$moved = array();
		foreach ( $manifest['files'] as $file ) {
			$from = $uploads_root . '/' . $file['from'];
			$target = self::trash_dir() . '/' . $token . '/' . $file['from'];
			if ( ! @rename( $from, $target ) ) {
				self::rollback_moves( $moved, $manifest_path );
				return new WP_Error( 'msw_cleanup', 'Cannot move ' . basename( $from ) . ' to trash. Recovery manifest retained if rollback failed.' );
			}
			$moved[] = array( $from, $target );
		}
		$manifest['state'] = 'trashed';
		if ( ! self::write_manifest( $manifest_path, $manifest ) ) {
			self::rollback_moves( $moved, $manifest_path );
			return new WP_Error( 'msw_cleanup', 'Cannot finalize trash manifest.' );
		}

		// Trash the native attachment (recoverable via wp_untrash_post).
		if ( $image['attachment_id'] > 0 ) {
			$attachment = get_post( (int) $image['attachment_id'] );
			if ( $attachment && 'trash' !== $attachment->post_status ) {
				if ( ! wp_trash_post( (int) $image['attachment_id'] ) ) {
					$manifest['state'] = 'moving';
					self::write_manifest( $manifest_path, $manifest );
					self::rollback_moves( $moved, $manifest_path );
					return new WP_Error( 'msw_cleanup', 'Cannot trash the WordPress attachment.' );
				}
			}
		}

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
		$path = self::trash_dir() . '/' . $token . '/manifest.json';
		if ( '' === $token || ! MSW_Files::within_uploads( $path ) || ! is_file( $path ) ) {
			return new WP_Error( 'msw_cleanup', 'Trash entry not found.' );
		}
		$manifest = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['image_id'] ) ) {
			return new WP_Error( 'msw_cleanup', 'Invalid manifest.' );
		}
		return MSW_Files::with_lock( 'image-' . (int) $manifest['image_id'], function () use ( $token ) {
			return self::restore_trashed_locked( $token );
		} );
	}

	protected static function restore_trashed_locked( $token ) {
		$token = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $token );
		$entry = self::trash_dir() . '/' . $token;

		$manifest_path = $entry . '/manifest.json';
		if ( ! is_dir( $entry ) || ! is_file( $manifest_path ) ) {
			return new WP_Error( 'msw_cleanup', 'Trash entry not found.' );
		}

		$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
		if ( ! is_array( $manifest ) || empty( $manifest['rel_path'] ) || empty( $manifest['files'] ) ) {
			return new WP_Error( 'msw_cleanup', 'Invalid manifest.' );
		}

		$uploads_root = MSW_Scanner::uploads_basedir();
		// Upgrade legacy manifests before any moves so interrupted restores can retry.
		foreach ( $manifest['files'] as &$file ) {
			$source = $entry . '/' . $file['from'];
			if ( empty( $file['sha256'] ) && MSW_Files::within_uploads( $source ) && is_file( $source ) ) {
				$file['sha256'] = @hash_file( 'sha256', $source );
			}
		}
		unset( $file );
		$manifest['state'] = 'restoring';
		if ( ! self::write_manifest( $manifest_path, $manifest ) ) {
			return new WP_Error( 'msw_cleanup', 'Cannot save restore state.' );
		}

		// Move files back.
		foreach ( (array) $manifest['files'] as $file ) {
			$from   = $entry . '/' . $file['from'];
			$target = $uploads_root . '/' . $file['from'];
			if ( ! MSW_Files::within_uploads( $from ) || ! MSW_Files::within_uploads( $target ) || is_link( $from ) || is_link( $target ) ) {
				return new WP_Error( 'msw_cleanup', 'Unsafe restore path.' );
			}
			if ( ! is_file( $from ) ) {
				// A previous partial restore/rollback may already have put this file back.
				if ( is_file( $target ) && ! empty( $file['sha256'] ) && hash_file( 'sha256', $target ) === $file['sha256'] ) {
					continue;
				}
				return new WP_Error( 'msw_cleanup', 'A recovery file is missing; manifest retained.' );
			}
			if ( file_exists( $target ) ) {
				return new WP_Error( 'msw_cleanup', 'Restore destination already exists; no files were overwritten.' );
			}

			$dir = dirname( $target );
			if ( ! is_dir( $dir ) ) {
				if ( ! wp_mkdir_p( $dir ) ) {
					return new WP_Error( 'msw_cleanup', 'Cannot create restore directory.' );
				}
			}

			if ( ! @rename( $from, $target ) ) {
				return new WP_Error( 'msw_cleanup', 'Cannot restore file; recovery manifest retained.' );
			}
		}

		// Restore the attachment from WordPress trash.
		$att_id = isset( $manifest['attachment_id'] ) ? (int) $manifest['attachment_id'] : 0;
		if ( $att_id ) {
			$attachment = get_post( $att_id );
			if ( $attachment && 'trash' === $attachment->post_status ) {
				if ( ! wp_untrash_post( $att_id ) ) {
					return new WP_Error( 'msw_cleanup', 'Cannot restore the WordPress attachment; manifest retained.' );
				}
			}
		}

		// Flip the index row back to active.
		if ( ! empty( $manifest['image_id'] ) ) {
			global $wpdb;
			$updated = $wpdb->update(
				MSW_Database::table( MSW_Database::IMAGES ),
				array(
					'status'     => 'active',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $manifest['image_id'] ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'msw_cleanup', 'Cannot update restored image index; manifest retained.' );
			}
		}
		if ( ! @unlink( $manifest_path ) ) {
			return new WP_Error( 'msw_cleanup', 'Files restored, but recovery manifest could not be removed.' );
		}
		@rmdir( $entry );

		// Re-index the restored file on the next scan; log it.
		MSW_Logger::info( 'cleanup', sprintf( 'Restored %s from trash (token %s).', $manifest['rel_path'], $token ) );

		return true;
	}

	/** Persist a manifest atomically; never truncate the last recovery plan. */
	protected static function write_manifest( $path, $manifest ) {
		$tmp = dirname( $path ) . '/.manifest-' . uniqid( '', true );
		if ( ! MSW_Files::within_uploads( $path ) || ! MSW_Files::within_uploads( $tmp ) || is_link( $path ) ) {
			return false;
		}
		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$ok = is_string( $json ) && strlen( $json ) === @file_put_contents( $tmp, $json ) && @rename( $tmp, $path );
		if ( is_file( $tmp ) ) {
			@unlink( $tmp );
		}
		return $ok;
	}

	/** Roll back completed moves; keep the plan if any rollback cannot finish. */
	protected static function rollback_moves( $moves, $manifest_path ) {
		$ok = true;
		foreach ( array_reverse( $moves ) as $move ) {
			if ( ! MSW_Files::within_uploads( $move[0] ) || ! MSW_Files::within_uploads( $move[1] ) || file_exists( $move[0] ) || ! @rename( $move[1], $move[0] ) ) {
				$ok = false;
			}
		}
		if ( $ok ) {
			@unlink( $manifest_path );
		}
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
			$result = MSW_Files::with_lock( 'image-' . (int) ( $entry['image_id'] ?? 0 ), function () use ( $entry, $cutoff ) {
				return self::purge_entry( $entry['token'], $cutoff );
			} );
			if ( true === $result ) {
				$purged++;
			}
		}
		return $purged;
	}

	/** Re-read the entry under the same lock used by trash and restore. */
	protected static function purge_entry( $token, $cutoff ) {
		global $wpdb;
		$path = self::trash_dir() . '/' . $token . '/manifest.json';
		if ( ! MSW_Files::within_uploads( $path ) || ! is_file( $path ) ) {
			return false;
		}
		$entry = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $entry ) ) {
			return false;
		}
		$entry['token'] = $token;
		if ( isset( $entry['state'] ) && 'trashed' !== $entry['state'] ) {
			return false;
		}
		$trashed_at = isset( $entry['trashed_at'] ) ? strtotime( $entry['trashed_at'] ) : 0;
		if ( ! $trashed_at || $trashed_at > $cutoff ) {
			return false;
		}

		// Permanently delete only a still-trashed attachment.
		if ( ! empty( $entry['attachment_id'] ) ) {
			$attachment = get_post( (int) $entry['attachment_id'] );
			if ( $attachment && ( 'trash' !== $attachment->post_status || ! wp_delete_post( (int) $entry['attachment_id'], true ) ) ) {
				return false;
			}
		}
		if ( ! empty( $entry['image_id'] ) ) {
			$deleted = $wpdb->query( $wpdb->prepare(
				'DELETE FROM ' . MSW_Database::table( MSW_Database::IMAGES ) . ' WHERE id = %d OR parent_file_id = %d',
				(int) $entry['image_id'], (int) $entry['image_id']
			) );
			$refs_deleted = $wpdb->delete( MSW_Database::table( MSW_Database::REFERENCES ), array( 'image_id' => (int) $entry['image_id'] ), array( '%d' ) );
			if ( false === $deleted || false === $refs_deleted ) {
				return false;
			}
		}
		$dir = self::trash_dir() . '/' . $entry['token'];
		if ( ! self::rrmdir( $dir ) ) {
			return false;
		}
		MSW_Logger::info( 'cleanup', sprintf( 'Purged trash entry %s (%s).', $entry['token'], $entry['rel_path'] ) );
		return true;
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

		if ( 0 !== strpos( $dir, $root . '/' ) || ! MSW_Files::within_uploads( $dir ) || is_link( $dir ) ) {
			return false;
		}

		$queue = array( array( $dir, false ) );
		while ( $queue ) {
			list( $current, $visited ) = array_pop( $queue );
			if ( $visited ) {
				@rmdir( $current );
				continue;
			}
			$entries = @scandir( $current );
			if ( ! is_array( $entries ) ) {
				continue;
			}
			$queue[] = array( $current, true );
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $current . '/' . $entry;
				if ( ! MSW_Files::within_uploads( $path ) || is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					$queue[] = array( $path, false );
				} else {
					@unlink( $path );
				}
			}
		}
		return ! is_dir( $dir );
	}
}

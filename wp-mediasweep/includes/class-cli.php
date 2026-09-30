<?php
/**
 * WP-CLI commands: wp mediasweep <command>
 *
 * Built for large sites where the admin UI is impractical — every command
 * drives the same resumable task engine the UI uses, so runs can be
 * interrupted and resumed at any time.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_CLI {

	/**
	 * Register commands when running under WP-CLI.
	 */
	public static function register() {
		WP_CLI::add_command( 'mediasweep', __CLASS__ );
	}

	/**
	 * Index the media library (batched, resumable).
	 *
	 * ## OPTIONS
	 *
	 * [--max-steps=<n>]
	 * : Safety stop after n task steps. Default: unlimited.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep scan
	 *     wp mediasweep scan --max-steps=50
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function scan( $args, $assoc_args ) {
		$task = MSW_Scanner::start();
		WP_CLI::log( sprintf( 'Scan task #%d started/resumed.', $task['id'] ) );
		$this->run_task_loop( 'scan', isset( $assoc_args['max-steps'] ) ? (int) $assoc_args['max-steps'] : 0 );
	}

	/**
	 * Detect image references (post content, featured images, blocks,
	 * WooCommerce, Elementor, optionally theme/plugin files).
	 *
	 * ## OPTIONS
	 *
	 * [--max-steps=<n>]
	 * : Safety stop after n task steps. Default: unlimited.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep references
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function references( $args, $assoc_args ) {
		$task = MSW_Reference_Detector::start();
		WP_CLI::log( sprintf( 'Reference scan task #%d started/resumed.', $task['id'] ) );
		$this->run_task_loop( 'reference_scan', isset( $assoc_args['max-steps'] ) ? (int) $assoc_args['max-steps'] : 0 );
	}

	/**
	 * Compress images (a single id, selected ids, or everything pending).
	 *
	 * ## OPTIONS
	 *
	 * [--id=<id>]
	 * : Compress one indexed image synchronously.
	 *
	 * [--ids=<id,id,…>]
	 * : Queue a batch task for specific image ids.
	 *
	 * [--all]
	 * : Queue a batch task for every uncompressed original image.
	 *
	 * [--force]
	 * : With --id, recompress even if already compressed.
	 *
	 * [--max-steps=<n>]
	 * : Safety stop after n task steps. Default: unlimited.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep compress --id=42
	 *     wp mediasweep compress --all
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function compress( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['id'] ) ) {
			$result = MSW_Compressor::compress_image( (int) $assoc_args['id'], ! empty( $assoc_args['force'] ) );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
			WP_CLI::success(
				sprintf(
					'Compressed #%d via %s: %s → %s (%.1f%% saved).',
					$result['image_id'],
					$result['backend'],
					size_format( $result['original_size'] ),
					size_format( $result['compressed_size'] ),
					$result['ratio']
				)
			);
			return;
		}

		global $wpdb;
		$table   = MSW_Database::table( MSW_Database::IMAGES );
		$options = array();
		$total   = 0;

		if ( ! empty( $assoc_args['ids'] ) ) {
			$ids            = array_values( array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['ids'] ) ) ) );
			$options['ids'] = $ids;
			$total          = count( $ids );
		} else {
			$options['all'] = 1;
			$total          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE compressed = 0 AND is_thumbnail = 0" ); // phpcs:ignore
		}

		if ( ! $total ) {
			WP_CLI::success( 'Nothing to compress.' );
			return;
		}

		$task = MSW_Task_Manager::create( 'compress', $options, $total );
		WP_CLI::log( sprintf( 'Compression task #%d queued (%d images).', $task['id'], $total ) );
		$this->run_task_loop( 'compress', isset( $assoc_args['max-steps'] ) ? (int) $assoc_args['max-steps'] : 0 );
	}

	/**
	 * List images with no detected references.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum rows. Default: 50.
	 *
	 * [--format=<format>]
	 * : table | csv | json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep unused --limit=20
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function unused( $args, $assoc_args ) {
		global $wpdb;

		$table = MSW_Database::table( MSW_Database::IMAGES );
		$limit = min( 1000, max( 1, isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50 ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, attachment_id, file_name, file_size, reference_status,
					CASE WHEN attachment_id > 0 THEN 'cautious' ELSE 'safe' END AS risk_level
				FROM {$table}
				WHERE is_thumbnail = 0 AND reference_status IN ('unused','orphan')
				ORDER BY file_size DESC LIMIT %d", // phpcs:ignore
				$limit
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			WP_CLI::success( 'No unreferenced images found. Run "wp mediasweep references" first if you have not.' );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'attachment_id', 'file_name', 'file_size', 'reference_status', 'risk_level' ) );
	}

	/**
	 * Move images to the MediaSweep trash (reversible for the retention window).
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<id,id,…>]
	 * : Index ids to trash.
	 *
	 * [--unused]
	 * : Trash every unreferenced image (requires --yes).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep clean --ids=12,13
	 *     wp mediasweep clean --unused --yes
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function clean( $args, $assoc_args ) {
		global $wpdb;

		$ids = array();
		if ( ! empty( $assoc_args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) $assoc_args['ids'] ) ) ) );
		} elseif ( ! empty( $assoc_args['unused'] ) ) {
			$table = MSW_Database::table( MSW_Database::IMAGES );
			$ids   = array_map(
				'intval',
				(array) $wpdb->get_col( "SELECT id FROM {$table} WHERE is_thumbnail = 0 AND reference_status IN ('unused','orphan')" ) // phpcs:ignore
			);
		}

		if ( ! $ids ) {
			WP_CLI::error( 'Nothing selected. Pass --ids=… or --unused.' );
		}

		WP_CLI::confirm( sprintf( 'Move %d image(s) to the MediaSweep trash?', count( $ids ) ), $assoc_args );

		$summary = MSW_Cleaner::trash_images( $ids );
		foreach ( $summary['errors'] as $error ) {
			WP_CLI::warning( $error );
		}
		WP_CLI::success( sprintf( 'Trashed %d image(s), %d failed. Restore from the admin UI while retention lasts.', $summary['trashed'], $summary['failed'] ) );
	}

	/**
	 * Advance the task queue once (normally driven by WP-Cron / the admin UI).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep tick
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function tick( $args, $assoc_args ) {
		$ran = MSW_Task_Manager::tick();
		$task = MSW_Task_Manager::active();
		WP_CLI::success(
			$ran
				? sprintf( 'Ran one step; task #%d still %s.', $task ? $task['id'] : 0, $task ? $task['status'] : 'done' )
				: 'No active task.'
		);
	}

	/**
	 * Print library statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep stats
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function stats( $args, $assoc_args ) {
		global $wpdb;

		$images = MSW_Database::table( MSW_Database::IMAGES );
		$row    = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total_files,
				COALESCE( SUM( is_thumbnail = 0 ), 0 ) AS originals,
				COALESCE( SUM( file_size ), 0 ) AS total_bytes,
				COALESCE( SUM( is_thumbnail = 0 AND compressed = 1 ), 0 ) AS compressed,
				COALESCE( SUM( is_thumbnail = 0 AND compressed = 0 ), 0 ) AS pending,
				COALESCE( SUM( CASE WHEN is_thumbnail = 0 AND compressed = 1 THEN original_size - compressed_size ELSE 0 END ), 0 ) AS saved_bytes,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'unused' ), 0 ) AS unused,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'maybe' ), 0 ) AS maybe_used,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'orphan' ), 0 ) AS orphan,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'used' ), 0 ) AS used
			FROM {$images}", // phpcs:ignore
			ARRAY_A
		);

		$rows = array(
			array( 'metric' => 'Files indexed', 'value' => number_format_i18n( (int) $row['total_files'] ) . ' (' . number_format_i18n( (int) $row['originals'] ) . ' originals)' ),
			array( 'metric' => 'Total size', 'value' => size_format( (int) $row['total_bytes'] ) ),
			array( 'metric' => 'Compressed', 'value' => number_format_i18n( (int) $row['compressed'] ) ),
			array( 'metric' => 'Pending', 'value' => number_format_i18n( (int) $row['pending'] ) ),
			array( 'metric' => 'Saved', 'value' => size_format( (int) $row['saved_bytes'] ) ),
			array( 'metric' => 'Used / Maybe / Unused / Orphan', 'value' => sprintf( '%d / %d / %d / %d', $row['used'], $row['maybe_used'], $row['unused'], $row['orphan'] ) ),
			array( 'metric' => 'Backends', 'value' => ( MSW_Backend_Imagick::available() ? 'imagick' : '' ) . ( MSW_Backend_GD::available() ? ( MSW_Backend_Imagick::available() ? '+gd' : 'gd' ) : '' ) ),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'value' ) );
	}

	/**
	 * List duplicate image groups (identical md5, originals only).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum groups. Default: 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mediasweep duplicates
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function duplicates( $args, $assoc_args ) {
		global $wpdb;

		$images = MSW_Database::table( MSW_Database::IMAGES );
		$limit  = min( 500, max( 1, isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50 ) );

		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT md5_hash, COUNT(*) AS files, SUM( file_size ) - MAX( file_size ) AS excess_size
				FROM {$images}
				WHERE is_thumbnail = 0 AND md5_hash <> ''
				GROUP BY md5_hash HAVING COUNT(*) > 1
				ORDER BY excess_size DESC LIMIT %d", // phpcs:ignore
				$limit
			),
			ARRAY_A
		);

		if ( ! $groups ) {
			WP_CLI::success( 'No duplicate images found (run "wp mediasweep scan" first to build hashes).' );
			return;
		}

		foreach ( $groups as $group ) {
			WP_CLI::log( sprintf( '── %d copies · %s reclaimable · md5 %s', $group['files'], size_format( (int) $group['excess_size'] ), substr( $group['md5_hash'], 0, 12 ) ) );

			$files = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, file_rel_path, file_size, reference_status FROM {$images} WHERE is_thumbnail = 0 AND md5_hash = %s ORDER BY id ASC", // phpcs:ignore
					$group['md5_hash']
				),
				ARRAY_A
			);
			WP_CLI\Utils\format_items( 'table', $files, array( 'id', 'file_rel_path', 'file_size', 'reference_status' ) );
		}
	}

	/**
	 * Drive the task loop until the given task type has no active task left.
	 *
	 * @param string $type      Task type to wait for.
	 * @param int    $max_steps Safety stop (0 = unlimited).
	 */
	protected function run_task_loop( $type, $max_steps = 0 ) {
		$steps = 0;

		while ( true ) {
			if ( $max_steps > 0 && $steps >= $max_steps ) {
				WP_CLI::warning( sprintf( 'Stopped after %d steps (--max-steps). Resume with the same command.', $steps ) );
				return;
			}

			$active = MSW_Task_Manager::active( $type );
			if ( ! $active ) {
				break;
			}

			if ( ! MSW_Task_Manager::tick() ) {
				break;
			}

			$steps++;

			if ( 0 === $steps % 5 ) {
				$task = MSW_Task_Manager::get( $active['id'] );
				if ( $task ) {
					WP_CLI::log( sprintf( '[task #%d] %s — %d/%s processed, %d failed', $task['id'], $task['status'], $task['processed'], $task['total'] ?: '?', $task['failed'] ) );
				}
			}
		}

		$latest = MSW_Task_Manager::all( 1 );
		$task   = $latest ? $latest[0] : null;

		if ( $task && $type === $task['type'] ) {
			WP_CLI::success(
				sprintf(
					'Task #%d %s: %d processed, %d failed (%d steps).',
					$task['id'],
					$task['status'],
					$task['processed'],
					$task['failed'],
					$steps
				)
			);
		} else {
			WP_CLI::success( 'Nothing active.' );
		}
	}
}

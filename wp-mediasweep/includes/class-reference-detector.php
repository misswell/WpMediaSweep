<?php
/**
 * Reference detector. Finds where indexed images are actually used.
 *
 * Sources covered:
 *   1. post_content     — uploads URLs / relative paths inside post content
 *   2. featured image   — wp_postmeta._thumbnail_id
 *   3. Gutenberg        — <!-- wp:image --> block ids inside post_content
 *   4. WooCommerce      — _product_image_gallery
 *   5. Elementor        — _elementor_data (JSON walk)
 *   6. theme / plugin   — file contents under wp-content/themes|plugins (optional)
 *
 * Reference statuses written to wp_ms_images:
 *   used    — at least one hard reference
 *   maybe   — only basename matches (same filename, different path possible)
 *   unused  — indexed attachment with no reference found
 *   orphan  — file on disk with no matching media library attachment
 *
 * The full scan runs as a resumable task; hits are written to wp_ms_references
 * as they are found (ticks are separate requests, no in-memory aggregation).
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Reference_Detector {

	const STATUS_USED   = 'used';
	const STATUS_MAYBE  = 'maybe';
	const STATUS_UNUSED = 'unused';
	const STATUS_ORPHAN = 'orphan';
	const STATUS_UNKNOWN = 'unknown';

	/** @var string[] Reference types that only count as "maybe used". */
	const SOFT_TYPES = array( 'post_content_maybe', 'theme_maybe', 'plugin_maybe' );

	/**
	 * Start (or resume) a full reference scan.
	 *
	 * @return array Task row.
	 */
	public static function start() {
		$task = MSW_Task_Manager::active( 'reference_scan' );
		if ( $task ) {
			return $task;
		}

		$task = MSW_Task_Manager::create(
			'reference_scan',
			array(
				'scan_themes'  => (bool) MSW_Settings::get( 'scan_themes', false ),
				'scan_plugins' => (bool) MSW_Settings::get( 'scan_plugins', false ),
			)
		);

		MSW_Logger::info( 'references', 'Reference scan started.' );

		return $task;
	}

	public static function register_handler() {
		MSW_Task_Manager::register( 'reference_scan', array( __CLASS__, 'run_task' ) );
	}

	/**
	 * Task handler.
	 *
	 * @param array $task   Task row.
	 * @param int   $budget Seconds for this tick.
	 * @return bool True while work remains.
	 */
	public static function run_task( $task, $budget ) {
		$cursor = wp_parse_args(
			is_array( $task['cursor'] ) ? $task['cursor'] : array(),
			array(
				'phase'     => 'posts',
				'last_id'   => 0,
				'files'     => array(),
				'file_idx'  => 0,
				'offset'    => 0,
				'found'     => 0,
			)
		);

		$batch = (int) MSW_Settings::get( 'batch_size', 200 );
		$start = microtime( true );

		while ( microtime( true ) - $start < $budget ) {
			// Phase whose step is about to run. Only a finished finalize step
			// means the whole scan is done — other steps hand off to the next
			// phase inside next_phase() before returning false.
			$running_phase = $cursor['phase'];

			switch ( $cursor['phase'] ) {
				case 'posts':
					$more = self::scan_posts_step( $cursor, $batch );
					break;
				case 'featured':
					$more = self::scan_meta_step( $cursor, '_thumbnail_id', 'featured', $batch );
					break;
				case 'woo':
					$more = self::scan_meta_step( $cursor, '_product_image_gallery', 'woocommerce', $batch, true );
					break;
				case 'elementor':
					$more = self::scan_elementor_step( $cursor, $batch );
					break;
				case 'files':
					$more = self::scan_files_step( $task, $cursor, $batch );
					break;
				case 'finalize':
					$more = self::finalize_step( $cursor, $batch );
					break;
				default:
					$more = false;
			}

			MSW_Task_Manager::set_cursor( $task['id'], $cursor );
			MSW_Task_Manager::update(
				$task['id'],
				array(
					'processed' => $cursor['found'],
				)
			);

			if ( ! $more ) {
				if ( 'finalize' === $running_phase ) {
					MSW_Logger::info( 'references', sprintf( 'Reference scan finished: %d references recorded.', $cursor['found'] ) );
					return false;
				}
				// A phase finished; loop advances to the next one within this tick.
			}
		}

		return true;
	}

	/**
	 * Advance cursor to the next phase.
	 *
	 * @param array $cursor Cursor (updated in place).
	 * @param array $task   Task row.
	 */
	protected static function next_phase( &$cursor, $task = null ) {
		$scan_themes  = $task && ! empty( $task['options']['scan_themes'] );
		$scan_plugins = $task && ! empty( $task['options']['scan_plugins'] );

		$order = array( 'posts', 'featured', 'woo', 'elementor' );
		if ( $scan_themes || $scan_plugins ) {
			$order[] = 'files';
		}

		$idx = array_search( $cursor['phase'], $order, true );
		if ( false !== $idx && isset( $order[ $idx + 1 ] ) ) {
			$cursor['phase']   = $order[ $idx + 1 ];
			$cursor['last_id'] = 0;
			$cursor['file_idx'] = 0;
			$cursor['offset']  = 0;
			$cursor['scope']   = 'files' === $cursor['phase'] ? ( $scan_themes ? 'themes' : 'plugins' ) : '';
			MSW_Logger::info( 'references', sprintf( 'Reference scan phase: %s', 'files' === $cursor['phase'] ? 'theme files' : $cursor['phase'] ) );
			return;
		}

		// Theme files finished — continue with plugins when both are enabled.
		if ( 'files' === $cursor['phase'] && $scan_plugins && empty( $cursor['plugins_done'] ) ) {
			if ( $scan_themes ) {
				// Themes were scanned first; now do plugins.
				$cursor['plugins_done'] = 1;
				$cursor['file_idx']     = 0;
				$cursor['scope']        = 'plugins';
				MSW_Logger::info( 'references', 'Reference scan phase: plugin files' );
				return;
			}
		}

		$cursor['phase']   = 'finalize';
		$cursor['last_id'] = 0;
		MSW_Logger::info( 'references', 'Reference scan phase: finalize' );
	}

	/**
	 * Phase: scan post_content in id order.
	 *
	 * @param array $cursor Cursor.
	 * @param int   $batch  Batch size.
	 * @return bool True while phase continues.
	 */
	protected static function scan_posts_step( &$cursor, $batch ) {
		global $wpdb;

		$types  = get_post_types( array( 'public' => true ), 'names' );
		$types  = array_diff( (array) $types, array( 'attachment' ) );
		$types[] = 'wp_block';
		$type_in = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $type_in is escaped above.
				"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_type IN ({$type_in}) AND post_status NOT IN ('trash','auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d",
				$cursor['last_id'],
				max( 20, (int) ( $batch / 2 ) )
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			self::next_phase( $cursor );
			return false;
		}

		foreach ( $rows as $row ) {
			self::find_refs_in_content( $row['post_content'], 'post_content', (int) $row['ID'], $row['post_title'], $cursor );
			$cursor['last_id'] = (int) $row['ID'];
		}

		return true;
	}

	/**
	 * Extract uploads references from a chunk of content and store hits.
	 *
	 * @param string $content Content.
	 * @param string $type    Reference type (post_content | theme | plugin).
	 * @param int    $ref_id  Reference id (post id / 0).
	 * @param string $source  Human readable source label.
	 * @param array  $cursor  Cursor (found counter updated in place).
	 * @param bool   $strong  Strong reference (code files). Weak sources like
	 *                        READMEs record "maybe" hits that never mark an
	 *                        image as used on their own.
	 */
	protected static function find_refs_in_content( $content, $type, $ref_id, $source, &$cursor, $strong = true ) {
		global $wpdb;

		if ( ! is_string( $content ) || '' === $content ) {
			return;
		}

		$images = MSW_Database::table( MSW_Database::IMAGES );
		$maybe_type = in_array( $type, array( 'theme', 'plugin' ), true ) ? $type . '_maybe' : 'post_content_maybe';

		// 1. Exact matches: uploads relative paths. When content points at a
		// size variant URL, the reference belongs to its parent original.
		if ( preg_match_all( '#wp-content/uploads/([^\s"\'\)\>\\\\]+)#i', $content, $m ) ) {
			$candidates = array_unique( array_map( 'rawurldecode', $m[1] ) );
			$placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
			$found = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, parent_file_id FROM {$images} WHERE file_rel_path IN ({$placeholders}) AND status = 'active'", // phpcs:ignore
					$candidates
				),
				ARRAY_A
			);

			$targets = array();
			foreach ( (array) $found as $hit ) {
				$targets[] = $hit['parent_file_id'] > 0 ? (int) $hit['parent_file_id'] : (int) $hit['id'];
			}
			foreach ( array_unique( $targets ) as $target_id ) {
				self::store_ref( $target_id, $type, $ref_id, $source, $cursor );
			}
		}

		// 2. Gutenberg blocks: parse natively and collect attachment ids.
		if ( 'post_content' === $type && false !== strpos( $content, '<!-- wp:' ) && function_exists( 'parse_blocks' ) ) {
			$block_ids = array();
			self::collect_block_ids_recursive( parse_blocks( $content ), $block_ids );

			if ( $block_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $block_ids ), '%d' ) );
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, parent_file_id FROM {$images} WHERE attachment_id IN ({$placeholders}) AND status = 'active'", // phpcs:ignore
						$block_ids
					),
					ARRAY_A
				);
				$targets = array();
				foreach ( (array) $found as $hit ) {
					$targets[] = $hit['parent_file_id'] > 0 ? (int) $hit['parent_file_id'] : (int) $hit['id'];
				}
				foreach ( array_unique( $targets ) as $target_id ) {
					self::store_ref( $target_id, 'gutenberg', $ref_id, $source, $cursor );
				}
			}
		}

		// 3. Fuzzy matches: bare file names (maybe-used).
		if ( preg_match_all( '/[\w\-.]+\.(?:jpe?g|png|webp|avif)/i', $content, $names ) ) {
			$basenames = array_unique( array_map( 'rawurldecode', $names[0] ) );
			if ( $basenames ) {
				$placeholders = implode( ',', array_fill( 0, count( $basenames ), '%s' ) );
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, parent_file_id FROM {$images} WHERE file_name IN ({$placeholders}) AND status = 'active'", // phpcs:ignore
						$basenames
					),
					ARRAY_A
				);
				foreach ( (array) $found as $hit ) {
					$target_id = $hit['parent_file_id'] > 0 ? (int) $hit['parent_file_id'] : (int) $hit['id'];
					self::store_ref( $target_id, $maybe_type, $ref_id, $source, $cursor );
				}
			}
		}
	}

	/**
	 * Collect attachment ids from parsed Gutenberg blocks (any block type,
	 * recursively — image, gallery, media-text, cover, third-party blocks…).
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int[] $ids    Output accumulator.
	 */
	protected static function collect_block_ids_recursive( $blocks, &$ids ) {
		foreach ( (array) $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			foreach ( $attrs as $key => $value ) {
				if ( 'id' === $key && is_numeric( $value ) && (int) $value > 0 ) {
					$ids[] = (int) $value;
				} elseif ( 'ids' === $key && is_array( $value ) ) {
					foreach ( $value as $nested ) {
						if ( is_numeric( $nested ) && (int) $nested > 0 ) {
							$ids[] = (int) $nested;
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_block_ids_recursive( $block['innerBlocks'], $ids );
			}
		}
	}

	/**
	 * Phase: scan one numeric/CSV meta key.
	 *
	 * @param array  $cursor Cursor.
	 * @param string $meta_key Meta key to page through.
	 * @param string $type     Reference type to write.
	 * @param int    $batch    Batch size.
	 * @param bool   $is_csv   Value is a comma separated id list.
	 * @return bool True while phase continues.
	 */
	protected static function scan_meta_step( &$cursor, $meta_key, $type, $batch, $is_csv = false ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value, meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				$meta_key,
				$cursor['last_id'],
				$batch
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			self::next_phase( $cursor );
			return false;
		}

		$images = MSW_Database::table( MSW_Database::IMAGES );

		foreach ( $rows as $row ) {
			$cursor['last_id'] = (int) $row['meta_id'];

			$ids = $is_csv
				? array_filter( array_map( 'intval', explode( ',', (string) $row['meta_value'] ) ) )
				: array( (int) $row['meta_value'] );

			if ( ! $ids ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$found = $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$images} WHERE attachment_id IN ({$placeholders})", $ids ) // phpcs:ignore
			);

			foreach ( (array) $found as $image_id ) {
				self::store_ref( $image_id, $type, (int) $row['post_id'], $meta_key, $cursor );
			}
		}

		return true;
	}

	/**
	 * Phase: parse Elementor data JSON in batches.
	 *
	 * @param array $cursor Cursor.
	 * @param int   $batch  Batch size.
	 * @return bool True while phase continues.
	 */
	protected static function scan_elementor_step( &$cursor, $batch ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value, meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				$cursor['last_id'],
				min( 50, $batch )
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			self::next_phase( $cursor );
			return false;
		}

		foreach ( $rows as $row ) {
			$cursor['last_id'] = (int) $row['meta_id'];
			$data = json_decode( (string) $row['meta_value'], true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$ids  = array();
			$urls = array();
			self::collect_media_refs_recursive( $data, $ids, $urls );

			$images = MSW_Database::table( MSW_Database::IMAGES );

			// Attachment-id references.
			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$found = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, parent_file_id FROM {$images} WHERE attachment_id IN ({$placeholders}) AND status = 'active'", $ids ), // phpcs:ignore
					ARRAY_A
				);
				foreach ( (array) $found as $hit ) {
					$target = $hit['parent_file_id'] > 0 ? (int) $hit['parent_file_id'] : (int) $hit['id'];
					self::store_ref( $target, 'elementor', (int) $row['post_id'], '_elementor_data', $cursor );
				}
			}

			// URL references: Elementor widgets often store the file URL, not the id.
			$rels = array();
			foreach ( $urls as $url ) {
				if ( preg_match( '#wp-content/uploads/([^\s"\'\)\>\\\\]+)#i', $url, $mm ) ) {
					$rels[] = rawurldecode( $mm[1] );
				}
			}
			$rels = array_values( array_unique( $rels ) );
			if ( $rels ) {
				$placeholders = implode( ',', array_fill( 0, count( $rels ), '%s' ) );
				$found = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, parent_file_id FROM {$images} WHERE file_rel_path IN ({$placeholders}) AND status = 'active'", $rels ), // phpcs:ignore
					ARRAY_A
				);
				foreach ( (array) $found as $hit ) {
					$target = $hit['parent_file_id'] > 0 ? (int) $hit['parent_file_id'] : (int) $hit['id'];
					self::store_ref( $target, 'elementor', (int) $row['post_id'], '_elementor_data', $cursor );
				}
			}
		}

		return true;
	}

	/**
	 * Collect attachment ids and media URLs from Elementor data structures.
	 *
	 * @param array    $data Parsed JSON.
	 * @param int[]    $ids  Id accumulator.
	 * @param string[] $urls URL accumulator.
	 */
	protected static function collect_media_refs_recursive( $data, &$ids, &$urls ) {
		foreach ( $data as $key => $value ) {
			if ( 'id' === $key && is_numeric( $value ) && (int) $value > 0 ) {
				$ids[] = (int) $value;
			}

			if ( is_string( $value ) && false !== strpos( $value, '/uploads/' ) && ( 0 === strpos( $value, 'http' ) || 0 === strpos( $value, '/' ) ) ) {
				$urls[] = $value;
			}

			if ( is_array( $value ) ) {
				self::collect_media_refs_recursive( $value, $ids, $urls );
			}
		}
	}

	/**
	 * Phase: scan theme/plugin file contents for uploads references.
	 * File list is rebuilt per tick (stable scandir order); only the index persists.
	 *
	 * @param array $task   Task row.
	 * @param array $cursor Cursor.
	 * @param int   $batch  Batch size.
	 * @return bool True while phase continues.
	 */
	protected static function scan_files_step( $task, &$cursor, $batch ) {
		$scope = isset( $cursor['scope'] ) && 'plugins' === $cursor['scope'] ? 'plugins' : 'themes';
		$base  = 'plugins' === $scope ? WP_CONTENT_DIR . '/plugins' : WP_CONTENT_DIR . '/themes';

		$files = self::list_text_files( $base );
		$type  = 'plugins' === $scope ? 'plugin' : 'theme';

		$limit = min( 50, max( 10, (int) ( $batch / 4 ) ) );
		$total = count( $files );

		while ( $cursor['file_idx'] < $total && $limit > 0 ) {
			$path  = $files[ $cursor['file_idx'] ];
			$cursor['file_idx']++;
			$limit--;

			$size = @filesize( $path );
			if ( ! $size || $size > 512 * 1024 ) {
				continue;
			}

			$content = @file_get_contents( $path );
			if ( false === $content || false === strpos( $content, 'uploads' ) ) {
				continue;
			}

			$source = ltrim( substr( $path, strlen( WP_CONTENT_DIR ) ), '/' );
			$ext    = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			// Code files reference media for real; docs/READMEs only mention it.
			$strong = in_array( $ext, array( 'php', 'css', 'js', 'scss' ), true );
			self::find_refs_in_content( $content, $type, 0, $source, $cursor, $strong );
		}

		if ( $cursor['file_idx'] >= $total ) {
			self::next_phase( $cursor, $task );
			return false; // Phase handoff; the task loop moves to the next phase.
		}

		return true;
	}

	/**
	 * Text files under a directory (bounded walk).
	 *
	 * @param string $base Root dir.
	 * @return string[]
	 */
	protected static function list_text_files( $base ) {
		$out   = array();
		$exts  = array( 'css', 'js', 'php', 'html', 'htm', 'txt', 'json', 'scss' );
		$queue = array( $base );
		$limit = 20000;

		while ( $queue && count( $out ) < $limit ) {
			$dir     = array_shift( $queue );
			$entries = @scandir( $dir );
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '' === $entry || '.' === $entry[0] || '..' === $entry ) {
					continue;
				}
				$path = $dir . '/' . $entry;
				if ( is_dir( $path ) ) {
					$queue[] = $path;
				} elseif ( in_array( strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) ), $exts, true ) ) {
					$out[] = $path;
				}
			}
		}

		return $out;
	}

	/**
	 * Phase: aggregate statuses into wp_ms_images (paged by image id).
	 *
	 * @param array $cursor Cursor.
	 * @param int   $batch  Batch size.
	 * @return bool True while phase continues.
	 */
	protected static function finalize_step( &$cursor, $batch ) {
		global $wpdb;

		$images = MSW_Database::table( MSW_Database::IMAGES );
		$refs   = MSW_Database::table( MSW_Database::REFERENCES );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id, i.attachment_id,
					( SELECT COUNT(*) FROM {$refs} r WHERE r.image_id = i.id AND r.reference_type NOT IN ('post_content_maybe','theme_maybe','plugin_maybe') ) AS hard,
					( SELECT COUNT(*) FROM {$refs} r WHERE r.image_id = i.id AND r.reference_type IN ('post_content_maybe','theme_maybe','plugin_maybe') ) AS soft
				FROM {$images} i WHERE i.id > %d AND i.is_thumbnail = 0 AND i.status = 'active' ORDER BY i.id ASC LIMIT %d", // phpcs:ignore
				$cursor['last_id'],
				$batch
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			// All originals are classified: size variants mirror their parent.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$images} t JOIN {$images} p ON t.parent_file_id = p.id " // phpcs:ignore
					. 'SET t.reference_status = p.reference_status, t.reference_count = p.reference_count, t.analyzed_at = %s '
					. "WHERE t.is_thumbnail = 1 AND t.parent_file_id > 0 AND t.status = 'active'",
					current_time( 'mysql', true )
				)
			);
			self::next_phase( $cursor );
			return false;
		}

		$now = current_time( 'mysql', true );
		foreach ( $rows as $row ) {
			$cursor['last_id'] = (int) $row['id'];

			if ( 0 === (int) $row['attachment_id'] ) {
				$status = self::STATUS_ORPHAN;
			} elseif ( (int) $row['hard'] > 0 ) {
				$status = self::STATUS_USED;
			} elseif ( (int) $row['soft'] > 0 ) {
				$status = self::STATUS_MAYBE;
			} else {
				$status = self::STATUS_UNUSED;
			}

			$wpdb->update(
				$images,
				array(
					'reference_status' => $status,
					'reference_count'  => (int) $row['hard'],
					'analyzed_at'      => $now,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
		}

		return true;
	}

	/**
	 * Store one reference hit (deduped).
	 *
	 * @param int    $image_id Image row id.
	 * @param string $type     Reference type.
	 * @param int    $ref_id   Reference id.
	 * @param string $source   Source label.
	 * @param array  $cursor   Cursor (found counter updated in place).
	 */
	protected static function store_ref( $image_id, $type, $ref_id, $source, &$cursor ) {
		global $wpdb;

		if ( ! $image_id ) {
			return;
		}

		$table = MSW_Database::table( MSW_Database::REFERENCES );

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE image_id = %d AND reference_type = %s AND reference_id = %d AND source = %s LIMIT 1",
				$image_id,
				$type,
				$ref_id,
				$source
			)
		);
		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'image_id'       => $image_id,
				'reference_type' => $type,
				'reference_id'   => $ref_id,
				'source'         => $source,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		$cursor['found']++;
	}

	/**
	 * Analyze one image right now (single-image rescan for the "view references" UI).
	 * DB sources only; theme/plugin file hits require the full scan.
	 *
	 * @param int $image_id Image row id.
	 * @return array|WP_Error Updated row + references.
	 */
	public static function analyze_image( $image_id ) {
		global $wpdb;

		$image = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . MSW_Database::table( MSW_Database::IMAGES ) . ' WHERE id = %d', $image_id ),
			ARRAY_A
		);
		if ( ! $image ) {
			return new WP_Error( 'msw_refs', 'Image not found.' );
		}

		$refs_table = MSW_Database::table( MSW_Database::REFERENCES );
		$wpdb->delete( $refs_table, array( 'image_id' => $image_id ), array( '%d' ) );

		$cursor = array( 'found' => 0 );
		$att_id = (int) $image['attachment_id'];
		$rel    = $image['file_rel_path'];
		$name   = $image['file_name'];

		if ( $att_id ) {
			// post_content: exact rel path, then bare name.
			$like_exact = '%' . $wpdb->esc_like( $rel ) . '%';
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status NOT IN ('trash','auto-draft') LIMIT 50",
					$like_exact
				),
				ARRAY_A
			);
			foreach ( (array) $posts as $post ) {
				self::store_ref( $image_id, 'post_content', (int) $post['ID'], $post['post_title'], $cursor );
				if ( false !== strpos( $post['post_content'], '<!-- wp:' )
					&& ( preg_match( '/"id":\s*' . $att_id . '\b/', $post['post_content'] )
						|| preg_match( '/\bid=["\']' . $att_id . '["\']/', $post['post_content'] ) ) ) {
					self::store_ref( $image_id, 'gutenberg', (int) $post['ID'], $post['post_title'], $cursor );
				}
			}

			// Featured image.
			$featured = $wpdb->get_col(
				$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 100", (string) $att_id )
			);
			foreach ( (array) $featured as $post_id ) {
				self::store_ref( $image_id, 'featured', (int) $post_id, '_thumbnail_id', $cursor );
			}

			// WooCommerce gallery.
			$galleries = $wpdb->get_results(
				$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND FIND_IN_SET( %d, meta_value ) LIMIT 100", $att_id ),
				ARRAY_A
			);
			foreach ( (array) $galleries as $row ) {
				self::store_ref( $image_id, 'woocommerce', (int) $row['post_id'], '_product_image_gallery', $cursor );
			}

			// Elementor.
			$elementor = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND ( meta_value LIKE %s OR meta_value LIKE %s ) LIMIT 50",
					'%' . $wpdb->esc_like( $rel ) . '%',
					'%' . $wpdb->esc_like( $name ) . '%'
				),
				ARRAY_A
			);
			foreach ( (array) $elementor as $row ) {
				$ids = array();
				$urls = array();
				$data = json_decode( (string) $row['meta_value'], true );
				if ( is_array( $data ) ) {
					self::collect_media_refs_recursive( $data, $ids, $urls );
				}
				if ( in_array( $att_id, $ids, true ) || false !== strpos( (string) $row['meta_value'], $rel ) ) {
					self::store_ref( $image_id, 'elementor', (int) $row['post_id'], '_elementor_data', $cursor );
				}
			}

			// Bare-name fuzzy hits (maybe).
			$fuzzy = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_content NOT LIKE %s AND post_status NOT IN ('trash','auto-draft') LIMIT 50",
					'%' . $wpdb->esc_like( $name ) . '%',
					$like_exact
				),
				ARRAY_A
			);
			foreach ( (array) $fuzzy as $post ) {
				self::store_ref( $image_id, 'post_content_maybe', (int) $post['ID'], $post['post_title'], $cursor );
			}
		}

		// Re-aggregate status for this row.
		$hard = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$refs_table} WHERE image_id = %d AND reference_type NOT IN ('post_content_maybe','theme_maybe','plugin_maybe')", $image_id ) );
		$soft = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$refs_table} WHERE image_id = %d AND reference_type IN ('post_content_maybe','theme_maybe','plugin_maybe')", $image_id ) );

		$status = self::STATUS_UNUSED;
		if ( 0 === $att_id ) {
			$status = self::STATUS_ORPHAN;
		} elseif ( $hard > 0 ) {
			$status = self::STATUS_USED;
		} elseif ( $soft > 0 ) {
			$status = self::STATUS_MAYBE;
		}

		$wpdb->update(
			MSW_Database::table( MSW_Database::IMAGES ),
			array(
				'reference_status' => $status,
				'reference_count'  => $hard,
				'analyzed_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $image_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);

		$references = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$refs_table} WHERE image_id = %d ORDER BY reference_type, id", $image_id ),
			ARRAY_A
		);

		// Attach human labels.
		foreach ( (array) $references as &$ref ) {
			$ref['label'] = self::reference_label( $ref );
		}

		return array(
			'image'      => $image,
			'status'     => $status,
			'references' => $references,
		);
	}

	/**
	 * Human readable label for one reference row.
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
					$post = get_post( (int) $ref['reference_id'] );
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
			case 'theme_maybe':
				return 'Theme file (name match): ' . $ref['source'];
			case 'plugin_maybe':
				return 'Plugin file (name match): ' . $ref['source'];
			case 'theme':
				return 'Theme file: ' . $ref['source'];
			case 'plugin':
				return 'Plugin file: ' . $ref['source'];
		}
		return $ref['source'];
	}
}

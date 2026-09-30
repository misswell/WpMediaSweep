<?php
/**
 * Plugin orchestrator: hooks, REST routes, task handler registration, assets.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Plugin {

	/** @var MSW_Plugin|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		// Upgrade tables if the schema changed.
		add_action( 'admin_init', array( 'MSW_Database', 'maybe_upgrade' ) );

		// Task handlers.
		MSW_Scanner::register_handler();
		MSW_Reference_Detector::register_handler();
		MSW_Compressor::register_handler();

		// Cron.
		MSW_Cron::init();

		// Admin UI.
		if ( is_admin() ) {
			MSW_Admin::init();
		}

		// WP-CLI.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			MSW_CLI::register();
		}

		// On-upload auto compression (after WordPress generated all size variants).
		if ( MSW_Settings::get( 'auto_compress', false ) ) {
			add_action( 'wp_generate_attachment_metadata', array( $this, 'auto_compress' ), 999, 2 );
		}

		// REST API.
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );

		// Schema bump for multisite-friendly defaults.
		register_activation_hook( MSW_PLUGIN_FILE, array( 'MSW_Cron', 'init' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function rest_api_init() {
		// Stats.
		register_rest_route( 'mediasweep/v1', '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_stats' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Duplicate groups (identical md5).
		register_rest_route( 'mediasweep/v1', '/duplicates', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_duplicates' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
			'args'                => array(
				'limit' => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );

		// Compression dry-run estimate.
		register_rest_route( 'mediasweep/v1', '/estimate', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_estimate' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Images list.
		register_rest_route( 'mediasweep/v1', '/images', array(
			'methods'             => 'GET',
			'callback'            => array( 'MSW_Rest_Images', 'list_images' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
			'args'                => array(
				'status'     => array( 'type' => 'string', 'enum' => array( 'all', 'used', 'maybe', 'unused', 'orphan' ) ),
				'compressed' => array( 'type' => 'string', 'enum' => array( 'all', '0', '1' ) ),
				'search'     => array( 'type' => 'string' ),
				'page'       => array( 'type' => 'integer', 'default' => 1 ),
				'per_page'   => array( 'type' => 'integer', 'default' => 50 ),
			),
		) );

		// Single image references.
		register_rest_route( 'mediasweep/v1', '/images/(?P<id>\d+)/references', array(
			'methods'             => 'GET',
			'callback'            => array( 'MSW_Rest_Images', 'references' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Single image analyze (rescan references).
		register_rest_route( 'mediasweep/v1', '/images/(?P<id>\d+)/analyze', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Images', 'analyze' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Single image restore (un-compress).
		register_rest_route( 'mediasweep/v1', '/images/(?P<id>\d+)/restore', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Images', 'restore' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Scan (start/resume full media scan).
		register_rest_route( 'mediasweep/v1', '/scan', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Scan', 'start_scan' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Reference scan.
		register_rest_route( 'mediasweep/v1', '/scan/references', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Scan', 'start_reference_scan' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Compress: single id, selected ids, or everything uncompressed.
		register_rest_route( 'mediasweep/v1', '/compress', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Compress', 'compress' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
			'args'                => array(
				'id'    => array( 'type' => 'integer' ),
				'ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'force' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );

		// Cleanup: move selected images to trash.
		register_rest_route( 'mediasweep/v1', '/delete', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Images', 'trash' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
			'args'                => array(
				'ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'required' => true ),
			),
		) );

		// Trash listing / restore.
		register_rest_route( 'mediasweep/v1', '/trash', array(
			'methods'             => 'GET',
			'callback'            => array( 'MSW_Rest_Images', 'trash_list' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'mediasweep/v1', '/trash/(?P<token>[a-zA-Z0-9\-]+)/restore', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Images', 'trash_restore' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Tasks.
		register_rest_route( 'mediasweep/v1', '/tasks', array(
			'methods'             => 'GET',
			'callback'            => array( 'MSW_Rest_Tasks', 'list_tasks' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );
		register_rest_route( 'mediasweep/v1', '/tasks/(?P<id>\d+)/(?P<action>pause|resume|cancel)', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Tasks', 'control' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Tick: drive the task loop from the frontend (cron is the fallback).
		register_rest_route( 'mediasweep/v1', '/tick', array(
			'methods'             => 'POST',
			'callback'            => array( 'MSW_Rest_Tasks', 'tick' ),
			'permission_callback' => array( __CLASS__, 'rest_permission' ),
		) );

		// Settings.
		register_rest_route( 'mediasweep/v1', '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'MSW_Rest_Settings', 'get' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'MSW_Rest_Settings', 'update' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			),
		) );

		// Logs.
		register_rest_route( 'mediasweep/v1', '/logs', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'MSW_Rest_Settings', 'logs' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => array(
					'limit' => array( 'type' => 'integer', 'default' => 100 ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( 'MSW_Rest_Settings', 'clear_logs' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
			),
		) );
	}

	/**
	 * All routes require manage_options.
	 *
	 * @return bool
	 */
	public static function rest_permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Compress a freshly uploaded original once its size variants exist.
	 * Failures never break the upload — the image simply stays uncompressed.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment id.
	 * @return array Unchanged metadata.
	 */
	public function auto_compress( $metadata, $attachment_id ) {
		global $wpdb;

		// Only our supported mime types, originals only (not size variants).
		$post = get_post( $attachment_id );
		if ( ! $post || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return $metadata;
		}

		$rel = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $rel ) {
			return $metadata;
		}

		$table = MSW_Database::table( MSW_Database::IMAGES );
		$id    = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE file_rel_path = %s AND is_thumbnail = 0", $rel )
		);
		if ( ! $id ) {
			$id = MSW_Scanner::index_attachment( $attachment_id, $rel );
		}

		if ( $id ) {
			$result = MSW_Compressor::compress_image( $id );
			if ( is_wp_error( $result ) ) {
				MSW_Logger::info( 'auto', sprintf( 'Auto-compress skipped for attachment #%d: %s', $attachment_id, $result->get_error_message() ) );
			}
		}

		return $metadata;
	}

	/**
	 * Dry-run estimate for "compress everything pending": counts, bytes and a
	 * projection from the average ratio achieved on already-compressed images.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_estimate() {
		global $wpdb;

		$images = MSW_Database::table( MSW_Database::IMAGES );

		$row = $wpdb->get_row(
			"SELECT
				COALESCE( SUM( is_thumbnail = 0 AND compressed = 0 AND file_size > 0 ), 0 ) AS pending_count,
				COALESCE( SUM( CASE WHEN is_thumbnail = 0 AND compressed = 0 THEN file_size ELSE 0 END ), 0 ) AS pending_bytes,
				COALESCE( SUM( CASE WHEN is_thumbnail = 0 AND compressed = 1 THEN original_size ELSE 0 END ), 0 ) AS done_original_bytes,
				COALESCE( SUM( CASE WHEN is_thumbnail = 0 AND compressed = 1 THEN compressed_size ELSE 0 END ), 0 ) AS done_compressed_bytes
			FROM {$images} WHERE status = 'active'", // phpcs:ignore
			ARRAY_A
		);

		$pending_count  = (int) ( $row['pending_count'] ?? 0 );
		$pending_bytes  = (int) ( $row['pending_bytes'] ?? 0 );
		$done_original  = (int) ( $row['done_original_bytes'] ?? 0 );
		$done_compressed = (int) ( $row['done_compressed_bytes'] ?? 0 );

		// Average achieved ratio; fall back to a conservative 15% for fresh installs.
		$ratio = $done_original > 0 ? ( 1 - $done_compressed / $done_original ) : 0.15;

		return rest_ensure_response(
			array(
				'pending_count'     => $pending_count,
				'pending_bytes'     => $pending_bytes,
				'estimated_savings' => (int) round( $pending_bytes * $ratio ),
				'estimated_ratio'   => round( $ratio * 100, 1 ),
				'basis'             => $done_original > 0 ? 'average of already-compressed images' : 'default assumption (15%)',
			)
		);
	}

	/**
	 * Aggregate statistics for the dashboard.
	 * File counts cover every indexed file; the compression/reference counters
	 * are scoped to original images (size variants follow their parent).
	 *
	 * @return array
	 */
	public static function rest_stats() {
		global $wpdb;

		$images = MSW_Database::table( MSW_Database::IMAGES );

		$row = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total_files,
				COALESCE( SUM( file_size ), 0 ) AS total_size,
				COALESCE( SUM( is_thumbnail = 0 ), 0 ) AS original_files,
				COALESCE( SUM( is_thumbnail = 0 AND compressed = 1 ), 0 ) AS compressed,
				COALESCE( SUM( is_thumbnail = 0 AND compressed = 0 AND file_size > 0 ), 0 ) AS pending,
				COALESCE( SUM( CASE WHEN is_thumbnail = 0 AND compressed = 1 THEN original_size - compressed_size ELSE 0 END ), 0 ) AS saved,
				COALESCE( SUM( CASE WHEN is_thumbnail = 0 AND compressed = 1 THEN compressed_size ELSE 0 END ), 0 ) AS compressed_total,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'unused' ), 0 ) AS unused,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'maybe' ), 0 ) AS maybe_used,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'orphan' ), 0 ) AS orphan,
				COALESCE( SUM( is_thumbnail = 0 AND reference_status = 'used' ), 0 ) AS used,
				COALESCE( SUM( is_thumbnail = 0 AND compressed = 0 AND reference_status = 'unused' ), 0 ) AS unused_uncompressed
			FROM {$images} WHERE status = 'active'", // phpcs:ignore
			ARRAY_A
		);

		$stats = array(
			'total_files'      => (int) ( $row['total_files'] ?? 0 ),
			'total_size'       => (int) ( $row['total_size'] ?? 0 ),
			'original_files'   => (int) ( $row['original_files'] ?? 0 ),
			'compressed'       => (int) ( $row['compressed'] ?? 0 ),
			'pending'          => (int) ( $row['pending'] ?? 0 ),
			'saved'            => (int) ( $row['saved'] ?? 0 ),
			'compressed_total' => (int) ( $row['compressed_total'] ?? 0 ),
			'unused'           => (int) ( $row['unused'] ?? 0 ),
			'maybe_used'       => (int) ( $row['maybe_used'] ?? 0 ),
			'orphan'           => (int) ( $row['orphan'] ?? 0 ),
			'used'             => (int) ( $row['used'] ?? 0 ),
			'releasable'       => 0,
			'analyzed'         => 0,
			'backends'         => array(
				'imagick' => MSW_Backend_Imagick::available(),
				'gd'      => MSW_Backend_GD::available(),
			),
		);

		// Releasable estimate: bytes of unused/orphan originals (incl. their size variants).
		$stats['releasable'] = (int) $wpdb->get_var(
			"SELECT COALESCE( SUM( t.file_size ), 0 ) FROM {$images} t
				LEFT JOIN {$images} p ON t.parent_file_id = p.id AND t.is_thumbnail = 1
				WHERE t.status = 'active' AND ( ( t.is_thumbnail = 0 AND t.reference_status IN ('unused','orphan') )
				   OR ( t.is_thumbnail = 1 AND p.reference_status IN ('unused','orphan') ) )" // phpcs:ignore
		);

		// Duplicate images: identical md5 among originals.
		$dup = $wpdb->get_row(
			"SELECT COUNT(*) AS groups_count, COALESCE( SUM( files ), 0 ) AS files_count, COALESCE( SUM( excess ), 0 ) AS excess_size
			FROM (
				SELECT COUNT(*) AS files, SUM( file_size ) - MAX( file_size ) AS excess
				FROM {$images}
				WHERE is_thumbnail = 0 AND md5_hash <> '' AND status = 'active'
				GROUP BY md5_hash HAVING COUNT(*) > 1
			) d", // phpcs:ignore
			ARRAY_A
		);

		$stats['duplicate_groups']   = (int) ( $dup['groups_count'] ?? 0 );
		$stats['duplicate_files']    = (int) ( $dup['files_count'] ?? 0 );
		$stats['duplicate_savings']  = (int) ( $dup['excess_size'] ?? 0 );

		$stats['analyzed'] = $stats['used'] + $stats['unused'] + $stats['maybe_used'] + $stats['orphan'];

		return rest_ensure_response( $stats );
	}

	/**
	 * Duplicate groups (identical md5 among original images).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_duplicates( $request ) {
		global $wpdb;

		$images = MSW_Database::table( MSW_Database::IMAGES );
		$limit  = min( 100, max( 1, (int) $request->get_param( 'limit' ) ) );

		$groups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT md5_hash, COUNT(*) AS files, SUM( file_size ) AS total_size, SUM( file_size ) - MAX( file_size ) AS excess_size
				FROM {$images}
				WHERE is_thumbnail = 0 AND md5_hash <> '' AND status = 'active'
				GROUP BY md5_hash HAVING COUNT(*) > 1
				ORDER BY excess_size DESC
				LIMIT %d", // phpcs:ignore
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $groups as $group ) {
			$files = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, attachment_id, file_name, file_rel_path, file_size, width, height, reference_status
					FROM {$images} WHERE is_thumbnail = 0 AND md5_hash = %s ORDER BY id ASC", // phpcs:ignore
					$group['md5_hash']
				),
				ARRAY_A
			);

			foreach ( $files as &$file ) {
				$file['id']            = (int) $file['id'];
				$file['attachment_id'] = (int) $file['attachment_id'];
				$file['file_size']     = (int) $file['file_size'];
				$file['risk_level']    = MSW_Rest_Images::risk_level( $file );
				$file['thumbnail_url'] = $file['attachment_id'] ? wp_get_attachment_image_url( (int) $file['attachment_id'], array( 150, 150 ) ) : '';
			}

			$out[] = array(
				'md5'         => $group['md5_hash'],
				'count'       => (int) $group['files'],
				'total_size'  => (int) $group['total_size'],
				'excess_size' => (int) $group['excess_size'],
				'files'       => $files,
			);
		}

		return rest_ensure_response(
			array(
				'groups' => $out,
				'total_groups' => count( $out ),
			)
		);
	}
}

// REST controller classes.
require_once MSW_PLUGIN_DIR . 'api/class-rest-images.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-scan.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-compress.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-tasks.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-settings.php';

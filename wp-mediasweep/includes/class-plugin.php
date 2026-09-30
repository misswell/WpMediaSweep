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
	 * Aggregate statistics for the dashboard.
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
				COALESCE( SUM( compressed = 1 ), 0 ) AS compressed,
				COALESCE( SUM( compressed = 0 AND file_size > 0 ), 0 ) AS pending,
				COALESCE( SUM( CASE WHEN compressed = 1 THEN original_size - compressed_size ELSE 0 END ), 0 ) AS saved,
				COALESCE( SUM( original_size ), 0 ) AS original_total,
				COALESCE( SUM( CASE WHEN compressed = 1 THEN compressed_size ELSE 0 END ), 0 ) AS compressed_total,
				COALESCE( SUM( reference_status = 'unused' ), 0 ) AS unused,
				COALESCE( SUM( reference_status = 'maybe' ), 0 ) AS maybe_used,
				COALESCE( SUM( reference_status = 'orphan' ), 0 ) AS orphan,
				COALESCE( SUM( reference_status = 'used' ), 0 ) AS used
			FROM {$images}", // phpcs:ignore
			ARRAY_A
		);

		$stats = array(
			'total_files'      => (int) ( $row['total_files'] ?? 0 ),
			'total_size'       => (int) ( $row['total_size'] ?? 0 ),
			'compressed'       => (int) ( $row['compressed'] ?? 0 ),
			'pending'          => (int) ( $row['pending'] ?? 0 ),
			'saved'            => (int) ( $row['saved'] ?? 0 ),
			'original_total'   => (int) ( $row['original_total'] ?? 0 ),
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

		// Releasable estimate: uncompressed bytes of unused/orphan images.
		$stats['releasable'] = (int) $wpdb->get_var(
			"SELECT COALESCE( SUM( file_size ), 0 ) FROM {$images} WHERE reference_status IN ('unused','orphan')" // phpcs:ignore
		);

		$stats['analyzed'] = $stats['used'] + $stats['unused'] + $stats['maybe_used'] + $stats['orphan'];

		return rest_ensure_response( $stats );
	}
}

// REST controller classes.
require_once MSW_PLUGIN_DIR . 'api/class-rest-images.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-scan.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-compress.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-tasks.php';
require_once MSW_PLUGIN_DIR . 'api/class-rest-settings.php';

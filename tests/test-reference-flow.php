<?php
/**
 * Control-flow test for the reference-scan task loop (no database needed).
 *
 * Reproduces the bug where the elementor->finalize phase hand-off made the
 * loop believe the task was finished before finalize ever ran.
 *
 * Usage: php tests/test-reference-flow.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'MSW_PLUGIN_DIR' ) ) {
	define( 'MSW_PLUGIN_DIR', __DIR__ . '/../wp-mediasweep/' );
}

// --- Minimal WP stubs -------------------------------------------------------
class WP_Error {
	protected $code;
	protected $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function current_time( $type, $gmt = 0 ) {
	return gmdate( 'Y-m-d H:i:s' );
}
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}
function get_post_types( $args = array(), $output = 'names' ) {
	return array( 'post', 'page', 'wp_block' );
}
function esc_sql( $s ) {
	return addslashes( $s );
}
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
class MSW_Logger {
	public static function info( $c, $m ) {}
	public static function warn( $c, $m ) {}
	public static function error( $c, $m ) {}
}
class MSW_Database {
	const IMAGES     = 'ms_images';
	const REFERENCES = 'ms_references';
	const TASKS      = 'ms_tasks';
	const LOGS       = 'ms_logs';

	public static function table( $t ) {
		return 'wp_' . $t;
	}
}
class MSW_Settings {
	public static function get( $k, $d = null ) {
		return 200;
	}
}

/**
 * Mock wpdb that routes phase queries to scripted result sets and records
 * every UPDATE so we can prove finalize actually wrote the statuses.
 */
class MockWpdb {
	public $prefix = 'wp_';
	public $posts  = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $updates          = array();
	public $last_error       = '';
	private $finalize_rounds = 0;
	private $posts_rounds    = 0;

	public function prepare( $query, ...$args ) {
		return $query;
	}
	public function esc_like( $s ) {
		return $s;
	}

	public function get_results( $query, $output = null ) {
		if ( false !== strpos( $query, 'AS hard' ) ) {
			// Finalize aggregation query: yield rows once, then stop.
			if ( 0 === $this->finalize_rounds ) {
				$this->finalize_rounds++;
				return array(
					array( 'id' => 1, 'attachment_id' => 4, 'hard' => 1, 'soft' => 0 ),
					array( 'id' => 2, 'attachment_id' => 5, 'hard' => 0, 'soft' => 1 ),
					array( 'id' => 3, 'attachment_id' => 6, 'hard' => 0, 'soft' => 0 ),
				);
			}
			return array();
		}
		if ( false !== strpos( $query, 'post_title, post_content' ) ) {
			// One batch of posts, then no more (paged by ID).
			if ( 0 === $this->posts_rounds ) {
				$this->posts_rounds++;
				return array(
					array( 'ID' => 7, 'post_title' => 'P', 'post_content' => '' ),
				);
			}
			return array();
		}
		// All other phase queries (featured/woo/elementor) report no rows.
		return array();
	}

	public function get_row( $query, $output = null ) {
		return null;
	}
	public function get_col( $query ) {
		return array();
	}
	public function get_var( $query ) {
		return 0;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array( $table, $data, $where );
		return 1;
	}
	public function insert( $table, $data, $format = null ) {
		return 1;
	}
	public function delete( $table, $where, $format = null ) {
		return 1;
	}
	public function query( $query ) {
		return 0;
	}
}

$GLOBALS['wpdb']     = new MockWpdb();
$GLOBALS['msw_task'] = array(
	'id'      => 9,
	'type'    => 'reference_scan',
	'status'  => 'running',
	'cursor'  => array(),
	'options' => array(),
);

// Task manager capture shim.
class MSW_Task_Manager {
	public static $saved_cursors = array();
	public static function register( $t, $h ) {}
	public static function get( $id ) {
		return null;
	}
	public static function set_cursor( $id, $cursor ) {
		self::$saved_cursors[] = $cursor;
	}
	public static function update( $id, $fields ) {}
}

require MSW_PLUGIN_DIR . 'includes/class-reference-detector.php';

// --- Run the task loop against the mock -------------------------------------
$task   = $GLOBALS['msw_task'];
$more   = MSW_Reference_Detector::run_task( $task, 10 );
$called = $GLOBALS['wpdb']->updates;

$failures = 0;
function check( $label, $cond, $extra = '' ) {
	echo ( $cond ? '  ok  ' : ' FAIL ' ) . $label . ( '' !== $extra ? " [{$extra}]" : '' ) . "\n";
	if ( ! $cond ) {
		$GLOBALS['failures']++;
	}
}

check( 'run_task reports the scan finished', false === $more, 'more=' . var_export( $more, true ) );
check( 'finalize wrote 3 image status rows', 3 === count( $called ), 'updates=' . count( $called ) );

$statuses = array();
foreach ( $called as $update ) {
	$statuses[] = $update[1]['reference_status'];
}
check( 'status #1 used', 'used' === $statuses[0] );
check( 'status #2 maybe', 'maybe' === $statuses[1] );
check( 'status #3 unused', 'unused' === $statuses[2] );

$phases = array();
foreach ( MSW_Task_Manager::$saved_cursors as $cursor ) {
	$phases[] = $cursor['phase'];
}
check( 'every phase was visited', array( 'posts', 'featured', 'woo', 'elementor', 'finalize' ) === array_values( array_unique( $phases ) ), implode( ',', array_unique( $phases ) ) );
check( 'loop ended inside finalize', 'finalize' === end( $phases ) );

echo "\n" . ( 0 === $failures ? "REFERENCE FLOW TEST PASSED\n" : $failures . " CHECK(S) FAILED\n" );
exit( $failures ? 1 : 0 );

<?php
/** Local regression tests using real plugin classes, GD and an isolated SQLite wpdb adapter. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MSW_PLUGIN_DIR', __DIR__ . '/../wp-mediasweep/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error {
	private $code; private $message;
	public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function size_format( $v ) { return (string) $v; }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['fixture_root'] ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0755, true ); }
function wp_json_encode( $v, $flags = 0 ) {
	if ( isset( $GLOBALS['manifest_hook'] ) && isset( $v['files'] ) ) {
		$hook = $GLOBALS['manifest_hook']; unset( $GLOBALS['manifest_hook'] ); $hook( $v );
	}
	return json_encode( $v, $flags );
}
function get_post( $id ) { return $GLOBALS['fixture_posts'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s', $id, $key ) );
}
function wp_trash_post( $id ) { $GLOBALS['fixture_posts'][ $id ]->post_status = 'trash'; return true; }
function wp_untrash_post( $id ) { $GLOBALS['fixture_posts'][ $id ]->post_status = 'inherit'; return true; }
function wp_delete_post( $id, $force ) { unset( $GLOBALS['fixture_posts'][ $id ] ); return true; }
function parse_blocks( $content ) {
	preg_match( '/<!-- wp:[\w\/]+ (\{.*?\})/', $content, $m );
	return array( array( 'attrs' => isset( $m[1] ) ? json_decode( $m[1], true ) : array(), 'innerBlocks' => array() ) );
}
class MSW_Settings {
	public static $values = array();
	public static function get( $key, $default = null ) { return self::$values[ $key ] ?? $default; }
}
class MSW_Logger {
	public static function info( $c, $m ) {}
	public static function warn( $c, $m ) {}
	public static function error( $c, $m ) {}
}
class FixtureWpdb {
	public $prefix = 'wp_'; public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
	public $last_error = ''; public $insert_id = 0; public $pdo; public $delay_compression = false;
	public static $locks = array();
	public function __construct( $pdo ) { $this->pdo = $pdo; }
	public function esc_like( $v ) { return addcslashes( $v, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ]; return '%s' === $m[0] ? $this->pdo->quote( (string) $v ) : (string) (float) $v;
		}, $sql );
	}
	public function query( $sql ) {
		$this->last_error = '';
		try { return $this->pdo->exec( 'START TRANSACTION' === $sql ? 'BEGIN' : $sql ); }
		catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return false; }
	}
	public function get_results( $sql, $format = null ) {
		$this->last_error = '';
		try { return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
		catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return null; }
	}
	public function get_row( $sql, $format = null ) { return $this->get_results( $sql )[0] ?? null; }
	public function get_var( $sql ) {
		if ( preg_match( '/SELECT (GET_LOCK|RELEASE_LOCK)\(\'([^\']+)\'/', $sql, $m ) ) {
			$key = $m[2]; $owner = spl_object_id( $this );
			if ( 'GET_LOCK' === $m[1] ) {
				if ( isset( self::$locks[ $key ] ) && self::$locks[ $key ] !== $owner ) { return '0'; }
				self::$locks[ $key ] = $owner; return '1';
			}
			if ( ( self::$locks[ $key ] ?? null ) === $owner ) { unset( self::$locks[ $key ] ); return '1'; }
			return '0';
		}
		$row = $this->get_row( $sql ); return $row ? reset( $row ) : null;
	}
	public function get_col( $sql ) { return array_map( function ( $r ) { return reset( $r ); }, $this->get_results( $sql ) ?? array() ); }
	public function insert( $table, $data, $formats = null ) {
		$sql = 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', array_fill( 0, count( $data ), '?' ) ) . ')';
		try { $s = $this->pdo->prepare( $sql ); $s->execute( array_values( $data ) ); $this->insert_id = (int) $this->pdo->lastInsertId(); return $s->rowCount(); }
		catch ( Throwable $e ) { $this->last_error = $e->getMessage(); return false; }
	}
	public function update( $table, $data, $where, $formats = null, $whereformats = null ) {
		if ( $this->delay_compression && isset( $data['compressed'] ) ) { usleep( 10000 ); $this->delay_compression = false; }
		$set = implode( ',', array_map( function ( $k ) { return $k . '=?'; }, array_keys( $data ) ) );
		$filter = implode( ' AND ', array_map( function ( $k ) { return $k . '=?'; }, array_keys( $where ) ) );
		$s = $this->pdo->prepare( "UPDATE {$table} SET {$set} WHERE {$filter}" ); $s->execute( array_merge( array_values( $data ), array_values( $where ) ) ); return $s->rowCount();
	}
	public function delete( $table, $where, $formats = null ) {
		$filter = implode( ' AND ', array_map( function ( $k ) { return $k . '=?'; }, array_keys( $where ) ) );
		$s = $this->pdo->prepare( "DELETE FROM {$table} WHERE {$filter}" ); $s->execute( array_values( $where ) ); return $s->rowCount();
	}
}
$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->sqliteCreateFunction( 'FIND_IN_SET', function ( $needle, $haystack ) { return in_array( (string) $needle, explode( ',', $haystack ), true ) ? 1 : 0; } );
$pdo->exec( "CREATE TABLE wp_ms_images (id INTEGER PRIMARY KEY AUTOINCREMENT, attachment_id INTEGER DEFAULT 0, file_path TEXT, file_rel_path TEXT, file_name TEXT, mime_type TEXT, width INTEGER, height INTEGER, file_size INTEGER, file_mtime INTEGER, md5_hash TEXT, is_thumbnail INTEGER DEFAULT 0, parent_file_id INTEGER DEFAULT 0, status TEXT DEFAULT 'active', compressed INTEGER DEFAULT 0, compressed_at TEXT, original_size INTEGER, compressed_size INTEGER, compression_ratio REAL, reference_status TEXT DEFAULT 'unknown', reference_count INTEGER DEFAULT 0, analyzed_at TEXT, created_at TEXT, updated_at TEXT)" );
$pdo->exec( 'CREATE TABLE wp_ms_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, status TEXT, total INTEGER, processed INTEGER, failed INTEGER, task_cursor TEXT, task_options TEXT, created_at TEXT, updated_at TEXT)' );
$pdo->exec( 'CREATE TABLE wp_ms_references (id INTEGER PRIMARY KEY AUTOINCREMENT, image_id INTEGER, reference_type TEXT, reference_id INTEGER, source TEXT, created_at TEXT)' );
$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT, post_content TEXT, post_status TEXT, post_type TEXT)' );
$pdo->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$wpdb = new FixtureWpdb( $pdo );
foreach ( array( 'database', 'files', 'task-manager', 'scanner', 'compressor', 'cleaner', 'reference-detector', 'plugin' ) as $class ) { require MSW_PLUGIN_DIR . 'includes/class-' . $class . '.php'; }
require MSW_PLUGIN_DIR . 'engine/octo-engine/class-octo-engine.php';
$fixture_root = sys_get_temp_dir() . '/msw-regression-' . bin2hex( random_bytes( 5 ) );
mkdir( $fixture_root );
$fixture_posts = array();
$failures = 0;
function check( $label, $condition ) {
	global $failures; echo ( $condition ? '  ok  ' : ' FAIL ' ) . $label . "\n"; if ( ! $condition ) { $failures++; }
}
function make_image( $name, $attachment = 0 ) {
	global $wpdb, $fixture_root;
	$path = $fixture_root . '/' . $name; wp_mkdir_p( dirname( $path ) );
	$im = imagecreatetruecolor( 240, 180 );
	for ( $x = 0; $x < 240; $x += 3 ) { for ( $y = 0; $y < 180; $y += 3 ) {
		imagefilledrectangle( $im, $x, $y, $x + 2, $y + 2, imagecolorallocate( $im, ( $x * 31 + $y ) % 256, ( $y * 17 + $x ) % 256, ( $x + $y * 11 ) % 256 ) );
	} }
	imagejpeg( $im, $path, 100 );
	$wpdb->insert( 'wp_ms_images', array( 'attachment_id' => $attachment, 'file_path' => $path, 'file_rel_path' => $name, 'file_name' => basename( $name ), 'file_size' => filesize( $path ) ) );
	return $wpdb->insert_id;
}
function reset_tasks() { global $wpdb; $wpdb->query( 'DELETE FROM wp_ms_tasks' ); }

// Two request connections compete for a real task; the second may not run it.
$calls = 0;
MSW_Task_Manager::register( 'probe', function () use ( &$calls, $pdo ) {
	global $wpdb; $calls++;
	if ( 1 === $calls ) { $original = $wpdb; $wpdb = new FixtureWpdb( $pdo ); MSW_Task_Manager::tick(); $wpdb = $original; }
	return false;
} );
MSW_Task_Manager::create( 'probe' ); MSW_Task_Manager::tick();
check( 'overlapping request does not execute the running handler', 1 === $calls );
foreach ( array( 'cancel' => 'cancelled', 'pause' => 'paused' ) as $method => $expected ) {
	reset_tasks();
	MSW_Task_Manager::register( 'probe', function ( $task ) use ( $method ) { MSW_Task_Manager::$method( $task['id'] ); return false; } );
	$task = MSW_Task_Manager::create( 'probe' ); MSW_Task_Manager::tick();
	check( $method . ' survives final-step completion', $expected === MSW_Task_Manager::get( $task['id'] )['status'] );
}
reset_tasks();
MSW_Task_Manager::register( 'probe', function () { throw new Error( 'fixture failure' ); } );
$task = MSW_Task_Manager::create( 'probe' ); MSW_Task_Manager::tick();
check( 'PHP Error requeues the task and releases its worker lock', 'queued' === MSW_Task_Manager::get( $task['id'] )['status'] && ! FixtureWpdb::$locks );

// Force recompression must preserve the original generation, even if no improvement.
$id = make_image( 'backup.jpg' ); $path = $fixture_root . '/backup.jpg'; $hash = hash_file( 'sha256', $path );
$result = MSW_Compressor::compress_image( $id );
check( 'first compression succeeds', ! is_wp_error( $result ) );
chmod( $path . '.ms-original', 0444 );
MSW_Compressor::compress_image( $id, true );
check( 'recompression preserves a read-only original backup', $hash === hash_file( 'sha256', $path . '.ms-original' ) );
MSW_Compressor::restore_image( $id );
check( 'restore recovers exact original bytes', $hash === hash_file( 'sha256', $path ) );

// Persist selection position across separate budgets, and skip empty batches.
MSW_Settings::$values['batch_size'] = 2;
$ids = array( make_image( 'select1.jpg' ), make_image( 'select2.jpg' ), make_image( 'select3.jpg' ) );
reset_tasks(); $task = MSW_Task_Manager::create( 'compress', array( 'ids' => $ids ), 3 );
$wpdb->delay_compression = true;
$more = MSW_Compressor::run_task( $task, 0.005 );
$fresh = MSW_Task_Manager::get( $task['id'] );
check( 'selection cursor advances after the first batch', 2 === $fresh['cursor']['selection_offset'] );
$more = MSW_Compressor::run_task( $fresh, 20 );
check( 'second tick reaches the selection tail and completes', false === $more && 1 === (int) $wpdb->get_var( 'SELECT compressed FROM wp_ms_images WHERE id=' . $ids[2] ) );
$tail = make_image( 'select4.jpg' ); $task = MSW_Task_Manager::create( 'compress', array( 'ids' => array( $ids[0], $ids[1], $tail ) ), 3 );
check( 'already compressed first batch does not hide later selections', false === MSW_Compressor::run_task( $task, 20 ) && 1 === (int) $wpdb->get_var( 'SELECT compressed FROM wp_ms_images WHERE id=' . $tail ) );

// Inject a destination collision after preflight to force the second rename to fail.
$id = make_image( 'trash/rollback.jpg' ); $path = $fixture_root . '/trash/rollback.jpg'; copy( $path, $path . '.ms-original' );
$token = 'file-' . substr( md5( 'trash/rollback.jpg' ), 0, 12 );
$blocker = MSW_Cleaner::trash_dir() . '/' . $token . '/trash/rollback.jpg.ms-original';
$manifest_hook = function () use ( $blocker ) { mkdir( $blocker ); };
$result = MSW_Cleaner::trash_images( array( $id ) );
check( 'failed second trash move rolls the original back', 1 === $result['failed'] && is_file( $path ) && is_file( $path . '.ms-original' ) );
check( 'successful rollback removes the recovery plan', ! is_file( dirname( dirname( $blocker ) ) . '/manifest.json' ) );
rmdir( $blocker );

// Failed restore preserves the manifest, and a partial restore can be retried.
$id = make_image( 'restore/retry.jpg' ); $path = $fixture_root . '/restore/retry.jpg'; copy( $path, $path . '.ms-original' );
$result = MSW_Cleaner::trash_images( array( $id ) );
$token = 'file-' . substr( md5( 'restore/retry.jpg' ), 0, 12 );
$plan = MSW_Cleaner::trash_dir() . '/' . $token . '/manifest.json';
mkdir( $path . '.ms-original' );
$result = MSW_Cleaner::restore_trashed( $token );
check( 'partial restore reports failure and keeps its manifest', is_wp_error( $result ) && is_file( $path ) && is_file( $plan ) );
check( 'failed restore keeps the index trashed', 'trash' === $wpdb->get_var( 'SELECT status FROM wp_ms_images WHERE id=' . $id ) );
$manifest = json_decode( file_get_contents( $plan ), true ); $manifest['trashed_at'] = '2020-01-01 00:00:00'; file_put_contents( $plan, json_encode( $manifest ) );
MSW_Cleaner::purge_expired();
check( 'daily purge skips an unfinished restore', is_file( $plan ) );
rmdir( $path . '.ms-original' );
$result = MSW_Cleaner::restore_trashed( $token );
check( 'retry completes the partial restore without overwriting files', true === $result && is_file( $path . '.ms-original' ) && ! is_file( $plan ) );

// Expired entries use the database correctly and delete their files and index.
$id = make_image( 'expire.jpg' ); MSW_Cleaner::trash_images( array( $id ) );
$token = 'file-' . substr( md5( 'expire.jpg' ), 0, 12 ); $plan = MSW_Cleaner::trash_dir() . '/' . $token . '/manifest.json';
$manifest = json_decode( file_get_contents( $plan ), true ); $manifest['trashed_at'] = '2020-01-01 00:00:00'; file_put_contents( $plan, json_encode( $manifest ) );
check( 'expired purge finishes without fatal and clears the entry', 1 === MSW_Cleaner::purge_expired() && ! is_file( $plan ) && null === $wpdb->get_var( 'SELECT id FROM wp_ms_images WHERE id=' . $id ) );

// A fresh upload has no index row: the real upload callback must create it.
$id = make_image( 'new-upload.jpg' ); $wpdb->delete( 'wp_ms_images', array( 'id' => $id ) );
$fixture_posts[900] = (object) array( 'post_mime_type' => 'image/jpeg' );
$wpdb->insert( 'wp_postmeta', array( 'post_id' => 900, 'meta_key' => '_wp_attached_file', 'meta_value' => 'new-upload.jpg' ) );
$plugin = ( new ReflectionClass( 'MSW_Plugin' ) )->newInstanceWithoutConstructor();
$metadata = array( 'file' => 'new-upload.jpg' );
check( 'upload callback returns unchanged metadata', $metadata === $plugin->auto_compress( $metadata, 900 ) );
check( 'new upload is indexed, linked, and compressed immediately', 1 === (int) $wpdb->get_var( 'SELECT compressed FROM wp_ms_images WHERE attachment_id=900' ) );

// Reanalysis preserves code references and parses ID-only blocks beyond 50 hits.
$id = make_image( 'refs.jpg', 901 );
$wpdb->insert( 'wp_ms_references', array( 'image_id' => $id, 'reference_type' => 'theme', 'reference_id' => 0, 'source' => 'theme.php' ) );
$result = MSW_Reference_Detector::analyze_image( $id );
check( 'single-image analysis retains a theme-only strong reference', ! is_wp_error( $result ) && 'used' === $result['status'] && isset( $result['references'][0]['label'] ) );
$wpdb->delete( 'wp_ms_references', array( 'image_id' => $id ) );
for ( $i = 1; $i <= 105; $i++ ) {
	$wpdb->insert( 'wp_posts', array( 'ID' => $i, 'post_title' => 'Block ' . $i, 'post_content' => '<!-- wp:core/gallery {"ids":[901]} /-->', 'post_status' => 'publish', 'post_type' => 'post' ) );
}
$result = MSW_Reference_Detector::analyze_image( $id );
check( 'ID-only Gutenberg references are paginated without truncation', ! is_wp_error( $result ) && 'used' === $result['status'] && 105 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_ms_references WHERE image_id={$id} AND reference_type='gutenberg'" ) );
$thumb_id = make_image( 'refs-120x90.jpg', 901 ); $wpdb->update( 'wp_ms_images', array( 'parent_file_id' => $id, 'is_thumbnail' => 1 ), array( 'id' => $thumb_id ) );
$wpdb->query( 'DELETE FROM wp_posts' );
$wpdb->insert( 'wp_posts', array( 'ID' => 200, 'post_title' => 'Variant', 'post_content' => '<img src="https://example.com/wp-content/uploads/refs-120x90.jpg">', 'post_status' => 'publish', 'post_type' => 'post' ) );
$result = MSW_Reference_Detector::analyze_image( $id );
check( 'single-image analysis attributes a variant URL to its parent', ! is_wp_error( $result ) && 'used' === $result['status'] );
check( 'missing-prefix traversal cannot escape the uploads root', ! MSW_Files::within_uploads( $fixture_root . '/missing/../../escape.jpg' ) );

// Only this test's exact temporary tree is removed.
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $fixture_root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $iterator as $file ) { $file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() ); }
rmdir( $fixture_root );
echo $failures ? "{$failures} REGRESSION CHECKS FAILED\n" : "SAFETY REGRESSION CHECKS PASSED\n";
exit( $failures ? 1 : 0 );

<?php
/**
 * E2E flow verification inside the Docker WP instance (run with wp eval-file).
 * Expects: attachments 4 (test1.jpg), 5 (test2.jpg), 6 (test3.png).
 */

define( 'MSW_E2E', true );
$failures = 0;
function check( $label, $cond, $extra = '' ) {
	global $failures;
	echo ( $cond ? '  ok  ' : ' FAIL ' ) . $label . ( '' !== $extra ? " [{$extra}]" : '' ) . "\n";
	if ( ! $cond ) {
		$failures++;
	}
}

$uploads = wp_upload_dir();
$basedir = $uploads['basedir'];

// --- Setup: featured image + a post embedding test2 by URL -------------------
update_post_meta( 1, '_thumbnail_id', 4 );

$test2_url = wp_get_attachment_url( 5 );
$post_id   = wp_insert_post(
	array(
		'post_title'   => 'Reference test post',
		'post_content' => 'Here is an image: <img src="' . $test2_url . '" alt="x"> and plain path wp-content/uploads/2026/09/test2.jpg',
		'post_status'  => 'publish',
	)
);
check( 'post created with reference', $post_id > 0 );

// --- 1. Media scan ------------------------------------------------------------
$task  = MSW_Scanner::start();
$steps = 0;
while ( MSW_Task_Manager::tick() && $steps < 50 ) {
	$steps++;
}
check( 'scan task completed', 'completed' === MSW_Task_Manager::get( $task['id'] )['status'], "steps={$steps}" );

global $wpdb;
$images = $wpdb->prefix . 'ms_images';
$rows   = $wpdb->get_results( "SELECT id, attachment_id, file_name, file_size, mime_type, width, height FROM {$images} ORDER BY id", ARRAY_A );

// Thumb variants are skipped; exactly 3 originals expected.
check( '3 images indexed (thumbnails skipped)', 3 === count( $rows ), 'got ' . count( $rows ) );

$by_name = array();
foreach ( $rows as $row ) {
	$by_name[ $row['file_name'] ] = $row;
}
check( 'test1.jpg matched to attachment 4', isset( $by_name['test1.jpg'] ) && 4 === (int) $by_name['test1.jpg']['attachment_id'] );
check( 'test2.jpg matched to attachment 5', isset( $by_name['test2.jpg'] ) && 5 === (int) $by_name['test2.jpg']['attachment_id'] );
check( 'test3.png matched to attachment 6', isset( $by_name['test3.png'] ) && 6 === (int) $by_name['test3.png']['attachment_id'] );
check( 'dimensions captured', 1200 === (int) $by_name['test1.jpg']['width'] && 900 === (int) $by_name['test1.jpg']['height'] );

// --- 2. Compress one image + verify backup/restore ----------------------------
$img1_id = (int) $by_name['test1.jpg']['id'];
$before  = filesize( $basedir . '/2026/09/test1.jpg' );

$result = MSW_Compressor::compress_image( $img1_id );
check( 'compress_image succeeded', is_array( $result ) && ! empty( $result['success'] ), is_wp_error( $result ) ? $result->get_error_message() : '' );
check( 'backup created (.ms-original)', is_file( $basedir . '/2026/09/test1.jpg.ms-original' ) );
check( 'file actually smaller', filesize( $basedir . '/2026/09/test1.jpg' ) < $before );

$row = $wpdb->get_row( "SELECT * FROM {$images} WHERE id = {$img1_id}", ARRAY_A );
check( 'index row updated (compressed=1)', 1 === (int) $row['compressed'] );
check( 'sizes recorded', (int) $row['original_size'] === $before && (int) $row['compressed_size'] === filesize( $basedir . '/2026/09/test1.jpg' ) );

// Restore.
$restored = MSW_Compressor::restore_image( $img1_id );
check( 'restore succeeded', ! is_wp_error( $restored ), is_wp_error( $restored ) ? $restored->get_error_message() : '' );
check( 'file size restored', filesize( $basedir . '/2026/09/test1.jpg' ) === $before );
check( 'backup removed after restore', ! is_file( $basedir . '/2026/09/test1.jpg.ms-original' ) );
$row = $wpdb->get_row( "SELECT * FROM {$images} WHERE id = {$img1_id}", ARRAY_A );
check( 'index row compressed=0 after restore', 0 === (int) $row['compressed'] );

// Re-compress via batch task (all uncompressed).
$task  = MSW_Task_Manager::create( 'compress', array( 'all' => 1 ), 3 );
$steps = 0;
while ( MSW_Task_Manager::tick() && $steps < 50 ) {
	$steps++;
}
$trow  = MSW_Task_Manager::get( $task['id'] );
check( 'batch compress completed', 'completed' === $trow['status'], "processed={$trow['processed']}" );
$cnt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$images} WHERE compressed = 1" );
check( 'all 3 compressed', 3 === $cnt, "compressed={$cnt}" );
check( 'backups exist for all', is_file( $basedir . '/2026/09/test1.jpg.ms-original' ) && is_file( $basedir . '/2026/09/test2.jpg.ms-original' ) && is_file( $basedir . '/2026/09/test3.png.ms-original' ) );

// --- 3. Reference scan ----------------------------------------------------------
$task  = MSW_Reference_Detector::start();
$steps = 0;
while ( MSW_Task_Manager::tick() && $steps < 100 ) {
	$steps++;
}
check( 'reference scan completed', 'completed' === MSW_Task_Manager::get( $task['id'] )['status'], "steps={$steps}" );

$rows = $wpdb->get_results( "SELECT id, file_name, reference_status, reference_count FROM {$images} ORDER BY id", ARRAY_A );
$by_name = array();
foreach ( $rows as $row ) {
	$by_name[ $row['file_name'] ] = $row;
}
check( 'test1.jpg used (featured)', 'used' === $by_name['test1.jpg']['reference_status'] && (int) $by_name['test1.jpg']['reference_count'] > 0 );
check( 'test2.jpg used (post content)', 'used' === $by_name['test2.jpg']['reference_status'] );
check( 'test3.png unused', 'unused' === $by_name['test3.png']['reference_status'] );

// Single-image analyze endpoint logic.
$analysis = MSW_Reference_Detector::analyze_image( (int) $by_name['test2.jpg']['id'] );
check( 'analyze_image finds the reference', ! is_wp_error( $analysis ) && count( $analysis['references'] ) > 0 );

// --- 4. Trash + restore ----------------------------------------------------------
$img3_id = (int) $by_name['test3.png']['id'];
MSW_Compressor::restore_image( $img3_id ); // restore bytes first so backup is gone before moving
$summary = MSW_Cleaner::trash_images( array( $img3_id ) );
check( 'trash: 1 image moved', 1 === $summary['trashed'] && 0 === $summary['failed'], json_encode( $summary ) );
check( 'file moved to ms-trash', is_dir( $basedir . '/ms-trash' ) && ! is_file( $basedir . '/2026/09/test3.png' ) );
$att6 = get_post( 6 );
check( 'attachment 6 in WP trash', $att6 && 'trash' === $att6->post_status );
check( 'manifest.json written', (bool) glob( $basedir . '/ms-trash/*/manifest.json' ) );
check( 'index row removed', 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$images} WHERE id = {$img3_id}" ) );

$trash_entries = MSW_Cleaner::list_trash();
check( 'trash listing shows entry', 1 === count( $trash_entries ) );
$restored = MSW_Cleaner::restore_trashed( $trash_entries[0]['token'] );
check( 'trash restore succeeded', ! is_wp_error( $restored ), is_wp_error( $restored ) ? $restored->get_error_message() : '' );
check( 'file back in uploads', is_file( $basedir . '/2026/09/test3.png' ) );
$att6 = get_post( 6 );
check( 'attachment 6 untrashed', $att6 && 'trash' !== $att6->post_status );

// --- 5. Stats sanity --------------------------------------------------------------
$stats_row = $wpdb->get_row( "SELECT COUNT(*) AS c FROM {$images}", ARRAY_A );
check( 'index has 3 rows after all flows', 3 === (int) $stats_row['c'], "got {$stats_row['c']}" );

echo "\n" . ( 0 === $failures ? "E2E ALL CHECKS PASSED\n" : $failures . " E2E CHECK(S) FAILED\n" );
exit( $failures ? 1 : 0 );

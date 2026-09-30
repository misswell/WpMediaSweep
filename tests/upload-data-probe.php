<?php
/**
 * Pick the largest uncompressed JPG and compress it as a chain sanity check.
 */

require '/home/wwwroot/blog.liuguofeng.com/wp-load.php';

global $wpdb;
$images = $wpdb->prefix . 'ms_images';

$rows = (array) $wpdb->get_results(
	"SELECT id, file_name, file_size FROM {$images}
	 WHERE is_thumbnail = 0 AND compressed = 0 AND status = 'active' AND mime_type = 'image/jpeg'
	 ORDER BY file_size DESC LIMIT 3",
	ARRAY_A
);

foreach ( $rows as $row ) {
	echo "TRY #{$row['id']} {$row['file_name']} (" . size_format( $row['file_size'] ) . ")\n";
	$result = MSW_Compressor::compress_image( (int) $row['id'] );
	if ( is_wp_error( $result ) ) {
		echo '  -> ' . $result->get_error_message() . "\n";
		continue;
	}
	echo '  -> OK via ' . $result['backend']
		. ': ' . size_format( $result['original_size'] )
		. ' -> ' . size_format( $result['compressed_size'] )
		. ' (' . $result['ratio'] . "% saved)\n";
	break;
}

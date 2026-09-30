<?php
/**
 * Probe 5 (final): assert list_images now includes file_url / thumbnail_url.
 */

require '/home/wwwroot/blog.liuguofeng.com/wp-load.php';

wp_set_current_user( 1 );

$response = MSW_Rest_Images::list_images( new WP_REST_Request( 'GET', '/mediasweep/v1/images' ) );
$data     = $response->get_data();
$first    = $data['items'][0] ?? null;

if ( ! is_array( $first ) ) {
	echo "NO ITEMS\n";
	exit;
}

echo 'KEYS: ' . implode( ',', array_keys( $first ) ) . "\n";
echo 'file_url=' . ( $first['file_url'] ?? 'MISSING' ) . "\n";
echo 'thumbnail_url=' . ( isset( $first['thumbnail_url'] ) && $first['thumbnail_url'] ? 'HAS-VALUE' : 'empty(ok, fallback used)' ) . "\n";
echo 'risk_level=' . ( $first['risk_level'] ?? 'MISSING' ) . "\n";

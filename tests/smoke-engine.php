<?php
/**
 * Smoke test for the Octo Engine (run outside WordPress with minimal stubs).
 *
 * Usage: php tests/smoke-engine.php
 *
 * @package MediaSweep
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
// ---------------------------------------------------------------------------

require MSW_PLUGIN_DIR . 'engine/octo-engine/class-msw-backend-imagick.php';
require MSW_PLUGIN_DIR . 'engine/octo-engine/class-msw-backend-gd.php';
require MSW_PLUGIN_DIR . 'engine/octo-engine/class-octo-engine.php';

$failures = 0;

function check( $label, $condition ) {
	global $failures;
	echo ( $condition ? "  ok  " : " FAIL " ) . $label . "\n";
	if ( ! $condition ) {
		$failures++;
	}
}

function make_test_image( $path, $mime, $w = 800, $h = 600 ) {
	$im = imagecreatetruecolor( $w, $h );
	// Fill with noise so compression has real work to do.
	for ( $x = 0; $x < $w; $x += 4 ) {
		for ( $y = 0; $y < $h; $y += 4 ) {
			$color = imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) );
			imagefilledrectangle( $im, $x, $y, $x + 4, $y + 4, $color );
		}
	}

	switch ( $mime ) {
		case 'image/jpeg':
			imagejpeg( $im, $path, 100 );
			break;
		case 'image/png':
			imagesavealpha( $im, true );
			imagepng( $im, $path, 1 );
			break;
		case 'image/webp':
			imagewebp( $im, $path, 100 );
			break;
	}
	imagedestroy( $im );
	return is_file( $path );
}

echo "== Octo Engine smoke test ==\n";
echo 'Imagick available: ' . ( MSW_Backend_Imagick::available() ? 'yes' : 'no' ) . "\n";
echo 'GD available: ' . ( MSW_Backend_GD::available() ? 'yes' : 'no' ) . "\n";

$dir = sys_get_temp_dir() . '/msw-smoke-' . mt_rand();
mkdir( $dir );

$cases = array(
	'image/jpeg' => 'photo.jpg',
	'image/png'  => 'photo.png',
	'image/webp' => 'photo.webp',
);

foreach ( $cases as $mime => $name ) {
	echo "\n-- {$mime} ({$name})\n";

	$path = $dir . '/' . $name;
	check( 'generate test image', make_test_image( $path, $mime ) );
	$original_size = filesize( $path );

	$result = MSW_Octo_Engine::compress( $path, array( 'jpeg_quality' => 80 ) );

	if ( is_file( $path . '.skip' ) ) {
		continue;
	}

	check( 'compression succeeded', ! empty( $result['success'] ) );
	if ( empty( $result['success'] ) ) {
		echo '      error: ' . $result['error'] . "\n";
		continue;
	}

	check( 'output smaller than original (' . $original_size . ' -> ' . $result['compressed_size'] . ')', $result['compressed_size'] < $original_size );
	check( 'ratio reported (' . $result['ratio'] . '%)', $result['ratio'] > 0 );
	check( 'backend recorded (' . $result['backend'] . ')', '' !== $result['backend'] );

	$out = getimagesize( $result['output_file'] );
	check( 'pixel dimensions preserved', 800 === $out[0] && 600 === $out[1] );

	// Replace flow (as the compressor does) then re-verify the file on disk.
	rename( $result['output_file'], $path );
	check( 'replaced file is a valid image', false !== getimagesize( $path ) );
	check( 'no temp files left', ! is_file( $dir . '/.photo.ms-tmp.' . pathinfo( $name, PATHINFO_EXTENSION ) ) );
}

echo "\n-- no-improvement rule\n";
// Tiny, already-tiny PNG should not be "compressed" (output would not be smaller).
$small = $dir . '/small.png';
$im    = imagecreatetruecolor( 2, 2 );
imagepng( $im, $small, 9 );
imagedestroy( $im );
$result = MSW_Octo_Engine::compress( $small, array() );
if ( ! empty( $result['success'] ) ) {
	check( 'tiny image rejected or improved', filesize( $result['output_file'] ) < filesize( $small ) );
	@unlink( $result['output_file'] );
	echo "  note: tiny image was improved by re-encode (acceptable)\n";
} else {
	check( 'tiny image kept (no improvement)', true );
	check( 'error reported: ' . ( $result['error'] ?: '' ), null !== $result['error'] );
}

echo "\n-- unsupported format\n";
file_put_contents( $dir . '/not-image.jpg', 'definitely not an image' );
$result = MSW_Octo_Engine::compress( $dir . '/not-image.jpg', array() );
check( 'invalid image rejected', empty( $result['success'] ) && null !== $result['error'] );

echo "\n-- missing file\n";
$result = MSW_Octo_Engine::compress( $dir . '/nope.jpg', array() );
check( 'missing file rejected', empty( $result['success'] ) && null !== $result['error'] );

// Cleanup.
foreach ( (array) glob( $dir . '/*' ) as $f ) {
	@unlink( $f );
}
@rmdir( $dir );

echo "\n" . ( 0 === $failures ? "ALL CHECKS PASSED\n" : $failures . " CHECK(S) FAILED\n" );
exit( $failures ? 1 : 0 );

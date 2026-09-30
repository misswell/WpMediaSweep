<?php
/**
 * GD backend for the Octo Engine (fallback when Imagick is not available).
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Backend_GD {

	public static function name() {
		return 'gd';
	}

	public static function available() {
		return function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * Compress into $dst.
	 *
	 * @param string $src     Source file.
	 * @param string $dst     Destination temp file.
	 * @param string $mime    Mime type.
	 * @param array  $options Resolved options.
	 * @return true|WP_Error
	 */
	public static function compress( $src, $dst, $mime, $options ) {
		switch ( $mime ) {
			case 'image/jpeg':
				return self::compress_jpeg( $src, $dst, $options );
			case 'image/png':
				return self::compress_png( $src, $dst );
			case 'image/webp':
				return self::compress_webp( $src, $dst, $options );
			case 'image/avif':
				return self::compress_avif( $src, $dst, $options );
		}
		return new WP_Error( 'msw_gd', 'Unsupported mime for GD backend.' );
	}

	/**
	 * Load an image into a truecolor GD resource.
	 *
	 * @param string   $src    Source path.
	 * @param callable $loader function( string $path ): resource|GdImage|null.
	 * @return resource|GdImage|null
	 */
	protected static function load( $src, $loader ) {
		if ( ! is_callable( $loader ) ) {
			return null;
		}
		$image = @call_user_func( $loader, $src );
		if ( ! $image ) {
			return null;
		}
		if ( function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $image );
		}
		return $image;
	}

	/**
	 * Rotate pixels per EXIF orientation (GD never reads it automatically).
	 *
	 * @param resource|GdImage $image GD image.
	 * @param string           $src   Source path (for exif_read_data).
	 * @return resource|GdImage
	 */
	protected static function apply_orientation( $image, $src ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $image;
		}

		$exif = @exif_read_data( $src );
		if ( empty( $exif['Orientation'] ) ) {
			return $image;
		}

		$angle = 0;
		switch ( (int) $exif['Orientation'] ) {
			case 3:
				$angle = 180;
				break;
			case 6:
				$angle = -90;
				break;
			case 8:
				$angle = 90;
				break;
		}

		if ( $angle && function_exists( 'imagerotate' ) ) {
			$rotated = imagerotate( $image, $angle, 0 );
			if ( $rotated ) {
				imagedestroy( $image );
				$image = $rotated;
			}
		}

		return $image;
	}

	protected static function compress_jpeg( $src, $dst, $options ) {
		$image = self::load( $src, 'imagecreatefromjpeg' );
		if ( ! $image ) {
			return new WP_Error( 'msw_gd', 'GD cannot decode JPEG.' );
		}

		$image = self::apply_orientation( $image, $src );

		$ok = false;
		if ( function_exists( 'imageistruecolor' ) && function_exists( 'imagejpeg' ) ) {
			$ok = imagejpeg( $image, $dst, (int) $options['jpeg_quality'] );
		}

		imagedestroy( $image );

		if ( ! $ok || ! is_file( $dst ) || filesize( $dst ) <= 0 ) {
			return new WP_Error( 'msw_gd', 'GD JPEG encode failed.' );
		}
		return true;
	}

	protected static function compress_png( $src, $dst ) {
		$image = self::load( $src, 'imagecreatefrompng' );
		if ( ! $image ) {
			return new WP_Error( 'msw_gd', 'GD cannot decode PNG.' );
		}

		// Preserve transparency; GD re-encoding drops metadata by design.
		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$level = 6;
		$ok    = function_exists( 'imagepng' ) ? imagepng( $image, $dst, $level ) : false;

		imagedestroy( $image );

		if ( ! $ok || ! is_file( $dst ) || filesize( $dst ) <= 0 ) {
			return new WP_Error( 'msw_gd', 'GD PNG encode failed.' );
		}
		return true;
	}

	protected static function compress_webp( $src, $dst, $options ) {
		if ( ! function_exists( 'imagecreatefromwebp' ) || ! function_exists( 'imagewebp' ) ) {
			return new WP_Error( 'msw_gd', 'GD WebP support missing.' );
		}

		$image = self::load( $src, 'imagecreatefromwebp' );
		if ( ! $image ) {
			return new WP_Error( 'msw_gd', 'GD cannot decode WebP.' );
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$ok = imagewebp( $image, $dst, (int) $options['webp_quality'] );

		imagedestroy( $image );

		if ( ! $ok || ! is_file( $dst ) || filesize( $dst ) <= 0 ) {
			return new WP_Error( 'msw_gd', 'GD WebP encode failed.' );
		}
		return true;
	}

	protected static function compress_avif( $src, $dst, $options ) {
		if ( ! function_exists( 'imagecreatefromavif' ) || ! function_exists( 'imageavif' ) ) {
			return new WP_Error( 'msw_gd', 'GD AVIF support missing (needs PHP 8.1+).' );
		}

		$image = self::load( $src, 'imagecreatefromavif' );
		if ( ! $image ) {
			return new WP_Error( 'msw_gd', 'GD cannot decode AVIF.' );
		}

		$quality = (int) $options['avif_quality'];
		$ok      = imageavif( $image, $dst, $quality );

		imagedestroy( $image );

		if ( ! $ok || ! is_file( $dst ) || filesize( $dst ) <= 0 ) {
			return new WP_Error( 'msw_gd', 'GD AVIF encode failed.' );
		}
		return true;
	}
}

<?php
/**
 * Imagick backend for the Octo Engine.
 *
 * Handles EXIF orientation explicitly: metadata stripping removes the
 * orientation flag, so pixels are auto-oriented before encoding.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Backend_Imagick {

	public static function name() {
		return 'imagick';
	}

	public static function available() {
		return class_exists( 'Imagick' );
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
		try {
			$image = new Imagick( $src );
		} catch ( Exception $e ) {
			return new WP_Error( 'msw_imagick', 'Cannot read image: ' . $e->getMessage() );
		}

		try {
			// Collapse animation frames: compressing frame 0 only would change semantics.
			if ( $image->getNumberImages() > 1 ) {
				$image = $image->coalesceImages();
				// Animated GIF/WebP are out of scope for lossy recompression.
				return new WP_Error( 'msw_imagick', 'Animated images are skipped.' );
			}

			// Apply EXIF orientation into pixels before stripping metadata.
			if ( method_exists( $image, 'autoOrient' ) ) {
				$image->autoOrient();
			} elseif ( defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
				$orientation = $image->getImageProperty( 'exif:Orientation' );
				if ( $orientation && (int) $orientation > 1 ) {
					$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
				}
			}

			$image->stripImage();

			switch ( $mime ) {
				case 'image/jpeg':
					$image->setImageFormat( 'jpeg' );
					$image->setImageCompression( Imagick::COMPRESSION_JPEG );
					$image->setImageCompressionQuality( (int) $options['jpeg_quality'] );
					$image->setInterlaceScheme( Imagick::INTERLACE_PLANE );
					break;

				case 'image/png':
					// Lossless: strip metadata, keep pixel data, best zlib effort.
					$image->setImageFormat( 'png' );
					$image->setOption( 'png:compression-level', '9' );
					if ( ! empty( $options['png_lossless'] ) ) {
						$image->setOption( 'png:lossless', 'true' );
					}
					break;

				case 'image/webp':
					$image->setImageFormat( 'webp' );
					$image->setImageCompressionQuality( (int) $options['webp_quality'] );
					break;

				case 'image/avif':
					$image->setImageFormat( 'avif' );
					$image->setImageCompressionQuality( (int) $options['avif_quality'] );
					break;

				default:
					return new WP_Error( 'msw_imagick', 'Unsupported mime.' );
			}

			if ( ! $image->writeImage( $dst ) ) {
				return new WP_Error( 'msw_imagick', 'Imagick writeImage failed.' );
			}

			$image->destroy();
		} catch ( Exception $e ) {
			return new WP_Error( 'msw_imagick', 'Imagick error: ' . $e->getMessage() );
		}

		if ( ! is_file( $dst ) || filesize( $dst ) <= 0 ) {
			return new WP_Error( 'msw_imagick', 'Imagick produced no output.' );
		}

		return true;
	}
}

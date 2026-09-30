<?php
/**
 * Octo Engine — local-first compression engine for MediaSweep.
 *
 * Ported from the OctoShrink engine philosophy:
 *  - backend chain per format (system CLI binaries when available, Imagick, then GD)
 *  - same-interface results { success, original_size, compressed_size, ratio, backend }
 *  - "no improvement" rule: output must be a valid image with the same pixel
 *    dimensions AND smaller than the original, otherwise the original is kept.
 *  - everything happens locally; files are never sent anywhere.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

require_once MSW_PLUGIN_DIR . 'engine/octo-engine/class-msw-backend-imagick.php';
require_once MSW_PLUGIN_DIR . 'engine/octo-engine/class-msw-backend-gd.php';

class MSW_Octo_Engine {

	/** @var array Supported source mime types. */
	const SUPPORTED = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/**
	 * Compress an image file.
	 *
	 * @param string $path    Absolute file path.
	 * @param array  $options { jpeg_quality, webp_quality, avif_quality, png_lossless }.
	 * @return array {
	 *     success, original_size, compressed_size, ratio, backend, error, output_file
	 * }
	 */
	public static function compress( $path, $options = array() ) {
		$result = array(
			'success'         => false,
			'original_size'   => 0,
			'compressed_size' => 0,
			'ratio'           => 0.0,
			'backend'         => '',
			'error'           => null,
			'output_file'     => null,
		);

		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			$result['error'] = 'File not readable: ' . $path;
			return $result;
		}

		$mime = self::detect_mime( $path );
		if ( ! $mime || ! isset( self::SUPPORTED[ $mime ] ) ) {
			$result['error'] = 'Unsupported format: ' . ( $mime ? $mime : 'unknown' );
			return $result;
		}

		$original_size = (int) filesize( $path );
		if ( $original_size <= 0 ) {
			$result['error'] = 'Empty file.';
			return $result;
		}

		$result['original_size'] = $original_size;

		$original = @getimagesize( $path );
		if ( ! $original ) {
			$result['error'] = 'Not a valid image.';
			return $result;
		}
		list( $w, $h ) = $original;

		$options = self::resolve_options( $mime, $options );

		// Output temp file must live in the same directory (rename() is not cross-device).
		$dir        = dirname( $path );
		$ext        = pathinfo( $path, PATHINFO_EXTENSION );
		$tmp_target = $dir . '/.' . basename( $path, '.' . $ext ) . '.ms-tmp.' . $ext;

		$chain = self::backend_chain( $mime, $options );
		$error = 'No compression backend available for ' . $mime . '.';

		foreach ( $chain as $backend ) {
			if ( ! $backend::available() ) {
				continue;
			}

			$ok = $backend::compress( $path, $tmp_target, $mime, $options );

			if ( is_wp_error( $ok ) ) {
				$error = $ok->get_error_message();
				@unlink( $tmp_target );
				continue;
			}

			$check = self::validate_output( $tmp_target, $w, $h, $original_size );
			if ( is_wp_error( $check ) ) {
				$error = $check->get_error_message();
				@unlink( $tmp_target );
				continue;
			}

			$result['success']         = true;
			$result['compressed_size'] = (int) filesize( $tmp_target );
			$result['ratio']           = round( ( 1 - $result['compressed_size'] / $original_size ) * 100, 1 );
			$result['backend']         = $backend::name();
			$result['output_file']     = $tmp_target;
			$result['error']           = null;

			return $result;
		}

		$result['error'] = $error;
		return $result;
	}

	/**
	 * Mime detection via finfo, falling back to getimagesize.
	 *
	 * @param string $path File path.
	 * @return string|null
	 */
	public static function detect_mime( $path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = finfo_file( $finfo, $path );
				finfo_close( $finfo );
				if ( $mime ) {
					return strtolower( $mime );
				}
			}
		}
		$info = @getimagesize( $path );
		return $info && ! empty( $info['mime'] ) ? strtolower( $info['mime'] ) : null;
	}

	/**
	 * Merge engine defaults (mirrors OctoShrink format strategy) with user options.
	 *
	 * @param string $mime    Mime type.
	 * @param array  $options User options.
	 * @return array
	 */
	protected static function resolve_options( $mime, $options ) {
		$defaults = array(
			'jpeg_quality' => 85,
			'webp_quality' => 80,
			'avif_quality' => 65,
			'png_lossless' => true,
		);

		$options = array_merge( $defaults, array_intersect_key( (array) $options, $defaults ) );
		$options['mime'] = $mime;
		return $options;
	}

	/**
	 * Backend chain per format, best first. Pure-PHP backends only — the plugin
	 * never spawns shell processes, so the chain is Imagick then GD.
	 *
	 * @param string $mime    Mime type.
	 * @param array  $options Resolved options.
	 * @return string[] Class names.
	 */
	protected static function backend_chain( $mime, $options ) {
		switch ( $mime ) {
			case 'image/jpeg':
			case 'image/png':
			case 'image/webp':
				return array( 'MSW_Backend_Imagick', 'MSW_Backend_GD' );
			case 'image/avif':
				return array( 'MSW_Backend_Imagick', 'MSW_Backend_GD' );
		}
		return array();
	}

	/**
	 * Validate a compressed output before it may replace the original.
	 * Mirrors OctoShrink acceptance rules.
	 *
	 * @param string $tmp_file      Output temp file.
	 * @param int    $orig_w        Original width.
	 * @param int    $orig_h        Original height.
	 * @param int    $original_size Original byte size.
	 * @return true|WP_Error
	 */
	protected static function validate_output( $tmp_file, $orig_w, $orig_h, $original_size ) {
		if ( ! is_file( $tmp_file ) || filesize( $tmp_file ) <= 0 ) {
			return new WP_Error( 'msw_engine', 'Backend produced no output.' );
		}

		$out = @getimagesize( $tmp_file );
		if ( ! $out ) {
			return new WP_Error( 'msw_engine', 'Output is not a valid image.' );
		}

		list( $out_w, $out_h ) = $out;

		// Pixel dimensions must match (orientation-swapped variant allowed).
		$match_normal  = ( $out_w === $orig_w && $out_h === $orig_h );
		$match_rotated = ( $out_w === $orig_h && $out_h === $orig_w );
		if ( ! $match_normal && ! $match_rotated ) {
			return new WP_Error(
				'msw_engine',
				sprintf( 'Dimensions changed: %dx%d -> %dx%d.', $orig_w, $orig_h, $out_w, $out_h )
			);
		}

		// No improvement → keep the original (OctoShrink "no_improvement" rule).
		if ( filesize( $tmp_file ) >= $original_size ) {
			return new WP_Error( 'msw_engine', 'No size improvement; original kept.' );
		}

		return true;
	}
}

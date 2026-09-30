<?php
/**
 * Plugin settings (stored as a single option array).
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Settings {

	const OPTION = 'msw_settings';

	/**
	 * Defaults aligned with the Octo Engine format strategy:
	 * JPEG 85 / PNG lossless / WebP 80 / AVIF 65.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'jpeg_quality'        => 85,
			'webp_quality'        => 80,
			'avif_quality'        => 65,
			'png_lossless'        => true,
			'keep_originals'      => true,   // backup as .ms-original beside the file.
			'backup_retention'    => 0,      // days, 0 = keep forever.
			'scan_themes'         => false,
			'scan_plugins'        => false,
			'trash_retention'     => 30,     // days before trash is purged.
			'batch_size'          => 200,    // files per scan/compress batch.
			'time_budget'         => 20,     // seconds per tick.
			'auto_compress'       => false,  // compress on upload (phase 3).
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist a partial settings array. Unknown keys and wrong types are dropped.
	 *
	 * @param array $new Partial settings.
	 * @return array Updated full settings.
	 */
	public static function update( $new ) {
		if ( ! is_array( $new ) ) {
			return self::all();
		}

		$int_keys    = array( 'jpeg_quality', 'webp_quality', 'avif_quality', 'backup_retention', 'trash_retention', 'batch_size', 'time_budget' );
		$bool_keys   = array( 'png_lossless', 'keep_originals', 'scan_themes', 'scan_plugins', 'auto_compress' );
		$allowed_int = array( 'jpeg_quality' => array( 1, 100 ), 'webp_quality' => array( 1, 100 ), 'avif_quality' => array( 1, 100 ), 'backup_retention' => array( 0, 3650 ), 'trash_retention' => array( 1, 365 ), 'batch_size' => array( 10, 1000 ), 'time_budget' => array( 5, 120 ) );

		$clean = array();
		foreach ( $new as $key => $value ) {
			if ( in_array( $key, $int_keys, true ) ) {
				if ( ! is_numeric( $value ) ) {
					continue;
				}
				$value = (int) $value;
				if ( isset( $allowed_int[ $key ] ) ) {
					$value = max( $allowed_int[ $key ][0], min( $allowed_int[ $key ][1], $value ) );
				}
				$clean[ $key ] = $value;
			} elseif ( in_array( $key, $bool_keys, true ) ) {
				$clean[ $key ] = (bool) $value;
			}
		}

		$merged = array_merge( self::all(), $clean );
		update_option( self::OPTION, $merged );

		MSW_Logger::info( 'settings', 'Settings updated.' );

		return $merged;
	}
}

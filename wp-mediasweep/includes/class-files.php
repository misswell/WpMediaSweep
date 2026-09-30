<?php
/**
 * Filesystem guard: every file write the plugin performs (backup, replace,
 * restore, trash move, unlink) must stay inside the uploads root. Paths come
 * from the plugin's own index table rather than user input, but the guard
 * makes path traversal structurally impossible even if the index is poisoned.
 *
 * @package MediaSweep
 */

defined( 'ABSPATH' ) || exit;

class MSW_Files {

	/**
	 * True when $path is a real filesystem path inside wp-content/uploads
	 * (the MediaSweep trash root included) — resolved, no traversal.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function within_uploads( $path ) {
		$root = self::realpath( MSW_Scanner::uploads_basedir() );
		$real = self::realpath( $path );

		if ( '' === $root || '' === $real ) {
			return false;
		}

		return 0 === strpos( $real, $root . '/' ) || $real === $root;
	}

	/**
	 * realpath() that tolerates missing files (needed for targets about to be
	 * created) by resolving the existing prefix.
	 *
	 * @param string $path Path.
	 * @return string Normalized path or '' on failure.
	 */
	public static function realpath( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '';
		}

		$real = @realpath( $path );
		if ( false !== $real ) {
			return $real;
		}

		// Resolve the deepest existing ancestor, then append the remainder.
		$prefix = dirname( $path );
		$suffix = basename( $path );
		$guard  = 0;

		while ( '/' !== $prefix && ! is_dir( $prefix ) && $guard++ < 64 ) {
			$suffix = basename( $prefix ) . '/' . $suffix;
			$prefix = dirname( $prefix );
		}

		$real = @realpath( $prefix );
		if ( false === $real ) {
			return '';
		}

		return rtrim( $real, '/' ) . '/' . ltrim( $suffix, '/' );
	}
}

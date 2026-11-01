<?php
/**
 * Fallback PSR-4 autoloader, used when Composer's autoloader is not installed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the plugin namespace to the src/ folder.
 */
final class Autoloader {

	/**
	 * Registers an SPL autoloader for one namespace prefix.
	 *
	 * @param string $prefix   Namespace prefix, with trailing backslash.
	 * @param string $base_dir Folder that holds the prefix's classes, with trailing slash.
	 * @return void
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				$length = strlen( $prefix );
				if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
					return;
				}

				$relative = substr( $class_name, $length );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}

<?php
/**
 * Finds the classes in one folder of src/.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Screens, settings sections, REST controllers and checks are each one class
 * per file in a known folder. Finding them by folder means a new one needs no
 * edit to a shared list, so work on separate files never collides.
 */
final class Discovery {

	/**
	 * Remembered results for this request.
	 *
	 * @var array<string, string[]>
	 */
	private static array $cache = array();

	/**
	 * Fully qualified names of the concrete classes in a folder of src/.
	 *
	 * Files whose name starts with Abstract or ends with Interface are skipped.
	 *
	 * @param string $folder Folder under src/, such as "Core/Settings/Sections".
	 * @return string[]
	 */
	public static function classes( string $folder ): array {
		if ( isset( self::$cache[ $folder ] ) ) {
			return self::$cache[ $folder ];
		}

		$found = array();
		$files = glob( ESTASOFT_ND_PATH . 'src/' . $folder . '/*.php' );

		if ( is_array( $files ) ) {
			sort( $files );

			foreach ( $files as $file ) {
				$name = basename( $file, '.php' );

				if ( 0 === strpos( $name, 'Abstract' ) || 'Interface' === substr( $name, -9 ) ) {
					continue;
				}

				$found[] = 'Estasoft\\NewsDistribution\\' . str_replace( '/', '\\', $folder ) . '\\' . $name;
			}
		}

		self::$cache[ $folder ] = $found;

		return $found;
	}

	/**
	 * Concrete classes one level down: src/<folder>/<Name>/<Name><suffix>.php.
	 *
	 * Channels live this way: src/Channels/GoogleNews/GoogleNewsChannel.php.
	 *
	 * @param string $folder Folder under src/, such as "Channels".
	 * @param string $suffix End of the class name, such as "Channel".
	 * @return string[]
	 */
	public static function nested( string $folder, string $suffix ): array {
		$key = $folder . '#' . $suffix;

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$found = array();
		$files = glob( ESTASOFT_ND_PATH . 'src/' . $folder . '/*/*' . $suffix . '.php' );

		if ( is_array( $files ) ) {
			sort( $files );

			foreach ( $files as $file ) {
				$name = basename( $file, '.php' );
				$dir  = basename( dirname( $file ) );

				if ( 0 === strpos( $name, 'Abstract' ) || 'Interface' === substr( $name, -9 ) ) {
					continue;
				}

				$found[] = 'Estasoft\\NewsDistribution\\' . str_replace( '/', '\\', $folder ) . '\\' . $dir . '\\' . $name;
			}
		}

		self::$cache[ $key ] = $found;

		return $found;
	}

	/**
	 * Forgets remembered results. Used by tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = array();
	}
}

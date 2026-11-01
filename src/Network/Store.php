<?php
/**
 * The network option `estasoft_nd_network`.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

defined( 'ABSPATH' ) || exit;

/**
 * One network option holds three things, each under its own key, so saving one never
 * drops another:
 *
 * - `ai`: the AI policy (read by `Ai\Policy` and `Core\Operations\PrivacyInventory`).
 * - `templates`: saved site templates.
 * - `applied`: the last template runs, with what each site had before, for Undo.
 */
final class Store {

	public const OPTION = 'estasoft_nd_network';

	/**
	 * The whole option.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$all = get_site_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * One key of the option.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Returned when the key is not stored.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = array() ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Changes one key and keeps the others.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value New value.
	 * @return void
	 */
	public static function set( string $key, $value ): void {
		$all         = self::all();
		$all[ $key ] = $value;

		update_site_option( self::OPTION, $all );
	}
}

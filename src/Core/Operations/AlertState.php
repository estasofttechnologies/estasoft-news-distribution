<?php
/**
 * What the alert system remembers between requests.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * Not a setting: the time the last failure email went out, the failures waiting
 * for the next one, the result of the last test email, which platforms were
 * already reported as silent. It lives in `estasoft_nd_alert_state`, which is
 * not autoloaded and never exported.
 */
final class AlertState {

	public const OPTION = 'estasoft_nd_alert_state';

	/**
	 * Everything kept.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * One entry.
	 *
	 * @param string $key      Entry name.
	 * @param mixed  $fallback Returned when nothing is kept.
	 * @return mixed
	 */
	public function get( string $key, $fallback = array() ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Keeps one entry.
	 *
	 * @param string $key   Entry name.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function put( string $key, $value ): void {
		$all         = $this->all();
		$all[ $key ] = $value;

		update_option( self::OPTION, $all, false );
	}
}

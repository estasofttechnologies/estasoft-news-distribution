<?php
/**
 * Messages that survive one redirect after a form post.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

defined( 'ABSPATH' ) || exit;

/**
 * A form on the Outlets page posts, does its work and redirects back (so a
 * reload never posts again). What happened is kept here for the next page
 * view, for the person who posted, and shown once. A message never holds a
 * secret: every text written here was cleaned by the outlet first.
 */
final class Flash {

	private const KEY = 'estasoft_nd_outlets_flash_';

	/**
	 * Adds a message for the next page view.
	 *
	 * @param string $tone    success, info, warning or danger.
	 * @param string $message Plain text.
	 * @return void
	 */
	public static function add( string $tone, string $message ): void {
		$all   = self::stored();
		$all[] = array(
			'tone' => $tone,
			'text' => $message,
		);

		set_transient( self::KEY . get_current_user_id(), array_slice( $all, -8 ), 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Reads the messages and forgets them.
	 *
	 * @return array<int, array{tone: string, text: string}>
	 */
	public static function take(): array {
		$all = self::stored();

		if ( array() !== $all ) {
			delete_transient( self::KEY . get_current_user_id() );
		}

		return $all;
	}

	/**
	 * The messages waiting.
	 *
	 * @return array<int, array{tone: string, text: string}>
	 */
	private static function stored(): array {
		$found = get_transient( self::KEY . get_current_user_id() );
		$out   = array();

		foreach ( is_array( $found ) ? $found : array() as $row ) {
			if ( is_array( $row ) && isset( $row['tone'], $row['text'] ) ) {
				$out[] = array(
					'tone' => (string) $row['tone'],
					'text' => (string) $row['text'],
				);
			}
		}

		return $out;
	}
}

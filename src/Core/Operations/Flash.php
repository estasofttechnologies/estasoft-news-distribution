<?php
/**
 * A one-time message after a form post.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Operations;

use Estasoft\NewsDistribution\Admin\Ui\Message;

defined( 'ABSPATH' ) || exit;

/**
 * A form that works without a script posts to admin-post.php, then redirects
 * back. The result is kept for two minutes for the person who posted it and
 * shown once, at the top of the screen.
 */
final class Flash {

	private const KEY = 'estasoft_nd_flash_';

	/**
	 * Keeps a message for the signed-in person.
	 *
	 * @param string $tone info, success, warning or danger.
	 * @param string $text Plain text.
	 * @return void
	 */
	public static function set( string $tone, string $text ): void {
		if ( get_current_user_id() < 1 ) {
			return;
		}

		set_transient(
			self::KEY . get_current_user_id(),
			array(
				'tone' => $tone,
				'text' => $text,
			),
			2 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * The kept message as a message bar, then forgets it.
	 *
	 * @return string Escaped HTML, or an empty string.
	 */
	public static function take(): string {
		if ( get_current_user_id() < 1 ) {
			return '';
		}

		$key   = self::KEY . get_current_user_id();
		$entry = get_transient( $key );

		if ( ! is_array( $entry ) ) {
			return '';
		}

		delete_transient( $key );

		return Message::text( (string) ( $entry['tone'] ?? 'info' ), (string) ( $entry['text'] ?? '' ) );
	}
}

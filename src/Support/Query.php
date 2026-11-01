<?php
/**
 * The arguments in the address that only choose what a screen shows.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one argument of the address (`?tab=history&filter=failed&paged=2`) and cleans it as it is read.
 *
 * This is the only place the plugin reads `$_GET` to choose what a screen shows: which tab is open, which filter or
 * page of a list, which message to print after a redirect. Opening a screen with a different argument changes nothing
 * on the site, and the same address is shared, bookmarked and linked from the documentation, so it carries no nonce.
 *
 * Nothing that changes the site goes through this class. Every form and every link that saves, sends, deletes or
 * starts something posts to the site with a nonce, and the handler checks that nonce and the person's capability
 * before it reads anything (`check_admin_referer()` or `wp_verify_nonce()`, then `$_POST`).
 */
final class Query {

	/**
	 * Whether the address has the argument, with any value.
	 *
	 * @param string $name The argument.
	 * @return bool
	 */
	public static function has( string $name ): bool {
		return isset( $_GET[ $name ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what to show; nothing is changed.
	}

	/**
	 * The argument as a key: lower-case letters, digits, dashes and underscores.
	 *
	 * @param string $name     The argument.
	 * @param string $fallback What to return when the address does not have it.
	 * @return string
	 */
	public static function key( string $name, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what to show; nothing is changed.
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? sanitize_key( wp_unslash( (string) $_GET[ $name ] ) ) : $fallback;
	}

	/**
	 * The argument as one line of plain text.
	 *
	 * @param string $name     The argument.
	 * @param string $fallback What to return when the address does not have it.
	 * @return string
	 */
	public static function text( string $name, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what to show; nothing is changed.
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $name ] ) ) : $fallback;
	}

	/**
	 * The argument as a whole number, never below zero.
	 *
	 * @param string $name    The argument.
	 * @param int    $fallback What to return when the address does not have it.
	 * @return int
	 */
	public static function int( string $name, int $fallback = 0 ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses what to show; nothing is changed.
		return isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? absint( wp_unslash( (string) $_GET[ $name ] ) ) : $fallback;
	}

	/**
	 * Whether the argument, read as a key, is exactly the given value.
	 *
	 * @param string $name  The argument.
	 * @param string $value The value to compare with, already a key such as `1` or `json`.
	 * @return bool
	 */
	public static function is( string $name, string $value ): bool {
		return self::has( $name ) && self::key( $name ) === $value;
	}
}

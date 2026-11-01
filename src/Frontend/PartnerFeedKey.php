<?php
/**
 * Partner API keys: making them, hashing them and checking them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * A key looks like `esnd_live_` followed by 32 random hexadecimal characters. It is
 * shown once, when it is made, and only a keyed hash of it is stored: an HMAC-SHA256
 * with a salt made for that one key. A slow password hash is the wrong tool here,
 * because a feed request checks a key every time, and the key is long and random, so
 * guessing it is not made easier by a fast hash.
 *
 * Nothing in this class can give a key back after it was made: the hash cannot be
 * reversed, and the last four characters that are kept for the screen ("hint")
 * are not enough to use it.
 */
final class PartnerFeedKey {

	public const PREFIX = 'esnd_live_';

	/**
	 * Random characters after the prefix.
	 */
	private const LENGTH = 32;

	/**
	 * Makes a new key and the values to store.
	 *
	 * @return array{key: string, salt: string, hash: string, hint: string} `key` is the only copy there will ever be.
	 */
	public static function make(): array {
		$key  = self::PREFIX . substr( bin2hex( random_bytes( (int) ( self::LENGTH / 2 ) ) ), 0, self::LENGTH );
		$salt = bin2hex( random_bytes( 16 ) );

		return array(
			'key'  => $key,
			'salt' => $salt,
			'hash' => self::hash( $key, $salt ),
			'hint' => substr( $key, -4 ),
		);
	}

	/**
	 * The keyed hash of a key.
	 *
	 * @param string $key  The key as the partner sends it.
	 * @param string $salt The salt made with that key.
	 * @return string
	 */
	public static function hash( string $key, string $salt ): string {
		return hash_hmac( 'sha256', $key, $salt );
	}

	/**
	 * Whether a key matches a stored hash. The comparison takes the same time whatever the key is.
	 *
	 * @param string $key  The key a request carried.
	 * @param string $hash The stored hash.
	 * @param string $salt The stored salt.
	 * @return bool
	 */
	public static function matches( string $key, string $hash, string $salt ): bool {
		if ( '' === $hash || '' === $salt || ! self::looks_like_a_key( $key ) ) {
			return false;
		}

		return hash_equals( $hash, self::hash( $key, $salt ) );
	}

	/**
	 * Whether a value has the shape of a key, so a request that carries something else is
	 * refused without any hashing.
	 *
	 * @param string $value Text from a request.
	 * @return bool
	 */
	public static function looks_like_a_key( string $value ): bool {
		return 1 === preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[0-9a-f]{' . self::LENGTH . '}$/', $value );
	}

	/**
	 * What a screen shows in place of a saved key: the prefix, bullets and the last four characters.
	 *
	 * @param string $hint The last four characters that were kept.
	 * @return string Empty when there is no hint.
	 */
	public static function mask( string $hint ): string {
		return '' === $hint ? '' : self::PREFIX . str_repeat( '•', 8 ) . $hint;
	}
}

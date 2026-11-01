<?php
/**
 * Tokens, webhook addresses and keys.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Secrets live in their own option, which is never autoloaded, never exported,
 * never returned by a REST route and never written to a log. A person with the
 * admin permission can replace a secret, not read it back.
 *
 * Values are sealed with the site's own salts when libsodium is available, so a
 * database dump alone does not reveal them. If the salts change, a sealed
 * secret cannot be opened and `get()` returns an empty string, which the screens
 * treat as "not set".
 */
final class Secrets {

	public const OPTION = 'estasoft_nd_secrets';

	private const PREFIX = 'v1:';

	/**
	 * Reads a secret for use in a request. Never print the result.
	 *
	 * @param string $key Secret name, such as "telegram_bot_token".
	 * @return string Empty when the secret is not set or cannot be opened.
	 */
	public function get( string $key ): string {
		$all = $this->all();

		if ( ! isset( $all[ $key ] ) || ! is_string( $all[ $key ] ) ) {
			return '';
		}

		return $this->open( $all[ $key ] );
	}

	/**
	 * Whether a secret is saved.
	 *
	 * @param string $key Secret name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return '' !== $this->get( $key );
	}

	/**
	 * Saves a secret. An empty value removes it.
	 *
	 * @param string $key   Secret name.
	 * @param string $value The secret.
	 * @return void
	 */
	public function set( string $key, string $value ): void {
		$all = $this->all();

		if ( '' === $value ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = $this->seal( $value );
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Removes a secret.
	 *
	 * @param string $key Secret name.
	 * @return void
	 */
	public function delete( string $key ): void {
		$this->set( $key, '' );
	}

	/**
	 * What a screen may show in place of a saved secret.
	 *
	 * Bullets only. No part of a saved secret is ever returned, so a person who
	 * may change a channel but not read its keys learns nothing about them.
	 *
	 * @param string $key Secret name.
	 * @return string Empty when the secret is not set.
	 */
	public function masked( string $key ): string {
		$value = $this->get( $key );

		if ( '' === $value ) {
			return '';
		}

		return str_repeat( '•', 8 );
	}

	/**
	 * Names of the saved secrets, never their values.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->all() );
	}

	/**
	 * The stored map.
	 *
	 * @return array<string, mixed>
	 */
	private function all(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * Seals a value.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	private function seal( string $value ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return $value;
		}

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$sealed = sodium_crypto_secretbox( $value, $nonce, $this->key() );
		} catch ( \Throwable $error ) {
			return $value;
		}

		return self::PREFIX . base64_encode( $nonce . $sealed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes sealed bytes for storage, not code.
	}

	/**
	 * Opens a stored value.
	 *
	 * @param string $stored Stored form.
	 * @return string
	 */
	private function open( string $stored ): string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes sealed bytes from storage, not code.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$sealed = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $sealed, $nonce, $this->key() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * A key made from the site's salts.
	 *
	 * @return string
	 */
	private function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'estasoft-nd-secrets', true );
	}
}

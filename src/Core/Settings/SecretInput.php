<?php
/**
 * Reads the secret fields out of a submission.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A secret field (type `secret`) is write-only: an empty submission keeps the
 * stored value, `<field>__remove` clears it, anything else replaces it. This is
 * the one place that rule is applied for channel settings.
 */
final class SecretInput {

	/**
	 * The secret fields of a field list.
	 *
	 * @param array<string, array<string, mixed>> $fields Field specs.
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( array $fields ): array {
		return array_filter(
			$fields,
			static function ( array $spec ): bool {
				return 'secret' === ( $spec['type'] ?? '' );
			}
		);
	}

	/**
	 * The fields that are not secrets but say where a secret is sent, such as the address of a Mastodon server: the
	 * saved token goes to whatever address is saved, so changing the address is as much a change of the secret as
	 * typing a new one. A field is one of these when its spec has `destination` set.
	 *
	 * @param array<string, array<string, mixed>> $fields Field specs.
	 * @return array<string, array<string, mixed>>
	 */
	public static function destinations( array $fields ): array {
		return array_filter(
			$fields,
			static function ( array $spec ): bool {
				return ! empty( $spec['destination'] );
			}
		);
	}

	/**
	 * The destination fields a submission would change. A field that is submitted with the value it already has
	 * (a form posts every field) is not a change.
	 *
	 * @param array<string, array<string, mixed>> $fields  Field specs (all of them).
	 * @param array<string, mixed>                $input   Submitted values.
	 * @param array<string, mixed>                $current The saved values.
	 * @return string[] Field names.
	 */
	public static function moved( array $fields, array $input, array $current ): array {
		$moved = array();

		foreach ( array_keys( self::destinations( $fields ) ) as $field ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}

			if ( is_scalar( $input[ $field ] ) && self::address( (string) $input[ $field ] ) === self::address( (string) ( $current[ $field ] ?? '' ) ) ) {
				continue;
			}

			$moved[] = (string) $field;
		}

		return $moved;
	}

	/**
	 * An address as it is compared: without spaces around it, a closing slash or capital letters.
	 *
	 * @param string $value The address.
	 * @return string
	 */
	private static function address( string $value ): string {
		return strtolower( rtrim( trim( $value ), '/' ) );
	}

	/**
	 * Whether a submission tries to set or remove any secret. An empty value
	 * keeps the stored secret, so it does not count.
	 *
	 * @param array<string, array<string, mixed>> $fields Field specs (all of them, or only the secret ones).
	 * @param array<string, mixed>                $input  Submitted values.
	 * @return bool
	 */
	public static function touches( array $fields, array $input ): bool {
		foreach ( array_keys( self::fields( $fields ) ) as $field ) {
			if ( ! empty( $input[ $field . '__remove' ] ) ) {
				return true;
			}

			if ( isset( $input[ $field ] ) && '' !== $input[ $field ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What to change in the secret store.
	 *
	 * @param array<string, array<string, mixed>> $fields Secret field specs (see fields()).
	 * @param array<string, mixed>                $input  Submitted values.
	 * @return array{changes: array<string, string>, errors: array<string, string>} Field => new value ("" removes it).
	 */
	public static function read( array $fields, array $input ): array {
		$changes = array();
		$errors  = array();

		foreach ( $fields as $field => $spec ) {
			$label = (string) ( $spec['label'] ?? $field );
			$max   = (int) ( $spec['max'] ?? 500 );

			if ( ! empty( $input[ $field . '__remove' ] ) ) {
				$changes[ $field ] = '';
				continue;
			}

			if ( ! array_key_exists( $field, $input ) || '' === $input[ $field ] || null === $input[ $field ] ) {
				continue;
			}

			if ( ! is_scalar( $input[ $field ] ) ) {
				$errors[ $field ] = sprintf(
					/* translators: %s: the name of a setting. */
					__( '%s must be text.', 'estasoft-news-distribution' ),
					$label
				);
				continue;
			}

			$value = trim( (string) $input[ $field ] );

			if ( mb_strlen( $value ) > $max ) {
				$errors[ $field ] = sprintf(
					/* translators: 1: the name of a setting, 2: the largest allowed number of characters. */
					_n( '%1$s can be at most %2$d character.', '%1$s can be at most %2$d characters.', $max, 'estasoft-news-distribution' ),
					$label,
					$max
				);
				continue;
			}

			$changes[ $field ] = $value;
		}

		return array(
			'changes' => $changes,
			'errors'  => $errors,
		);
	}
}

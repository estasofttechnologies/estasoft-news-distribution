<?php
/**
 * Puts submitted settings into the shape the Validator reads.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * An HTML form cannot post an empty list, an empty map or an unchecked box. The
 * form sends an empty marker instead, and this class turns the marker back into
 * what it stands for. A script that posts real JSON passes through untouched.
 */
final class Input {

	/**
	 * Normalises submitted values.
	 *
	 * @param array<string, array<string, mixed>> $fields Field specs.
	 * @param array<string, mixed>                $input  Unslashed input.
	 * @return array<string, mixed>
	 */
	public static function normalise( array $fields, array $input ): array {
		$out = array();

		foreach ( $fields as $name => $spec ) {
			$type = (string) ( $spec['type'] ?? 'string' );

			// A secret can also be removed, which arrives as its own flag.
			if ( 'secret' === $type && array_key_exists( $name . '__remove', $input ) ) {
				$out[ $name . '__remove' ] = $input[ $name . '__remove' ];
			}

			if ( ! array_key_exists( $name, $input ) ) {
				continue;
			}

			$value = $input[ $name ];

			if ( in_array( $type, array( 'list', 'map' ), true ) && ( '' === $value || null === $value ) ) {
				$value = array();
			}

			// Check boxes post an empty marker first, so a lone marker means nothing is chosen.
			if ( 'list' === $type && is_array( $value ) ) {
				$value = array_values(
					array_filter(
						$value,
						static function ( $item ) {
							return '' !== $item && null !== $item;
						}
					)
				);
			}

			// A map posts an empty marker too; drop it so it is not read as a pair.
			if ( 'map' === $type && is_array( $value ) ) {
				unset( $value[''] );
			}

			$out[ $name ] = $value;
		}

		return $out;
	}
}

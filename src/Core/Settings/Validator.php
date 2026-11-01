<?php
/**
 * Validates and cleans submitted settings against a section's field list.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A value outside its allowed range is not saved and not clamped. The error
 * names the field and says what is allowed, in words a person can act on.
 *
 * A field spec is an array with these keys:
 *
 * - type: bool, int, string, enum, list, map, group or secret. A secret is kept in
 *   Core\Secrets, never in the option, never returned, and an empty submission keeps it.
 * - default: the value used when nothing is stored.
 * - label: the field's name as a person reads it, used in messages.
 * - int: min, max.
 * - string: max (characters), format (text, textarea, url, email, key, hostname, color), schemes (for url).
 * - enum: choices (values), or options (value => label), either may be a callable that returns them.
 * - list: item (a field spec), max_items.
 * - map: item (a field spec), key_pattern (a regular expression for the keys), max_items.
 * - group: fields (name => field spec).
 * - storage: "user_meta:<key>" saves the value to the current user instead of the site.
 */
final class Validator {

	/**
	 * Cleans a submitted section.
	 *
	 * Keys that are not in the field list are dropped. Fields missing from the
	 * input keep their current value, so a partial update never erases anything.
	 *
	 * @param array<string, array<string, mixed>> $fields  Field specs.
	 * @param array<string, mixed>                $input   Submitted values.
	 * @param array<string, mixed>                $current Values stored now (defaults included).
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function clean( array $fields, array $input, array $current ): array {
		$values = array();
		$errors = array();

		foreach ( $fields as $name => $spec ) {
			$fallback = array_key_exists( $name, $current ) ? $current[ $name ] : self::default_of( $spec );

			if ( ! array_key_exists( $name, $input ) ) {
				$values[ $name ] = $fallback;
				continue;
			}

			$result = self::clean_value( $spec, $input[ $name ], (string) $name, $fallback );

			if ( null !== $result['error'] ) {
				$errors[ $result['path'] ] = $result['error'];
				$values[ $name ]           = $fallback;
				continue;
			}

			$values[ $name ] = $result['value'];
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Defaults for a field list.
	 *
	 * @param array<string, array<string, mixed>> $fields Field specs.
	 * @return array<string, mixed>
	 */
	public static function defaults( array $fields ): array {
		$defaults = array();

		foreach ( $fields as $name => $spec ) {
			$defaults[ $name ] = self::default_of( $spec );
		}

		return $defaults;
	}

	/**
	 * The default of one field.
	 *
	 * @param array<string, mixed> $spec Field spec.
	 * @return mixed
	 */
	public static function default_of( array $spec ) {
		if ( 'group' === ( $spec['type'] ?? '' ) ) {
			return self::defaults( (array) ( $spec['fields'] ?? array() ) );
		}

		if ( array_key_exists( 'default', $spec ) ) {
			return $spec['default'];
		}

		switch ( $spec['type'] ?? 'string' ) {
			case 'bool':
				return false;
			case 'int':
				return (int) ( $spec['min'] ?? 0 );
			case 'list':
			case 'map':
				return array();
			default:
				return '';
		}
	}

	/**
	 * Cleans one value.
	 *
	 * @param array<string, mixed> $spec     Field spec.
	 * @param mixed                $value    Submitted value.
	 * @param string               $path     Dotted path used in error keys.
	 * @param mixed                $fallback The current value.
	 * @return array{value: mixed, error: string|null, path: string}
	 */
	private static function clean_value( array $spec, $value, string $path, $fallback ): array {
		$label = (string) ( $spec['label'] ?? $path );
		$type  = (string) ( $spec['type'] ?? 'string' );
		$fail  = static function ( string $message, ?string $at = null ) use ( $path ): array {
			return array(
				'value' => null,
				'error' => $message,
				'path'  => null === $at ? $path : $at,
			);
		};
		$ok    = static function ( $clean ) use ( $path ): array {
			return array(
				'value' => $clean,
				'error' => null,
				'path'  => $path,
			);
		};

		switch ( $type ) {
			case 'bool':
				if ( is_array( $value ) ) {
					return $fail(
						/* translators: %s: the name of a setting. */
						sprintf( __( '%s must be on or off.', 'estasoft-news-distribution' ), $label )
					);
				}
				return $ok( filter_var( $value, FILTER_VALIDATE_BOOLEAN ) );

			case 'int':
				$min = (int) ( $spec['min'] ?? PHP_INT_MIN );
				$max = (int) ( $spec['max'] ?? PHP_INT_MAX );

				if ( is_bool( $value ) || is_array( $value ) || ! is_numeric( $value ) || (float) (int) $value !== (float) $value ) {
					return $fail( self::range_message( $label, $min, $max ) );
				}

				$number = (int) $value;

				if ( $number < $min || $number > $max ) {
					return $fail( self::range_message( $label, $min, $max ) );
				}

				return $ok( $number );

			case 'enum':
				$choices = self::choices_of( $spec );

				if ( ! is_scalar( $value ) || ! in_array( (string) $value, array_map( 'strval', $choices ), true ) ) {
					return $fail(
						/* translators: %s: the name of a setting. */
						sprintf( __( 'Choose one of the listed values for %s.', 'estasoft-news-distribution' ), $label )
					);
				}

				return $ok( (string) $value );

			case 'list':
				if ( ! is_array( $value ) ) {
					return $fail(
						/* translators: %s: the name of a setting. */
						sprintf( __( '%s must be a list.', 'estasoft-news-distribution' ), $label )
					);
				}

				$max_items = (int) ( $spec['max_items'] ?? 500 );
				$item      = (array) ( $spec['item'] ?? array( 'type' => 'string' ) );
				$clean     = array();

				foreach ( array_values( $value ) as $index => $entry ) {
					$result = self::clean_value( $item + array( 'label' => $label ), $entry, $path . '.' . $index, null );

					if ( null !== $result['error'] ) {
						return $fail( $result['error'], $result['path'] );
					}

					$clean[] = $result['value'];
				}

				$clean = array_values( array_unique( $clean, SORT_REGULAR ) );

				if ( count( $clean ) > $max_items ) {
					return $fail(
						sprintf(
							/* translators: 1: the name of a setting, 2: the largest allowed number of entries. */
							_n( '%1$s can have at most %2$d entry.', '%1$s can have at most %2$d entries.', $max_items, 'estasoft-news-distribution' ),
							$label,
							$max_items
						)
					);
				}

				return $ok( $clean );

			case 'map':
				if ( ! is_array( $value ) ) {
					return $fail(
						/* translators: %s: the name of a setting. */
						sprintf( __( '%s must be a list of pairs.', 'estasoft-news-distribution' ), $label )
					);
				}

				$pattern = (string) ( $spec['key_pattern'] ?? '/^[a-z0-9_-]{1,40}$/i' );
				$item    = (array) ( $spec['item'] ?? array( 'type' => 'string' ) );
				$clean   = array();

				foreach ( $value as $key => $entry ) {
					$key = (string) $key;

					if ( ! preg_match( $pattern, $key ) ) {
						continue;
					}

					$result = self::clean_value( $item + array( 'label' => $label ), $entry, $path . '.' . $key, null );

					if ( null !== $result['error'] ) {
						return $fail( $result['error'], $result['path'] );
					}

					$clean[ $key ] = $result['value'];
				}

				return $ok( $clean );

			case 'group':
				if ( ! is_array( $value ) ) {
					return $fail(
						/* translators: %s: the name of a setting. */
						sprintf( __( '%s must be a group of values.', 'estasoft-news-distribution' ), $label )
					);
				}

				$inner   = self::clean( (array) ( $spec['fields'] ?? array() ), $value, is_array( $fallback ) ? $fallback : array() );
				$problem = reset( $inner['errors'] );

				if ( false !== $problem ) {
					$key = (string) key( $inner['errors'] );
					return $fail( (string) $problem, $path . '.' . $key );
				}

				return $ok( $inner['values'] );

			case 'string':
			default:
				if ( ! is_scalar( $value ) ) {
					return $fail(
						/* translators: %s: the name of a setting. */
						sprintf( __( '%s must be text.', 'estasoft-news-distribution' ), $label )
					);
				}

				return self::clean_string( $spec, (string) $value, $label, $path );
		}
	}

	/**
	 * Cleans a text value.
	 *
	 * @param array<string, mixed> $spec  Field spec.
	 * @param string               $value Submitted text.
	 * @param string               $label Field name for messages.
	 * @param string               $path  Dotted path.
	 * @return array{value: mixed, error: string|null, path: string}
	 */
	private static function clean_string( array $spec, string $value, string $label, string $path ): array {
		$format = (string) ( $spec['format'] ?? 'text' );
		$max    = (int) ( $spec['max'] ?? 500 );

		switch ( $format ) {
			case 'textarea':
				$clean = sanitize_textarea_field( $value );
				break;
			case 'url':
				$clean = trim( $value );
				if ( '' !== $clean ) {
					$schemes = (array) ( $spec['schemes'] ?? array( 'https', 'http' ) );
					$parsed  = wp_parse_url( $clean );
					$scheme  = is_array( $parsed ) && isset( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : '';
					$host    = is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : '';

					if ( '' === $host || ! in_array( $scheme, $schemes, true ) ) {
						return array(
							'value' => null,
							'error' => sprintf(
								/* translators: 1: the name of a setting, 2: the allowed address start, such as https. */
								__( '%1$s must be a full web address that starts with %2$s://.', 'estasoft-news-distribution' ),
								$label,
								(string) reset( $schemes )
							),
							'path'  => $path,
						);
					}

					$clean = esc_url_raw( $clean, $schemes );
				}
				break;
			case 'email':
				$clean = trim( $value );
				if ( '' !== $clean && ! is_email( $clean ) ) {
					return array(
						'value' => null,
						'error' => sprintf(
							/* translators: %s: the name of a setting. */
							__( '%s must be an email address.', 'estasoft-news-distribution' ),
							$label
						),
						'path'  => $path,
					);
				}
				$clean = '' === $clean ? '' : sanitize_email( $clean );
				break;
			case 'key':
				$clean = sanitize_key( $value );
				break;
			case 'hostname':
				$clean = strtolower( trim( $value ) );
				if ( '' !== $clean && ! preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $clean ) ) {
					return array(
						'value' => null,
						'error' => sprintf(
							/* translators: %s: the name of a setting. */
							__( '%s must be a host name such as example.com.', 'estasoft-news-distribution' ),
							$label
						),
						'path'  => $path,
					);
				}
				break;
			case 'color':
				$clean = sanitize_hex_color( trim( $value ) );
				$clean = null === $clean ? '' : $clean;
				break;
			case 'text':
			default:
				$clean = sanitize_text_field( $value );
		}

		if ( mb_strlen( $clean ) > $max ) {
			return array(
				'value' => null,
				'error' => sprintf(
					/* translators: 1: the name of a setting, 2: the largest allowed number of characters. */
					_n( '%1$s can be at most %2$d character.', '%1$s can be at most %2$d characters.', $max, 'estasoft-news-distribution' ),
					$label,
					$max
				),
				'path'  => $path,
			);
		}

		if ( ! empty( $spec['required'] ) && '' === $clean ) {
			return array(
				'value' => null,
				'error' => sprintf(
					/* translators: %s: the name of a setting. */
					__( '%s cannot be empty.', 'estasoft-news-distribution' ),
					$label
				),
				'path'  => $path,
			);
		}

		return array(
			'value' => $clean,
			'error' => null,
			'path'  => $path,
		);
	}

	/**
	 * The allowed values of an enum field.
	 *
	 * A field lists them either as `choices` (values) or as `options` (value => label,
	 * or value => array with label and help). Either can be a callable, for lists that
	 * depend on the site, such as post types.
	 *
	 * @param array<string, mixed> $spec Field spec.
	 * @return string[]
	 */
	public static function choices_of( array $spec ): array {
		if ( isset( $spec['options'] ) ) {
			$options = is_callable( $spec['options'] ) ? (array) call_user_func( $spec['options'] ) : (array) $spec['options'];
			return array_map( 'strval', array_keys( $options ) );
		}

		$choices = $spec['choices'] ?? array();
		$choices = is_callable( $choices ) ? (array) call_user_func( $choices ) : (array) $choices;

		return array_map( 'strval', $choices );
	}

	/**
	 * The message for a number outside its range.
	 *
	 * @param string $label Field name.
	 * @param int    $min   Smallest allowed value.
	 * @param int    $max   Largest allowed value.
	 * @return string
	 */
	private static function range_message( string $label, int $min, int $max ): string {
		if ( PHP_INT_MIN === $min && PHP_INT_MAX === $max ) {
			/* translators: %s: the name of a setting. */
			return sprintf( __( '%s must be a whole number.', 'estasoft-news-distribution' ), $label );
		}

		return sprintf(
			/* translators: 1: the name of a setting, 2: smallest value, 3: largest value. */
			__( '%1$s must be a whole number from %2$d to %3$d.', 'estasoft-news-distribution' ),
			$label,
			$min,
			$max
		);
	}
}

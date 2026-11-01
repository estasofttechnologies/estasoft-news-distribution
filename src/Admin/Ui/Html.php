<?php
/**
 * Small helpers that build escaped HTML.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Every component builds its markup with these, so attributes are escaped in
 * one place. Pass text children already escaped (use esc_html()); the
 * `inner` argument of tag() is trusted HTML.
 */
final class Html {

	/**
	 * Attributes that hold addresses.
	 */
	private const URL_ATTRIBUTES = array( 'href', 'src', 'action', 'formaction' );

	/**
	 * Builds an attribute string with a leading space.
	 *
	 * `true` prints the bare attribute, `false` and `null` leave it out, and an
	 * array is joined with spaces (for class).
	 *
	 * @param array<string, mixed> $attrs Attribute name => value.
	 * @return string
	 */
	public static function attrs( array $attrs ): string {
		$out = '';

		foreach ( $attrs as $name => $value ) {
			$name = (string) preg_replace( '/[^a-zA-Z0-9_:-]/', '', (string) $name );

			if ( '' === $name || null === $value || false === $value ) {
				continue;
			}

			if ( true === $value ) {
				$out .= ' ' . $name;
				continue;
			}

			if ( is_array( $value ) ) {
				$value = trim( implode( ' ', array_filter( array_map( 'strval', $value ) ) ) );

				if ( '' === $value ) {
					continue;
				}
			}

			$value = (string) $value;
			$out  .= ' ' . $name . '="' . ( in_array( $name, self::URL_ATTRIBUTES, true ) ? esc_url( $value ) : esc_attr( $value ) ) . '"';
		}

		return $out;
	}

	/**
	 * An element with children.
	 *
	 * @param string               $name  Tag name.
	 * @param array<string, mixed> $attrs Attributes.
	 * @param string               $inner Trusted, already escaped HTML.
	 * @return string
	 */
	public static function tag( string $name, array $attrs = array(), string $inner = '' ): string {
		$name = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $name ) );

		return '<' . $name . self::attrs( $attrs ) . '>' . $inner . '</' . $name . '>';
	}

	/**
	 * An element with no children, such as input.
	 *
	 * @param string               $name  Tag name.
	 * @param array<string, mixed> $attrs Attributes.
	 * @return string
	 */
	public static function void( string $name, array $attrs = array() ): string {
		$name = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( $name ) );

		return '<' . $name . self::attrs( $attrs ) . '>';
	}

	/**
	 * Joins class names, skipping empty ones.
	 *
	 * @param string ...$classes Class names.
	 * @return string
	 */
	public static function classes( string ...$classes ): string {
		return trim( implode( ' ', array_filter( $classes ) ) );
	}

	/**
	 * Picks the width class for a control: the smallest preset that fits.
	 *
	 * @param int $pixels Wanted width in pixels, or 0 for the default.
	 * @return string Class name, or an empty string.
	 */
	public static function width_class( int $pixels ): string {
		if ( $pixels < 1 ) {
			return '';
		}

		foreach ( array( 90, 120, 200, 260, 280, 360, 480 ) as $preset ) {
			if ( $pixels <= $preset ) {
				return 'estasoft-nd-w-' . $preset;
			}
		}

		return 'estasoft-nd-w-full';
	}
}

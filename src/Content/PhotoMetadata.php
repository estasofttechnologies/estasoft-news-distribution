<?php
/**
 * What a photographer or an agency wrote into a photo file.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the credit, the description and the copyright notice from the file itself, without
 * calling another server.
 *
 * - IPTC (the news-industry block most agencies and photo editors write): credit line (2:110),
 *   by-line (2:080, the creator), caption or abstract (2:120) and copyright notice (2:116).
 * - EXIF (what the camera and most editors write): artist, image description and copyright.
 *
 * The credit is the first of the credit line, the creator and the camera's artist field that is
 * not empty. Text that is not valid UTF-8 is read as Latin-1, which is what older files hold.
 *
 * It reads only. Nothing is written to the file and nothing is stored here.
 */
final class PhotoMetadata {

	public const CREDIT  = 'credit';
	public const CAPTION = 'caption';
	public const NOTICE  = 'notice';

	/**
	 * Reads one file.
	 *
	 * @param string $file Path to the file.
	 * @return array{credit: string, caption: string, notice: string} Every key is there; an empty string means the file has nothing for it.
	 */
	public static function read( string $file ): array {
		$out = array(
			self::CREDIT  => '',
			self::CAPTION => '',
			self::NOTICE  => '',
		);

		if ( '' === $file || ! is_readable( $file ) || is_dir( $file ) ) {
			return $out;
		}

		$iptc = self::iptc( $file );
		$exif = self::exif( $file );

		foreach ( array( '2#110', '2#080' ) as $dataset ) {
			if ( '' === $out[ self::CREDIT ] ) {
				$out[ self::CREDIT ] = self::first( $iptc, $dataset );
			}
		}

		if ( '' === $out[ self::CREDIT ] ) {
			$out[ self::CREDIT ] = self::clean( $exif['Artist'] ?? '' );
		}

		$out[ self::CAPTION ] = self::first( $iptc, '2#120' );

		if ( '' === $out[ self::CAPTION ] ) {
			$out[ self::CAPTION ] = self::clean( $exif['ImageDescription'] ?? '' );
		}

		$out[ self::NOTICE ] = self::first( $iptc, '2#116' );

		if ( '' === $out[ self::NOTICE ] ) {
			$out[ self::NOTICE ] = self::clean( $exif['Copyright'] ?? '' );
		}

		return $out;
	}

	/**
	 * The IPTC datasets of a file.
	 *
	 * @param string $file Path to the file.
	 * @return array<string, array<int, string>> Dataset number to values; empty when the file has none.
	 */
	private static function iptc( string $file ): array {
		if ( ! function_exists( 'getimagesize' ) || ! function_exists( 'iptcparse' ) ) {
			return array();
		}

		$info = array();
		$size = @getimagesize( $file, $info ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file that is not an image is a normal case, not an error to report.

		if ( false === $size || empty( $info['APP13'] ) || ! is_string( $info['APP13'] ) ) {
			return array();
		}

		$parsed = @iptcparse( $info['APP13'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A damaged block is a normal case, not an error to report.

		if ( ! is_array( $parsed ) ) {
			return array();
		}

		$out = array();

		foreach ( $parsed as $dataset => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				$out[ (string) $dataset ][] = self::text( (string) $value );
			}
		}

		return $out;
	}

	/**
	 * The EXIF fields a file has, when the server can read them.
	 *
	 * @param string $file Path to the file.
	 * @return array<string, mixed>
	 */
	private static function exif( string $file ): array {
		if ( ! function_exists( 'exif_read_data' ) || ! function_exists( 'exif_imagetype' ) ) {
			return array();
		}

		$type = @exif_imagetype( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file that is not an image is a normal case, not an error to report.

		if ( ! in_array( $type, array( IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM ), true ) ) {
			return array();
		}

		$data = @exif_read_data( $file, 'IFD0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file without EXIF is a normal case, not an error to report.

		if ( ! is_array( $data ) ) {
			return array();
		}

		$out = array();

		foreach ( array( 'Artist', 'ImageDescription', 'Copyright' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$out[ $key ] = self::text( $data[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * The first non-empty value of a dataset.
	 *
	 * @param array<string, array<int, string>> $iptc    Parsed datasets.
	 * @param string                            $dataset Dataset number, such as "2#110".
	 * @return string
	 */
	private static function first( array $iptc, string $dataset ): string {
		foreach ( $iptc[ $dataset ] ?? array() as $value ) {
			$value = self::clean( $value );

			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Turns the bytes of a text field into UTF-8: text that is valid UTF-8 stays as it is, and anything else is read as Latin-1.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private static function text( string $value ): string {
		if ( '' === $value || ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $value, 'UTF-8' ) ) ) {
			return $value;
		}

		if ( function_exists( 'mb_convert_encoding' ) ) {
			return (string) mb_convert_encoding( $value, 'UTF-8', 'ISO-8859-1' );
		}

		// No way to convert: keep the plain characters rather than print broken bytes.
		return (string) preg_replace( '/[\x80-\xFF]+/', '', $value );
	}

	/**
	 * Makes a value one plain line: no control characters, no markup, single spaces.
	 *
	 * @param mixed $value What the file held.
	 * @return string
	 */
	private static function clean( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = str_replace( "\0", '', (string) $value );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = wp_strip_all_tags( $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );

		return mb_substr( $value, 0, 500 );
	}
}

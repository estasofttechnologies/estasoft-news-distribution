<?php
/**
 * Names and writing direction of the languages the plugin supports.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Screens print a language as a short tag (EN) and a name (English). Names are
 * written in English on purpose: the admin is translated, but a language is
 * recognised by its common name.
 */
final class Catalog {

	/**
	 * Language names by primary subtag.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			'en' => __( 'English', 'estasoft-news-distribution' ),
			'ku' => __( 'Kurmanji', 'estasoft-news-distribution' ),
			'es' => __( 'Spanish', 'estasoft-news-distribution' ),
			'fr' => __( 'French', 'estasoft-news-distribution' ),
			'nl' => __( 'Dutch', 'estasoft-news-distribution' ),
			'ar' => __( 'Arabic', 'estasoft-news-distribution' ),
			'he' => __( 'Hebrew', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The primary subtag of a language code, lowercase.
	 *
	 * @param string $code Language code such as "ku-Latn" or "en_US".
	 * @return string
	 */
	public static function primary( string $code ): string {
		$parts = preg_split( '/[-_]/', strtolower( $code ) );

		return is_array( $parts ) ? (string) $parts[0] : '';
	}

	/**
	 * The name of a language.
	 *
	 * @param string $code Language code.
	 * @return string The code in capitals when the language is not known.
	 */
	public static function name( string $code ): string {
		$names   = self::names();
		$primary = self::primary( $code );

		return $names[ $primary ] ?? strtoupper( $code );
	}

	/**
	 * The short tag printed next to a field.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function tag( string $code ): string {
		return strtoupper( self::primary( $code ) );
	}

	/**
	 * Text direction of a language.
	 *
	 * @param string $code Language code.
	 * @return string "rtl" or "ltr".
	 */
	public static function direction( string $code ): string {
		return in_array( self::primary( $code ), array( 'ar', 'he', 'fa', 'ur', 'ckb' ), true ) ? 'rtl' : 'ltr';
	}
}

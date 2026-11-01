<?php
/**
 * Small helpers for writing XML by hand.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SearchSitemap;

defined( 'ABSPATH' ) || exit;

/**
 * The sitemaps and feeds are written as text, so every value goes through here.
 */
final class Xml {

	/**
	 * Removes the characters XML does not allow.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean( string $text ): string {
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );

		return null === $clean ? '' : $clean;
	}

	/**
	 * Escapes text for an element or an attribute.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function text( string $text ): string {
		return htmlspecialchars( self::clean( $text ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Wraps HTML in a CDATA section, splitting any closing marker inside it.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function cdata( string $html ): string {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', self::clean( $html ) ) . ']]>';
	}

	/**
	 * Parses a document and returns the root, or the first problems libxml found.
	 *
	 * @param string $body Document text.
	 * @return array{xml: \SimpleXMLElement|null, errors: string[]}
	 */
	public static function parse( string $body ): array {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		$errors   = array();

		if ( false === $xml ) {
			foreach ( libxml_get_errors() as $error ) {
				$errors[] = trim( $error->message );
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'xml'    => false === $xml ? null : $xml,
			'errors' => array_slice( array_values( array_unique( $errors ) ), 0, 5 ),
		);
	}
}

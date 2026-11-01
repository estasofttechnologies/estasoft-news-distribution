<?php
/**
 * Small helpers for writing and reading RSS 2.0 feeds.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Support;

use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * Used by the partner feeds that are plain RSS 2.0 (Microsoft Start, Yahoo
 * News). Nothing here knows a platform's rules; it only writes well-formed XML
 * and reads it back so the plugin can check its own output.
 */
final class PartnerRss {

	public const NS_ATOM    = 'http://www.w3.org/2005/Atom';
	public const NS_MEDIA   = 'http://search.yahoo.com/mrss/';
	public const NS_DC      = 'http://purl.org/dc/elements/1.1/';
	public const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

	/**
	 * Escapes text for XML and removes the characters XML forbids.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function xml( string $text ): string {
		return htmlspecialchars( self::clean( $text ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Wraps HTML in a CDATA section. A closing marker inside the text is split so it cannot end the section early.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	public static function cdata( string $html ): string {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', self::clean( $html ) ) . ']]>';
	}

	/**
	 * A date in the form RSS 2.0 uses: RFC 822 with a four digit year and a numeric time zone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function rfc822( int $timestamp ): string {
		return gmdate( 'D, d M Y H:i:s', $timestamp ) . ' +0000';
	}

	/**
	 * A language code in the form the RSS language element takes ("en", "ku", "ar").
	 *
	 * @param string $code Language code or locale.
	 * @return string
	 */
	public static function language( string $code ): string {
		$code = strtolower( str_replace( '_', '-', trim( $code ) ) );

		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $code ) ? $code : 'en';
	}

	/**
	 * The article body as HTML, after WordPress has run blocks and shortcodes.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	public static function html( Article $article ): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
		$html = apply_filters( 'the_content', HtmlClean::drop_empty_paragraphs( $article->content() ) );

		return trim( (string) $html );
	}

	/**
	 * A plain-text summary of an article: the one a person chose for platforms, or else its first words, without tags.
	 *
	 * @param Article $article The article.
	 * @param int     $words   Words to keep when no summary was chosen.
	 * @return string
	 */
	public static function summary( Article $article, int $words = 40 ): string {
		if ( '' !== $article->summary() ) {
			return $article->summary();
		}

		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $article->content() ) ), $words, '…' );
	}

	/**
	 * Reads an RSS 2.0 document back: whether it is well-formed XML, and the fields of each item.
	 *
	 * @param string $body The document.
	 * @return array{valid: bool, problems: string[], items: array<int, array<string, string>>}
	 */
	public static function read( string $body ): array {
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		$problems = array();
		$items    = array();

		if ( false === $xml ) {
			foreach ( libxml_get_errors() as $error ) {
				$problems[] = trim( $error->message );
			}
		} elseif ( 'rss' !== $xml->getName() || ! isset( $xml->channel ) ) {
			$problems[] = __( 'The document is not an RSS 2.0 feed.', 'estasoft-news-distribution' );
		} else {
			foreach ( $xml->channel->item as $item ) {
				$media   = $item->children( self::NS_MEDIA );
				$atom    = $item->children( self::NS_ATOM );
				$items[] = array(
					'title'     => trim( (string) $item->title ),
					'link'      => trim( (string) $item->link ),
					'guid'      => trim( (string) $item->guid ),
					'pubDate'   => trim( (string) $item->{'pubDate'} ),
					'updated'   => trim( (string) $atom->updated ),
					'thumbnail' => isset( $media->thumbnail ) ? trim( (string) $media->thumbnail->attributes()->url ) : '',
				);
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'valid'    => false !== $xml && array() === $problems,
			'problems' => array_slice( array_values( array_unique( $problems ) ), 0, 10 ),
			'items'    => $items,
		);
	}

	/**
	 * Removes characters XML 1.0 does not allow.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function clean( string $text ): string {
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );

		return null === $clean ? '' : $clean;
	}
}

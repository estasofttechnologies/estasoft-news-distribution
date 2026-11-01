<?php
/**
 * Turns an article's stored content into the clean text partner feeds carry.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\AppleNews;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Support\HtmlClean;

defined( 'ABSPATH' ) || exit;

/**
 * Used by the Apple News and SmartNews feeds. It reads the stored content (it
 * never runs the_content, so other plugins cannot add ads, related stories or
 * share buttons on the way), removes what a partner feed should not carry, and
 * keeps a short list of safe tags. It also holds the small text helpers both
 * feeds need.
 */
final class FeedBody {

	/**
	 * Class names that mark a block a feed should not carry: ads, related
	 * stories and share buttons.
	 *
	 * @var string[]
	 */
	private const UNWANTED_CLASSES = array(
		'adsbygoogle',
		'advert',
		'advertisement',
		'ad-slot',
		'ad-container',
		'related-posts',
		'related-stories',
		'related-articles',
		'related-story',
		'jp-relatedposts',
		'yarpp-related',
		'sharedaddy',
		'addtoany_share_save_container',
		'a2a_kit',
		'share-buttons',
		'social-share',
		'wp-block-latest-posts',
		'wp-block-social-links',
	);

	/**
	 * Tags whose whole element, content included, is removed.
	 *
	 * @var string[]
	 */
	private const UNWANTED_TAGS = array( 'script', 'style', 'iframe', 'noscript', 'form', 'button', 'input', 'select', 'textarea', 'object', 'embed', 'ins' );

	/**
	 * Web addresses that open a share dialog, as host and path start.
	 *
	 * @var array<string, string[]>
	 */
	private const SHARE_LINKS = array(
		'facebook.com'     => array( '/sharer', '/share' ),
		'twitter.com'      => array( '/intent', '/share' ),
		'x.com'            => array( '/intent', '/share' ),
		'linkedin.com'     => array( '/sharearticle', '/sharing' ),
		'pinterest.com'    => array( '/pin/create' ),
		'api.whatsapp.com' => array( '/send' ),
		'wa.me'            => array( '/' ),
		't.me'             => array( '/share' ),
		'telegram.me'      => array( '/share' ),
		'reddit.com'       => array( '/submit' ),
	);

	/**
	 * Query parameters that only track where a visitor came from.
	 *
	 * @var string[]
	 */
	private const TRACKING = array( 'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'yclid' );

	/**
	 * The article text as clean HTML.
	 *
	 * @param string $content      The stored post content.
	 * @param bool   $strip_markup Whether to remove block notes and shortcodes.
	 * @return string
	 */
	public static function html( string $content, bool $strip_markup = true ): string {
		if ( $strip_markup ) {
			$content = (string) preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $content );
			$content = strip_shortcodes( $content );
		}

		$content = self::remove_unwanted( $content );
		$content = wp_kses( $content, self::allowed() );
		$content = HtmlClean::drop_empty_paragraphs( $content );

		return trim( wpautop( $content ) );
	}

	/**
	 * Whether the content has any text once it is cleaned.
	 *
	 * @param string $content The stored post content.
	 * @return bool
	 */
	public static function has_text( string $content ): bool {
		return '' !== trim( wp_strip_all_tags( self::html( $content ) ) );
	}

	/**
	 * A short plain-text summary of the content.
	 *
	 * @param string $content The stored post content.
	 * @param int    $words   Most words to keep.
	 * @return string
	 */
	public static function excerpt( string $content, int $words = 40 ): string {
		$text = wp_strip_all_tags( self::html( $content ) );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );

		return wp_trim_words( $text, $words, '…' );
	}

	/**
	 * The text under an item's headline: the summary a person chose for platforms, or else the first words.
	 *
	 * @param Article $article The article.
	 * @param int     $words   Words to keep when no summary was chosen.
	 * @return string
	 */
	public static function description( Article $article, int $words = 40 ): string {
		return '' !== $article->summary() ? $article->summary() : self::excerpt( $article->content(), $words );
	}

	/**
	 * Escapes text for XML and removes characters XML forbids.
	 *
	 * @param string $text Raw text, possibly with HTML entities.
	 * @return string
	 */
	public static function xml( string $text ): string {
		$clean = self::strip_invalid( wp_specialchars_decode( $text, ENT_QUOTES ) );

		return htmlspecialchars( $clean, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Wraps HTML in a CDATA section.
	 *
	 * @param string $html The HTML.
	 * @return string
	 */
	public static function cdata( string $html ): string {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', self::strip_invalid( $html ) ) . ']]>';
	}

	/**
	 * A date in the form RSS 2.0 asks for: RFC 822 with a time zone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function rfc822( int $timestamp ): string {
		return gmdate( 'D, d M Y H:i:s', $timestamp ) . ' +0000';
	}

	/**
	 * A language code in the form an RSS language element takes: en, ar, en-us.
	 *
	 * @param string $language Language code or locale.
	 * @return string
	 */
	public static function language_code( string $language ): string {
		$code = strtolower( str_replace( '_', '-', trim( $language ) ) );

		return 1 === preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $code ) ? $code : 'en';
	}

	/**
	 * An address with tracking parameters (utm_ and a few more) and the fragment removed.
	 *
	 * @param string $url An article address.
	 * @return string
	 */
	public static function canonical( string $url ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $query ) {
			return (string) strtok( $url, '#' );
		}

		$params = array();
		wp_parse_str( $query, $params );
		$remove = array();

		foreach ( array_keys( $params ) as $name ) {
			$name = (string) $name;

			if ( 0 === strpos( strtolower( $name ), 'utm_' ) || in_array( strtolower( $name ), self::TRACKING, true ) ) {
				$remove[] = $name;
			}
		}

		$clean = array() === $remove ? $url : remove_query_arg( $remove, $url );

		return (string) strtok( $clean, '#' );
	}

	/**
	 * Removes characters XML does not allow.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip_invalid( string $text ): string {
		$clean = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );

		return null === $clean ? '' : $clean;
	}

	/**
	 * The tags and attributes a feed body keeps.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed(): array {
		return array(
			'p'          => array(),
			'br'         => array(),
			'hr'         => array(),
			'h2'         => array(),
			'h3'         => array(),
			'h4'         => array(),
			'h5'         => array(),
			'h6'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'sup'        => array(),
			'sub'        => array(),
			'code'       => array(),
			'pre'        => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array( 'cite' => true ),
			'a'          => array(
				'href'  => true,
				'title' => true,
			),
			'img'        => array(
				'src'    => true,
				'alt'    => true,
				'width'  => true,
				'height' => true,
			),
			'figure'     => array(),
			'figcaption' => array(),
			'table'      => array(),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array(),
			'td'         => array(),
		);
	}

	/**
	 * Removes ad blocks, related-story blocks, share links and anything that runs code.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	private static function remove_unwanted( string $html ): string {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property names are camelCase.
		if ( '' === trim( $html ) || false === strpos( $html, '<' ) || ! class_exists( '\DOMDocument' ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadHTML(
			'<?xml encoding="UTF-8"><div id="estasoft-nd-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		$root     = $loaded ? $document->getElementById( 'estasoft-nd-root' ) : null;

		if ( ! $root instanceof \DOMElement ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			return $html;
		}

		$xpath = new \DOMXPath( $document );
		$drop  = array();

		foreach ( self::UNWANTED_TAGS as $tag ) {
			$drop[] = '//' . $tag;
		}

		foreach ( self::UNWANTED_CLASSES as $class ) {
			$drop[] = '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]';
		}

		foreach ( $drop as $query ) {
			$found = $xpath->query( $query );

			foreach ( false === $found ? array() : iterator_to_array( $found ) as $node ) {
				if ( $node->parentNode instanceof \DOMNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}

		$links = $xpath->query( '//a[@href]' );

		foreach ( false === $links ? array() : iterator_to_array( $links ) as $link ) {
			if ( $link instanceof \DOMElement && self::is_share_link( $link->getAttribute( 'href' ) ) && $link->parentNode instanceof \DOMNode ) {
				$link->parentNode->removeChild( $link );
			}
		}

		$out = '';

		foreach ( $root->childNodes as $child ) {
			$out .= $document->saveHTML( $child );
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return $out;
	}

	/**
	 * Whether an address opens a share dialog on a social site.
	 *
	 * @param string $href Link address.
	 * @return bool
	 */
	private static function is_share_link( string $href ): bool {
		$parts = wp_parse_url( trim( $href ) );
		$host  = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? strtolower( (string) $parts['path'] ) : '/';

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		if ( ! isset( self::SHARE_LINKS[ $host ] ) ) {
			return false;
		}

		foreach ( self::SHARE_LINKS[ $host ] as $start ) {
			if ( 0 === strpos( $path, $start ) ) {
				return true;
			}
		}

		return false;
	}
}

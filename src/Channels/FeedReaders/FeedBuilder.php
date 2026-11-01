<?php
/**
 * Builds and checks the plain RSS feed that open readers and Flipboard read.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\FeedReaders;

use Estasoft\NewsDistribution\Channels\ChannelImage;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SearchSitemapBuilder;
use Estasoft\NewsDistribution\Channels\SearchSitemap\Xml;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Support\HtmlClean;

defined( 'ABSPATH' ) || exit;

/**
 * RSS 2.0 with the full text of every article in `content:encoded`. The Flipboard
 * feed is the same document with the featured image's width and height and a
 * suggested topic added to each item. It works on the articles it is given. The
 * only things it asks for besides them are about the featured image, from
 * `Channels\ChannelImage`: the photo's credit, written as `media:credit` inside
 * the item's `media:content` (Media RSS has that element, and this feed already
 * writes `media:content`; nothing here knows whether a reader shows it), and,
 * when the `channel` option names a channel, the copy the image presets made for
 * it, with the copy's own width and height.
 */
final class FeedBuilder {

	public const ATOM    = 'http://www.w3.org/2005/Atom';
	public const CONTENT = 'http://purl.org/rss/1.0/modules/content/';
	public const DC      = 'http://purl.org/dc/elements/1.1/';
	public const MEDIA   = 'http://search.yahoo.com/mrss/';

	/**
	 * Builds the feed for one language.
	 *
	 * Options: `title`, `link`, `description`, `self` (the feed's own address), `hub`
	 * (a WebSub hub address, or empty), `flipboard` (bool), `topics` (category name
	 * => suggested topic), `limit` (most items) and `channel` (the id of the channel
	 * the feed is for, so its image copy is used; leave it out for the original image).
	 *
	 * @param Article[]            $articles Candidate articles, in any order.
	 * @param string               $language Language code of this feed.
	 * @param int                  $now      Unix time to treat as now.
	 * @param array<string, mixed> $options  Feed options.
	 * @return string
	 */
	public static function build( array $articles, string $language, int $now, array $options ): string {
		$items     = self::select( $articles, $language, $now, (int) ( $options['limit'] ?? 25 ) );
		$flipboard = ! empty( $options['flipboard'] );
		$topics    = (array) ( $options['topics'] ?? array() );
		$modified  = 0;

		foreach ( $items as $article ) {
			$modified = max( $modified, $article->modified_ts(), $article->published_ts() );
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:atom="' . self::ATOM . '" xmlns:content="' . self::CONTENT . '" xmlns:dc="' . self::DC . '" xmlns:media="' . self::MEDIA . '">' . "\n";
		$xml .= "\t<channel>\n";
		$xml .= "\t\t<title>" . Xml::text( (string) ( $options['title'] ?? '' ) ) . "</title>\n";
		$xml .= "\t\t<link>" . Xml::text( (string) ( $options['link'] ?? '' ) ) . "</link>\n";
		$xml .= "\t\t<description>" . Xml::text( (string) ( $options['description'] ?? '' ) ) . "</description>\n";
		$xml .= "\t\t<language>" . Xml::text( strtolower( str_replace( '_', '-', $language ) ) ) . "</language>\n";
		$xml .= "\t\t<lastBuildDate>" . self::date( $modified > 0 ? min( $modified, $now ) : $now ) . "</lastBuildDate>\n";

		if ( '' !== (string) ( $options['self'] ?? '' ) ) {
			$xml .= "\t\t" . '<atom:link href="' . Xml::text( (string) $options['self'] ) . '" rel="self" type="application/rss+xml" />' . "\n";
		}

		if ( '' !== (string) ( $options['hub'] ?? '' ) ) {
			$xml .= "\t\t" . '<atom:link href="' . Xml::text( (string) $options['hub'] ) . '" rel="hub" />' . "\n";
		}

		foreach ( $items as $article ) {
			$html = self::html( $article->content() );
			$xml .= "\t\t<item>\n";
			$xml .= "\t\t\t<title>" . Xml::text( $article->title() ) . "</title>\n";
			$xml .= "\t\t\t<link>" . Xml::text( $article->permalink() ) . "</link>\n";
			$xml .= "\t\t\t" . '<guid isPermaLink="true">' . Xml::text( $article->permalink() ) . "</guid>\n";
			$xml .= "\t\t\t<pubDate>" . self::date( $article->published_ts() ) . "</pubDate>\n";

			if ( '' !== trim( $article->author_name() ) ) {
				$xml .= "\t\t\t<dc:creator>" . Xml::text( $article->author_name() ) . "</dc:creator>\n";
			}

			$categories = array();

			if ( $flipboard ) {
				foreach ( $article->categories() as $name ) {
					if ( isset( $topics[ $name ] ) && '' !== (string) $topics[ $name ] ) {
						$categories[] = (string) $topics[ $name ];
						break;
					}
				}
			}

			foreach ( array_merge( $article->categories(), $article->tags() ) as $term ) {
				$categories[] = $term;
			}

			foreach ( array_values( array_unique( $categories ) ) as $term ) {
				$xml .= "\t\t\t<category>" . Xml::text( $term ) . "</category>\n";
			}

			$xml .= "\t\t\t<description>" . Xml::text( '' !== $article->summary() ? $article->summary() : self::summary( $html ) ) . "</description>\n";
			$xml .= "\t\t\t<content:encoded>" . Xml::cdata( $html ) . "</content:encoded>\n";

			if ( '' !== $article->thumbnail_url() ) {
				$picture = ChannelImage::of( $article, (string) ( $options['channel'] ?? '' ) );
				$credit  = ChannelImage::credit( $article )['credit'];
				$size    = '';

				if ( $flipboard && $picture['width'] > 0 && $picture['height'] > 0 ) {
					$size = ' width="' . (int) $picture['width'] . '" height="' . (int) $picture['height'] . '"';
				}

				$open = "\t\t\t" . '<media:content url="' . Xml::text( $picture['url'] ) . '" medium="image"' . $size;

				// Media RSS, which this feed already uses for the picture, names the credit as a child of the picture.
				$xml .= '' === $credit
					? $open . " />\n"
					: $open . ">\n\t\t\t\t<media:credit>" . Xml::text( $credit ) . "</media:credit>\n\t\t\t</media:content>\n";
			}

			$xml .= "\t\t</item>\n";
		}

		return $xml . "\t</channel>\n</rss>\n";
	}

	/**
	 * The articles that belong in the feed: public, in this language, with a title
	 * and an address, not dated in the future, one entry per address, newest first.
	 *
	 * @param Article[] $articles Candidate articles.
	 * @param string    $language Language code.
	 * @param int       $now      Unix time to treat as now.
	 * @param int       $limit    Most items.
	 * @return Article[]
	 */
	public static function select( array $articles, string $language, int $now, int $limit ): array {
		$with_title = array_filter(
			$articles,
			static function ( $article ): bool {
				return $article instanceof Article && '' !== trim( $article->title() );
			}
		);

		return array_slice( SearchSitemapBuilder::select( array_values( $with_title ), $language, $now, 0 ), 0, max( 1, $limit ) );
	}

	/**
	 * Turns the stored post content into HTML for readers: blocks, paragraphs and
	 * shortcodes, and nothing a theme or another plugin adds to the page.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	public static function html( string $content ): string {
		$html = function_exists( 'do_blocks' ) ? do_blocks( $content ) : $content;
		$html = wpautop( HtmlClean::drop_empty_paragraphs( $html ) );
		$html = shortcode_unautop( $html );

		return trim( do_shortcode( $html ) );
	}

	/**
	 * A plain-text summary for the `description` element: the first 55 words.
	 *
	 * @param string $html Article HTML.
	 * @return string
	 */
	public static function summary( string $html ): string {
		return wp_trim_words( wp_strip_all_tags( $html ), 55, '…' );
	}

	/**
	 * An RFC 822 date, as RSS wants it.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function date( int $timestamp ): string {
		return gmdate( 'D, d M Y H:i:s', $timestamp ) . ' +0000';
	}

	/**
	 * The plugin's own check of a built feed, in plain words.
	 *
	 * Returns the usual `valid`, `entries` and `problems`, and what the screen's
	 * discovery checks read: whether every item carries its full text (`full_text`),
	 * whether the feed names a WebSub hub (`hub`) and how many items carry the
	 * featured image's size (`sized_images`).
	 *
	 * @param string $body  The document.
	 * @param int    $limit Most items allowed, or 0 for no check.
	 * @return array{valid: bool, entries: int, problems: string[], full_text: bool, hub: bool, sized_images: int}
	 */
	public static function check( string $body, int $limit = 0 ): array {
		$parsed = Xml::parse( $body );
		$empty  = array(
			'valid'        => false,
			'entries'      => 0,
			'problems'     => array(),
			'full_text'    => false,
			'hub'          => false,
			'sized_images' => 0,
		);

		if ( null === $parsed['xml'] ) {
			return array_merge(
				$empty,
				array(
					'problems' => array_merge(
						array( __( 'The feed is not valid XML, so a reader could not read it.', 'estasoft-news-distribution' ) ),
						$parsed['errors']
					),
				)
			);
		}

		$xml = $parsed['xml'];

		if ( 'rss' !== $xml->getName() || ! isset( $xml->channel ) ) {
			return array_merge(
				$empty,
				array( 'problems' => array( __( 'The document is not an RSS feed: it has no rss element with a channel inside.', 'estasoft-news-distribution' ) ) )
			);
		}

		$problems = array();
		$channel  = $xml->channel;
		$counts   = array(
			'no_title' => 0,
			'no_link'  => 0,
			'no_guid'  => 0,
			'no_date'  => 0,
			'no_text'  => 0,
			'bad_date' => 0,
		);

		foreach ( array( 'title', 'link', 'description' ) as $required ) {
			if ( '' === trim( (string) $channel->{$required} ) ) {
				$problems[] = sprintf(
					/* translators: %s: the name of an RSS element such as title. */
					__( 'The feed has no %s.', 'estasoft-news-distribution' ),
					$required
				);
			}
		}

		$entries = 0;
		$sized   = 0;

		foreach ( $channel->item as $item ) {
			++$entries;

			if ( '' === trim( (string) $item->title ) ) {
				++$counts['no_title'];
			}

			if ( '' === trim( (string) $item->link ) ) {
				++$counts['no_link'];
			}

			if ( '' === trim( (string) $item->guid ) ) {
				++$counts['no_guid'];
			}

			$date = trim( (string) $item->pubDate ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- RSS element name.

			if ( '' === $date ) {
				++$counts['no_date'];
			} elseif ( false === strtotime( $date ) ) {
				++$counts['bad_date'];
			}

			if ( '' === trim( (string) $item->children( self::CONTENT )->encoded ) ) {
				++$counts['no_text'];
			}

			$media = $item->children( self::MEDIA )->content;

			if ( count( $media ) > 0 && '' !== (string) $media->attributes()->width && '' !== (string) $media->attributes()->height ) {
				++$sized;
			}
		}

		if ( $limit > 0 && $entries > $limit ) {
			$problems[] = sprintf(
				/* translators: 1: number of items in the feed, 2: largest number of items News Distribution puts in this feed. */
				_n( 'The feed has %1$s item. News Distribution keeps it to the latest %2$s.', 'The feed has %1$s items. News Distribution keeps it to the latest %2$s.', $entries, 'estasoft-news-distribution' ),
				number_format_i18n( $entries ),
				number_format_i18n( $limit )
			);
		}

		$messages = array(
			'no_title' => array(
				/* translators: %s: number of items. */
				_n( '%s item has no title.', '%s items have no title.', $counts['no_title'], 'estasoft-news-distribution' ),
				$counts['no_title'],
			),
			'no_link'  => array(
				/* translators: %s: number of items. */
				_n( '%s item has no link.', '%s items have no link.', $counts['no_link'], 'estasoft-news-distribution' ),
				$counts['no_link'],
			),
			'no_guid'  => array(
				/* translators: %s: number of items. */
				_n( '%s item has no unique id.', '%s items have no unique id.', $counts['no_guid'], 'estasoft-news-distribution' ),
				$counts['no_guid'],
			),
			'no_date'  => array(
				/* translators: %s: number of items. */
				_n( '%s item has no publication date.', '%s items have no publication date.', $counts['no_date'], 'estasoft-news-distribution' ),
				$counts['no_date'],
			),
			'bad_date' => array(
				/* translators: %s: number of items. */
				_n( '%s item has a publication date a reader may not understand.', '%s items have a publication date a reader may not understand.', $counts['bad_date'], 'estasoft-news-distribution' ),
				$counts['bad_date'],
			),
			'no_text'  => array(
				/* translators: %s: number of items. */
				_n( '%s item has no full text.', '%s items have no full text.', $counts['no_text'], 'estasoft-news-distribution' ),
				$counts['no_text'],
			),
		);

		foreach ( $messages as $key => $message ) {
			if ( $counts[ $key ] > 0 ) {
				$problems[] = sprintf( $message[0], number_format_i18n( (int) $message[1] ) );
			}
		}

		$hub = false;

		foreach ( $channel->children( self::ATOM )->link as $link ) {
			if ( 'hub' === (string) $link->attributes()->rel && '' !== (string) $link->attributes()->href ) {
				$hub = true;
			}
		}

		return array(
			'valid'        => array() === $problems,
			'entries'      => $entries,
			'problems'     => $problems,
			'full_text'    => 0 === $counts['no_text'],
			'hub'          => $hub,
			'sized_images' => $sized,
		);
	}
}

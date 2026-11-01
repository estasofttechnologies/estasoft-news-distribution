<?php
/**
 * News Distribution's own check of a SmartFormat feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SmartNews;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a built SmartNews feed back as XML and reports what it finds. This is
 * the plugin's check against the rules in rules/smartnews.json; it is not a
 * SmartNews result and says nothing about whether SmartNews lists the feed.
 */
final class FeedCheck {

	/**
	 * Namespaces the feed declares: prefix => address.
	 *
	 * @var array<string, string>
	 */
	public const NAMESPACES = array(
		'content' => 'http://purl.org/rss/1.0/modules/content/',
		'dc'      => 'http://purl.org/dc/elements/1.1/',
		'media'   => 'http://search.yahoo.com/mrss/',
		'snf'     => 'http://www.smartnews.be/snf',
	);

	/**
	 * Checks a feed.
	 *
	 * @param string $body      The feed document.
	 * @param int    $min_edge  Shortest long edge of a thumbnail before it is flagged, in pixels.
	 * @param int    $max_items Most live items a feed should carry.
	 * @param int    $max_bytes Largest feed size in bytes.
	 * @return array{valid: bool, entries: int, problems: string[], warnings: string[], live: int, deleted: int, bytes: int, has_logo: bool, namespaces: bool, utf8: bool, items: array<int, array<string, mixed>>}
	 */
	public static function run( string $body, int $min_edge, int $max_items, int $max_bytes ): array {
		$result   = array(
			'valid'      => false,
			'entries'    => 0,
			'problems'   => array(),
			'warnings'   => array(),
			'live'       => 0,
			'deleted'    => 0,
			'bytes'      => strlen( $body ),
			'has_logo'   => false,
			'namespaces' => false,
			'utf8'       => 1 === preg_match( '/^<\?xml[^>]*encoding=["\']UTF-8["\']/i', ltrim( $body ) ),
			'items'      => array(),
		);
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );

		if ( false === $xml ) {
			foreach ( libxml_get_errors() as $error ) {
				$result['problems'][] = trim( $error->message );
			}
		} elseif ( 'rss' !== $xml->getName() || '2.0' !== (string) $xml['version'] ) {
			$result['problems'][] = __( 'The feed is not RSS 2.0.', 'estasoft-news-distribution' );
		} else {
			self::inspect( $xml, $result, $min_edge );
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( false !== $xml && ! $result['utf8'] ) {
			$result['problems'][] = __( 'The feed does not say it is UTF-8.', 'estasoft-news-distribution' );
		}

		if ( $result['live'] > $max_items ) {
			$result['warnings'][] = sprintf(
				/* translators: %d: the number of items in the feed. */
				_n( 'The feed holds %d item, more than the about 100 News Distribution aims for.', 'The feed holds %d items, more than the about 100 News Distribution aims for.', $result['live'], 'estasoft-news-distribution' ),
				$result['live']
			);
		}

		if ( $result['bytes'] >= $max_bytes ) {
			$result['warnings'][] = __( 'The feed is 1 MB or larger.', 'estasoft-news-distribution' );
		}

		$result['problems'] = array_slice( array_values( array_unique( $result['problems'] ) ), 0, 10 );
		$result['valid']    = false !== $xml && array() === $result['problems'];
		$result['entries']  = $result['live'];

		return $result;
	}

	/**
	 * The live items that have a thumbnail under the given long edge.
	 *
	 * @param array<int, array<string, mixed>> $items Items from run().
	 * @return array<int, array<string, mixed>>
	 */
	public static function small( array $items ): array {
		return array_values(
			array_filter(
				$items,
				static function ( array $item ): bool {
					return ! empty( $item['small'] );
				}
			)
		);
	}

	/**
	 * Fills the result from a parsed feed.
	 *
	 * @param \SimpleXMLElement    $xml      The parsed feed.
	 * @param array<string, mixed> $result   The result so far, changed in place.
	 * @param int                  $min_edge Shortest long edge before a thumbnail is flagged.
	 * @return void
	 */
	private static function inspect( \SimpleXMLElement $xml, array &$result, int $min_edge ): void {
		$declared             = $xml->getDocNamespaces( true );
		$result['namespaces'] = true;

		foreach ( self::NAMESPACES as $prefix => $address ) {
			if ( ! isset( $declared[ $prefix ] ) || $declared[ $prefix ] !== $address ) {
				$result['namespaces'] = false;
				$result['problems'][] = sprintf(
					/* translators: %s: a namespace prefix such as snf. */
					__( 'The feed does not declare the %s namespace.', 'estasoft-news-distribution' ),
					$prefix
				);
			}
		}

		$channel = $xml->channel;

		foreach ( array( 'title', 'link', 'description', 'language' ) as $name ) {
			if ( ! isset( $channel->{$name} ) || '' === trim( (string) $channel->{$name} ) ) {
				$result['problems'][] = sprintf(
					/* translators: %s: the name of a feed element, such as title. */
					__( 'The feed needs a %s for the publication.', 'estasoft-news-distribution' ),
					$name
				);
			}
		}

		// The logo element is in the snf namespace; the address inside it has no namespace.
		$logo               = $channel->children( self::NAMESPACES['snf'] )->logo;
		$result['has_logo'] = isset( $logo[0] ) && '' !== trim( (string) $logo[0]->children( '' )->url );

		if ( ! $result['has_logo'] ) {
			$result['warnings'][] = __( 'The feed has no logo yet.', 'estasoft-news-distribution' );
		}

		foreach ( $channel->item as $item ) {
			$media  = $item->children( self::NAMESPACES['media'] );
			$status = isset( $media->status ) ? $media->status->attributes() : null;

			if ( null !== $status && 'deleted' === (string) $status['state'] ) {
				++$result['deleted'];
				continue;
			}

			++$result['live'];
			$result['items'][] = self::inspect_item( $item, $result, $min_edge );
		}
	}

	/**
	 * Reads one live item and adds its problems.
	 *
	 * @param \SimpleXMLElement    $item     The item.
	 * @param array<string, mixed> $result   The result so far, changed in place.
	 * @param int                  $min_edge Shortest long edge before a thumbnail is flagged.
	 * @return array<string, mixed>
	 */
	private static function inspect_item( \SimpleXMLElement $item, array &$result, int $min_edge ): array {
		$content   = (string) $item->children( self::NAMESPACES['content'] )->encoded;
		$author    = trim( (string) $item->children( self::NAMESPACES['dc'] )->creator );
		$thumb     = $item->children( self::NAMESPACES['media'] )->thumbnail;
		$thumb_at  = isset( $thumb ) ? $thumb->attributes() : null;
		$url       = null === $thumb_at ? '' : (string) $thumb_at['url'];
		$width     = null === $thumb_at ? 0 : (int) $thumb_at['width'];
		$height    = null === $thumb_at ? 0 : (int) $thumb_at['height'];
		$date      = trim( (string) $item->{'pubDate'} );
		$link      = trim( (string) $item->link );
		$date_ok   = 1 === preg_match( '/^[A-Z][a-z]{2}, \d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}(:\d{2})? (GMT|UT|[+-]\d{4}|[A-Z]{1,3})$/', $date );
		$long_edge = max( $width, $height );
		$row       = array(
			'title'        => trim( (string) $item->title ),
			'link'         => $link,
			'author'       => $author,
			'thumbnail'    => $url,
			'width'        => $width,
			'height'       => $height,
			'small'        => '' !== $url && $long_edge > 0 && $long_edge < $min_edge,
			'lead_in_body' => false !== stripos( $content, '<img' ),
			'has_text'     => '' !== trim( wp_strip_all_tags( $content ) ),
			'date_ok'      => $date_ok,
			'canonical_ok' => 1 !== preg_match( '/[?&](utm_[a-z0-9_]+|fbclid|gclid)=/i', $link ),
			'has_guid'     => '' !== trim( (string) $item->guid ),
		);

		if ( '' === $row['title'] || '' === $link || ! $row['has_guid'] || '' === $date || ! $row['has_text'] ) {
			$result['problems'][] = __( 'An item is missing its title, link, guid, date or text.', 'estasoft-news-distribution' );
		}

		if ( '' !== $date && ! $date_ok ) {
			$result['problems'][] = __( 'A date is not in RFC 822 form with a time zone.', 'estasoft-news-distribution' );
		}

		if ( '' === $author || '' === $url ) {
			$result['problems'][] = __( 'An item is missing its author or thumbnail.', 'estasoft-news-distribution' );
		}

		return $row;
	}
}

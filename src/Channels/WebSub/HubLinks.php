<?php
/**
 * The hub links a feed carries.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\WebSub;

defined( 'ABSPATH' ) || exit;

/**
 * A feed names its WebSub hub with a link whose rel is "hub", and its own
 * address with a link whose rel is "self", both in the document and in the
 * HTTP headers. A feed builder asks here, and gets nothing until the owner has
 * entered a hub and turned WebSub on.
 *
 * The filter `estasoft_nd_feed_hubs` is the contract other channels use:
 *
 *     $hubs = apply_filters( 'estasoft_nd_feed_hubs', array(), $feed_slug );
 *
 * It returns the hub addresses to advertise in the feed served at that slug.
 * The WebSub module answers it for the feed WebSub pings (`/feed/main/`).
 */
final class HubLinks {

	/**
	 * The hubs a feed should name.
	 *
	 * @param string $slug Feed slug, such as "main".
	 * @return string[] Hub addresses, no duplicates. Empty when the feed has none.
	 */
	public static function hubs( string $slug ): array {
		/**
		 * Filters the WebSub hubs a feed names.
		 *
		 * @param string[] $hubs Hub addresses. Empty by default.
		 * @param string   $slug Feed slug.
		 */
		$hubs = apply_filters( 'estasoft_nd_feed_hubs', array(), $slug );
		$out  = array();

		foreach ( is_array( $hubs ) ? $hubs : array() as $hub ) {
			$hub = is_string( $hub ) ? trim( $hub ) : '';

			if ( '' !== $hub && ! in_array( $hub, $out, true ) ) {
				$out[] = $hub;
			}
		}

		return $out;
	}

	/**
	 * Atom link elements for the hubs, to place inside an RSS channel or an Atom feed.
	 * The feed writes its own self link; this adds only the hub links.
	 *
	 * @param string $slug   Feed slug.
	 * @param string $indent Leading whitespace of each line.
	 * @return string XML lines, or an empty string when there is no hub.
	 */
	public static function atom_links( string $slug, string $indent = "\t\t" ): string {
		$xml = '';

		foreach ( self::hubs( $slug ) as $hub ) {
			$xml .= $indent . '<atom:link rel="hub" href="' . htmlspecialchars( $hub, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '"/>' . "\n";
		}

		return $xml;
	}

	/**
	 * The value of the HTTP Link header for a feed.
	 *
	 * @param string $slug     Feed slug.
	 * @param string $self_url The feed's own exact address.
	 * @return string Empty when there is no hub.
	 */
	public static function link_header( string $slug, string $self_url ): string {
		$parts = array();

		foreach ( self::hubs( $slug ) as $hub ) {
			$parts[] = '<' . esc_url_raw( $hub ) . '>; rel="hub"';
		}

		if ( array() === $parts ) {
			return '';
		}

		$parts[] = '<' . esc_url_raw( $self_url ) . '>; rel="self"';

		return implode( ', ', $parts );
	}
}

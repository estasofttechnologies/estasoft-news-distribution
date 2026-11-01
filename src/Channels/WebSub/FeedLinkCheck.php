<?php
/**
 * Checks that the feeds WebSub pings name the hub and themselves.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\WebSub;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches each language feed from the site itself (a same-site request, only
 * when a person presses "Check again") and looks for a hub link and a self link
 * in the document and in the HTTP Link header. The result is kept in an option
 * so the screen can show it without fetching again.
 */
final class FeedLinkCheck {

	public const OPTION = 'estasoft_nd_websub_check';

	/**
	 * Fetches the feeds and keeps the result.
	 *
	 * @param WebSubChannel $channel The WebSub channel.
	 * @return array{time: int, languages: array<string, array<string, mixed>>}
	 */
	public static function run( WebSubChannel $channel ): array {
		$hubs      = $channel->hubs();
		$languages = array();

		foreach ( $channel->carried_languages() as $language ) {
			$topics = $channel->topics( $language );

			if ( array() === $topics ) {
				continue;
			}

			$languages[ $language ] = self::fetch( (string) $topics[0], $hubs );
		}

		$result = array(
			'time'      => Clock::now(),
			'languages' => $languages,
		);

		update_option( self::OPTION, $result, false );

		return $result;
	}

	/**
	 * The result of the last check.
	 *
	 * @return array{time: int, languages: array<string, array<string, mixed>>}
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );

		return array(
			'time'      => is_array( $stored ) ? (int) ( $stored['time'] ?? 0 ) : 0,
			'languages' => is_array( $stored ) && isset( $stored['languages'] ) && is_array( $stored['languages'] ) ? $stored['languages'] : array(),
		);
	}

	/**
	 * Fetches one feed and inspects it.
	 *
	 * @param string   $url  The feed's own address.
	 * @param string[] $hubs The hubs the owner entered.
	 * @return array{code: int, body_hub: bool, body_self: bool, header_hub: bool, header_self: bool, error: string}
	 */
	private static function fetch( string $url, array $hubs ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/rss+xml, application/atom+xml, application/xml' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'        => 0,
				'body_hub'    => false,
				'body_self'   => false,
				'header_hub'  => false,
				'header_self' => false,
				'error'       => $response->get_error_message(),
			);
		}

		$link = wp_remote_retrieve_header( $response, 'link' );
		$link = is_array( $link ) ? implode( ', ', array_map( 'strval', $link ) ) : (string) $link;

		return array_merge(
			array( 'code' => (int) wp_remote_retrieve_response_code( $response ) ),
			self::inspect( (string) wp_remote_retrieve_body( $response ), $link, $url, $hubs ),
			array( 'error' => '' )
		);
	}

	/**
	 * Looks for the hub and self links.
	 *
	 * @param string   $body   The feed document.
	 * @param string   $header The value of the HTTP Link header, or an empty string.
	 * @param string   $own    The address the feed must give as its self link.
	 * @param string[] $hubs   The hubs the owner entered. When empty, any hub link counts.
	 * @return array{body_hub: bool, body_self: bool, header_hub: bool, header_self: bool}
	 */
	public static function inspect( string $body, string $header, string $own, array $hubs ): array {
		$found = array(
			'body_hub'    => false,
			'body_self'   => false,
			'header_hub'  => false,
			'header_self' => false,
		);

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );

		if ( false !== $xml ) {
			$links = $xml->xpath( '//*[local-name()="link"][@rel]' );

			foreach ( is_array( $links ) ? $links : array() as $link ) {
				$found = self::note( $found, 'body', strtolower( (string) $link['rel'] ), (string) $link['href'], $own, $hubs );
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( '' !== $header && preg_match_all( '/<([^>]*)>\s*;[^,]*?\brel="?([a-z]+)"?/i', $header, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$found = self::note( $found, 'header', strtolower( $match[2] ), $match[1], $own, $hubs );
			}
		}

		return $found;
	}

	/**
	 * Records one link.
	 *
	 * @param array<string, bool> $found  What was found so far.
	 * @param string              $where  "body" or "header".
	 * @param string              $rel    The link's rel.
	 * @param string              $href   The link's address.
	 * @param string              $own    The address the feed must give as its self link.
	 * @param string[]            $hubs   The hubs the owner entered.
	 * @return array<string, bool>
	 */
	private static function note( array $found, string $where, string $rel, string $href, string $own, array $hubs ): array {
		if ( 'hub' === $rel && ( array() === $hubs || in_array( $href, $hubs, true ) ) ) {
			$found[ $where . '_hub' ] = true;
		}

		if ( 'self' === $rel && $href === $own ) {
			$found[ $where . '_self' ] = true;
		}

		return $found;
	}
}

<?php
/**
 * What a sitemap channel's page shows for one language.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SearchSitemap;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Frontend\FeedController;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the document the crawler gets, from the same cache entry the feed
 * controller uses (`feed:<slug>:<language>`), so the page and the crawler never
 * disagree, and runs the channel's own check on it.
 */
final class SitemapReport {

	/**
	 * The report for one language.
	 *
	 * @param Plugin           $plugin   The running plugin.
	 * @param ChannelInterface $channel  The channel.
	 * @param string           $language Language code.
	 * @return array{language: string, url: string, valid: bool, entries: int, problems: string[], modified: int, body: string}
	 */
	public static function for_language( Plugin $plugin, ChannelInterface $channel, string $language ): array {
		$feeds = new FeedController( $plugin );
		$entry = $plugin->cache()->remember(
			'feed:' . $channel->feed_slug() . ':' . $language,
			$plugin->settings()->cache_ttl(),
			static function () use ( $feeds, $channel, $language ): array {
				return $feeds->build_document( $channel, $language );
			}
		);
		$check = $channel->check_output( (string) ( $entry['body'] ?? '' ), $language );

		return array(
			'language' => $language,
			'url'      => add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . $channel->feed_slug() . '/' ) ),
			'valid'    => (bool) $check['valid'],
			'entries'  => (int) $check['entries'],
			'problems' => array_values( (array) $check['problems'] ),
			'modified' => (int) ( $entry['modified'] ?? 0 ),
			'body'     => (string) ( $entry['body'] ?? '' ),
		);
	}

	/**
	 * The report for every language the channel carries.
	 *
	 * @param Plugin           $plugin  The running plugin.
	 * @param ChannelInterface $channel The channel.
	 * @return array<string, array{language: string, url: string, valid: bool, entries: int, problems: string[], modified: int, body: string}> Language code => report.
	 */
	public static function for_channel( Plugin $plugin, ChannelInterface $channel ): array {
		$reports = array();

		foreach ( $plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( $channel->accepts_language( $language ) ) {
				$reports[ $language ] = self::for_language( $plugin, $channel, $language );
			}
		}

		return $reports;
	}
}

<?php
/**
 * A partner feed document, from the cache the public feed uses.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\AppleNews;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Frontend\FeedController;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The screens and the SmartNews readiness report check the feed as a platform
 * receives it. This reads the same cached document the public address serves,
 * and builds it when the cache is empty, so a check never differs from what a
 * platform gets and never makes the site build the feed twice.
 */
final class FeedDocument {

	/**
	 * The document for a channel and a language.
	 *
	 * @param Plugin           $plugin   The running plugin.
	 * @param ChannelInterface $channel  A pulled channel.
	 * @param string           $language Language code.
	 * @return string The feed, or an empty string when there is none.
	 */
	public static function body( Plugin $plugin, ChannelInterface $channel, string $language ): string {
		return (string) ( self::entry( $plugin, $channel, $language )['body'] ?? '' );
	}

	/**
	 * The cached entry for a channel and a language: the document, when it last
	 * changed and how many articles were candidates for it.
	 *
	 * @param Plugin           $plugin   The running plugin.
	 * @param ChannelInterface $channel  A pulled channel.
	 * @param string           $language Language code.
	 * @return array<string, mixed>
	 */
	public static function entry( Plugin $plugin, ChannelInterface $channel, string $language ): array {
		$entry = $plugin->cache()->remember(
			'feed:' . $channel->feed_slug() . ':' . $language,
			$plugin->settings()->cache_ttl(),
			static function () use ( $plugin, $channel, $language ): array {
				return ( new FeedController( $plugin ) )->build_document( $channel, $language );
			}
		);

		return is_array( $entry ) ? $entry : array();
	}
}

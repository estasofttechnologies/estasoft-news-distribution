<?php
/**
 * Names the WebSub hub in the open-reader feed's HTTP headers.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\WebSub\HubLinks;
use Estasoft\NewsDistribution\Channels\WebSub\WebSubChannel;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A reader that follows a feed through WebSub learns the hub from a link whose
 * rel is "hub", in the feed document or in the HTTP Link header. This module:
 *
 * - answers the filter `estasoft_nd_feed_hubs` for the open-reader feed (`main`)
 *   with the hubs the owner entered, once the WebSub channel is turned on, so any
 *   feed builder can ask for them;
 * - adds the Link header (hub and self) to that feed's response through the
 *   filter `estasoft_nd_feed_headers`.
 *
 * It does nothing, and sends nothing, until a hub is entered and WebSub is on.
 */
final class WebSubModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'estasoft_nd_feed_hubs', array( $this, 'hubs' ), 10, 2 );
		add_filter( 'estasoft_nd_feed_headers', array( $this, 'headers' ), 10, 3 );
	}

	/**
	 * Adds the owner's hubs to the list for the open-reader feed.
	 *
	 * @param mixed  $hubs Hubs found so far.
	 * @param string $slug Feed slug.
	 * @return string[]
	 */
	public function hubs( $hubs, $slug ): array {
		$hubs = is_array( $hubs ) ? array_values( array_map( 'strval', $hubs ) ) : array();

		if ( WebSubChannel::TOPIC_SLUG !== $slug ) {
			return $hubs;
		}

		$channel = $this->channel();

		if ( null === $channel || ! $channel->is_enabled() ) {
			return $hubs;
		}

		return array_values( array_unique( array_merge( $hubs, $channel->hubs() ) ) );
	}

	/**
	 * Adds the Link header to a response of the open-reader feed.
	 *
	 * @param mixed  $headers  Response headers so far.
	 * @param mixed  $served   The channel that serves the feed.
	 * @param string $language Language code of the response.
	 * @return array<string, string>
	 */
	public function headers( $headers, $served, $language ): array {
		$headers = is_array( $headers ) ? $headers : array();

		if ( ! $served instanceof ChannelInterface || ! $served->serves( WebSubChannel::TOPIC_SLUG ) ) {
			return $headers;
		}

		$channel = $this->channel();

		if ( null === $channel || ! $channel->is_enabled() ) {
			return $headers;
		}

		$topics = $channel->topics( (string) $language );
		$value  = HubLinks::link_header( WebSubChannel::TOPIC_SLUG, (string) reset( $topics ) );

		if ( '' !== $value ) {
			$headers['Link'] = $value;
		}

		return $headers;
	}

	/**
	 * The WebSub channel, when it is registered.
	 *
	 * @return WebSubChannel|null
	 */
	private function channel(): ?WebSubChannel {
		$channel = $this->plugin->registry()->get( 'websub' );

		return $channel instanceof WebSubChannel ? $channel : null;
	}
}
